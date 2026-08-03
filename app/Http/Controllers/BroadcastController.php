<?php

namespace App\Http\Controllers;

use App\Jobs\SendBroadcastMessageJob;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\SavedSegment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BroadcastController extends Controller
{
    public function index(Request $request): Response
    {
        $accountId = $request->user()->account_id;

        $broadcasts = Broadcast::forAccount($accountId)
            ->with('user:id,name')
            ->latest()
            ->limit(50)
            ->get(['id', 'name', 'message', 'status', 'total_recipients', 'sent_count', 'failed_count', 'sent_at', 'created_at', 'user_id']);

        return Inertia::render('Broadcasts/Index', [
            'broadcasts' => $broadcasts,
        ]);
    }

    public function create(Request $request): Response
    {
        $accountId = $request->user()->account_id;

        $segments = SavedSegment::forAccount($accountId)
            ->where(fn ($q) => $q->where('user_id', $request->user()->id)->orWhere('is_shared', true))
            ->orderBy('name')
            ->get(['id', 'name', 'filters']);

        return Inertia::render('Broadcasts/Create', [
            'segments' => $segments,
        ]);
    }

    /** Preview: cuenta cuantos destinatarios matchean el segmento. */
    public function preview(Request $request)
    {
        $accountId = $request->user()->account_id;
        $filters = $request->input('filters', []);

        $ids = $this->recipientPhones($accountId, $filters);

        return response()->json([
            'count' => count($ids),
            'sample' => array_slice(array_map(fn ($r) => [
                'name' => $r->name,
                'phone' => $r->phone,
            ], $ids), 0, 5),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'message' => 'required|string|max:4000',
            'filters' => 'nullable|array',
            'image' => 'nullable|file|image|mimes:jpeg,png,webp,gif|max:10240',
        ]);

        $accountId = $request->user()->account_id;
        $recipients = $this->recipientPhones($accountId, $validated['filters'] ?? []);

        abort_if(empty($recipients), 422, 'Sin destinatarios validos con estos filtros.');

        // Guarda la imagen del broadcast (se reutiliza para cada destinatario).
        $mediaPath = null;
        if ($request->hasFile('image')) {
            $mediaPath = $request->file('image')->store('broadcasts');
        }

        DB::transaction(function () use ($request, $validated, $accountId, $recipients, &$broadcast, $mediaPath) {
            $broadcast = Broadcast::create([
                'account_id' => $accountId,
                'user_id' => $request->user()->id,
                'name' => $validated['name'],
                'message' => $validated['message'],
                'media_path' => $mediaPath,
                'filters' => $validated['filters'] ?? [],
                'status' => 'running',
                'total_recipients' => count($recipients),
                'sent_at' => now(),
            ]);

            $now = now();
            $rows = array_map(fn ($r) => [
                'id' => (string) Str::uuid(),
                'broadcast_id' => $broadcast->id,
                'lead_id' => $r->lead_id,
                'contact_id' => $r->contact_id,
                'phone_normalized' => $r->phone,
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ], $recipients);

            foreach (array_chunk($rows, 500) as $chunk) {
                BroadcastRecipient::insert($chunk);
            }

            // Dispatchear un job por recipient para paralelismo con throttle del queue worker
            BroadcastRecipient::where('broadcast_id', $broadcast->id)
                ->pluck('id')
                ->each(fn ($id) => SendBroadcastMessageJob::dispatch($id));
        });

        return redirect()->route('broadcasts.show', $broadcast)->with('success', 'Broadcast en curso — se procesa en segundo plano.');
    }

    public function show(Request $request, Broadcast $broadcast): Response
    {
        abort_if($broadcast->account_id !== $request->user()->account_id, 403);
        $broadcast->refresh();

        return Inertia::render('Broadcasts/Show', [
            'broadcast' => $broadcast->load('user:id,name'),
            'recipients' => $broadcast->recipients()
                ->with(['lead:id,title', 'contact:id,name'])
                ->orderByRaw("CASE status WHEN 'failed' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END")
                ->limit(200)
                ->get(),
        ]);
    }

    /** Sirve la imagen adjunta al broadcast (autorizada por pertenencia a la cuenta). */
    public function media(Request $request, Broadcast $broadcast)
    {
        abort_if($broadcast->account_id !== $request->user()->account_id, 403);
        abort_if(! $broadcast->media_path || ! Storage::disk('local')->exists($broadcast->media_path), 404);

        return Storage::disk('local')->response($broadcast->media_path);
    }

    /**
     * Devuelve destinatarios [{lead_id, contact_id, name, phone}] segun los filtros
     * (mismos que LeadController@index) — solo leads con contact.phone_normalized.
     */
    private function recipientPhones(string $accountId, array $filters): array
    {
        $query = Lead::forAccount($accountId)
            ->whereHas('contact', fn ($q) => $q->whereNotNull('phone_normalized'))
            ->with(['contact:id,name,phone_normalized'])
            ->when($filters['responsible'] ?? null, fn ($q, $v) => $v === 'none' ? $q->whereNull('responsible_user_id') : $q->where('responsible_user_id', $v))
            ->when($filters['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            ->when($filters['tag'] ?? null, fn ($q, $v) => $q->whereHas('tags', fn ($tq) => $tq->where('tags.id', $v)))
            ->when(! empty($filters['no_task']), fn ($q) => $q->whereDoesntHave('tasks', fn ($t) => $t->whereNull('completed_at')))
            ->when(! empty($filters['q']), function ($q) use ($filters) {
                $t = $filters['q'];
                $q->where(function ($qq) use ($t) {
                    $qq->where('title', 'like', "%{$t}%")
                        ->orWhereHas('contact', fn ($cq) => $cq->where('name', 'like', "%{$t}%"));
                });
            });

        // Solo leads abiertos por default
        if (! isset($filters['include_closed']) || ! $filters['include_closed']) {
            $query->where('status', 'open');
        }

        $leads = $query->get(['id', 'contact_id', 'responsible_user_id']);

        // Dedup por phone_normalized (mismo contacto en 2 leads = un solo msg)
        $seen = [];
        $out = [];
        foreach ($leads as $lead) {
            $phone = $lead->contact?->phone_normalized;
            if (! $phone || isset($seen[$phone])) {
                continue;
            }
            $seen[$phone] = true;
            $out[] = (object) [
                'lead_id' => $lead->id,
                'contact_id' => $lead->contact_id,
                'name' => $lead->contact->name,
                'phone' => $phone,
            ];
        }

        return $out;
    }
}
