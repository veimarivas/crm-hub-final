<?php

namespace App\Http\Controllers;

use App\Jobs\SendBroadcastMessageJob;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\SavedSegment;
use App\Models\Tag;
use App\Models\User;
use App\Services\WhatsApp\MessagingCost;
use App\Services\WhatsApp\ServiceWindow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BroadcastController extends Controller
{
    public function index(Request $request): Response
    {
        $accountId = $request->user()->account_id;
        $isAdmin = $request->user()->hasRoleAtLeast(User::ROLE_ADMIN);

        $broadcasts = Broadcast::forAccount($accountId)
            // El agente ve el historial de SUS envios. Los del equipo no le
            // aportan y muestran a quien le escribio otro.
            ->when(! $isAdmin, fn ($q) => $q->where('user_id', $request->user()->id))
            ->with('user:id,name')
            ->latest()
            ->limit(50)
            ->get(['id', 'name', 'message', 'status', 'total_recipients', 'sent_count', 'failed_count', 'sent_at', 'created_at', 'user_id']);

        return Inertia::render('Broadcasts/Index', [
            'broadcasts' => $broadcasts,
            'isAdmin' => $isAdmin,
        ]);
    }

    public function create(Request $request): Response
    {
        $accountId = $request->user()->account_id;
        $isAdmin = $request->user()->hasRoleAtLeast(User::ROLE_ADMIN);

        $segments = SavedSegment::forAccount($accountId)
            ->where(fn ($q) => $q->where('user_id', $request->user()->id)->orWhere('is_shared', true))
            ->orderBy('name')
            ->get(['id', 'name', 'filters']);

        return Inertia::render('Broadcasts/Create', [
            'segments' => $segments,
            // Filtrar por etiqueta es la forma natural de armar un envío
            // ("los Nuevos", "los del MBA"), asi que las etiquetas viajan con
            // la pantalla en vez de esconderse detras de una lista guardada.
            //
            // El contador de leads por etiqueta respeta el alcance de quien
            // mira: si el agente ve "Nuevo (120)" y al elegirla le aparecen 9,
            // el numero mintio.
            'tags' => Tag::forAccount($accountId)
                ->withCount(['leads' => fn ($q) => $isAdmin ? $q : $q->where('responsible_user_id', $request->user()->id)])
                ->orderBy('name')
                ->get(['id', 'name', 'color']),
            // Elegir responsable es del admin; el agente solo se tiene a si
            // mismo, asi que el desplegable no tendria nada que filtrar.
            'members' => $isAdmin
                ? User::where('account_id', $accountId)->orderBy('name')->get(['id', 'name'])
                : [],
            'pricing' => app(MessagingCost::class)->rates(),
            'isAdmin' => $isAdmin,
        ]);
    }

    /**
     * Lista de candidatos con su ventana de servicio.
     *
     * Antes esto devolvia solo un numero y tres nombres de muestra: se enviaba
     * a ciegas. Ahora vuelve la lista entera para que se vea a quien se le va
     * a escribir, y sobre todo a quien NO conviene.
     */
    public function preview(Request $request)
    {
        $accountId = $request->user()->account_id;
        $filters = $request->input('filters', []);

        $recipients = $this->recipientPhones($request, $filters);

        $inWindow = array_values(array_filter($recipients, fn ($r) => $r->window['is_open']));
        $outOfWindow = array_values(array_filter($recipients, fn ($r) => ! $r->window['is_open']));

        return response()->json([
            'count' => count($recipients),
            'in_window' => count($inWindow),
            'out_of_window' => count($outOfWindow),
            // Tope defensivo: un envio de 5.000 no tiene por que reventar el
            // navegador. Se avisa en la UI con `truncated`.
            'recipients' => array_map(fn ($r) => [
                'lead_id' => $r->lead_id,
                'contact_id' => $r->contact_id,
                'name' => $r->name,
                'phone' => $r->phone,
                'title' => $r->title,
                'tags' => $r->tags,
                'window' => $r->window,
            ], array_slice($recipients, 0, 500)),
            'truncated' => count($recipients) > 500,
            // Lo que costaria escribirle a los de afuera CON plantilla
            // aprobada, que es la unica forma de que les llegue.
            'cost_out_of_window' => app(MessagingCost::class)->estimate(count($outOfWindow)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'message' => 'required|string|max:4000',
            'filters' => 'nullable|array',
            'lead_ids' => 'nullable|array|max:5000',
            'lead_ids.*' => 'uuid',
            'image' => 'nullable|file|image|mimes:jpeg,png,webp,gif|max:10240',
        ]);

        $accountId = $request->user()->account_id;
        $recipients = $this->recipientPhones($request, $validated['filters'] ?? []);

        // La seleccion de la pantalla manda: los filtros arman la lista, pero
        // quien recibe lo decide la persona destildando a mano. Se intersecta
        // contra la lista recien calculada, asi que un id de otra cuenta o de
        // un lead que dejo de matchear no entra igual.
        if (! empty($validated['lead_ids'])) {
            $elegidos = array_flip($validated['lead_ids']);
            $recipients = array_values(array_filter($recipients, fn ($r) => isset($elegidos[$r->lead_id])));
        }

        // Como excepción de validación y no `abort(422)`: un abort pelado no
        // es una respuesta de validación, así que Inertia lo descarta y el
        // botón parece no hacer nada. Así el motivo aterriza en la pantalla.
        if (empty($recipients)) {
            throw ValidationException::withMessages([
                'lead_ids' => 'Ningún destinatario válido: revisá que los seleccionados sigan teniendo teléfono y coincidan con los filtros.',
            ]);
        }

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
        $this->authorizeBroadcast($request, $broadcast);
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
        $this->authorizeBroadcast($request, $broadcast);
        abort_if(! $broadcast->media_path || ! Storage::disk('local')->exists($broadcast->media_path), 404);

        return Storage::disk('local')->response($broadcast->media_path);
    }

    /**
     * De la cuenta, y del agente si no es admin.
     *
     * El listado ya oculta los envíos ajenos, pero ocultar no es cortar: con
     * el id a mano se abría igual, y el detalle muestra a quién le escribió
     * otro asesor.
     */
    private function authorizeBroadcast(Request $request, Broadcast $broadcast): void
    {
        abort_if($broadcast->account_id !== $request->user()->account_id, 403);

        abort_if(
            ! $request->user()->hasRoleAtLeast(User::ROLE_ADMIN) && $broadcast->user_id !== $request->user()->id,
            403,
        );
    }

    /**
     * Destinatarios candidatos con su ventana de servicio, segun los filtros
     * (los mismos que `LeadController@index`) — solo leads con telefono.
     *
     * @return array<int, object>
     */
    private function recipientPhones(Request $request, array $filters): array
    {
        $user = $request->user();
        $isAdmin = $user->hasRoleAtLeast(User::ROLE_ADMIN);

        // `tags` (varias, OR) reemplaza a `tag` (una). Se sigue aceptando la
        // vieja porque las listas guardadas la traen adentro.
        $tagIds = array_filter((array) ($filters['tags'] ?? array_filter([$filters['tag'] ?? null])));

        $query = Lead::forAccount($user->account_id)
            // El corte del agente: solo su cartera. Va acá y no en la pantalla
            // porque este método alimenta TANTO la vista previa como el envío
            // — filtrar solo en el front dejaría el `store` abierto a mandar
            // un lead_id ajeno.
            ->when(! $isAdmin, fn ($q) => $q->where('responsible_user_id', $user->id))
            ->whereHas('contact', fn ($q) => $q->whereNotNull('phone_normalized'))
            ->with(['contact:id,name,phone_normalized', 'tags:id,name,color'])
            ->when($filters['responsible'] ?? null, fn ($q, $v) => $isAdmin
                ? ($v === 'none' ? $q->whereNull('responsible_user_id') : $q->where('responsible_user_id', $v))
                : $q)
            ->when($filters['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            ->when($tagIds, fn ($q, $ids) => $q->whereHas('tags', fn ($tq) => $tq->whereIn('tags.id', $ids)))
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

        $leads = $query->get(['id', 'contact_id', 'responsible_user_id', 'title', 'source_ref', 'created_at']);

        // Ventana de servicio de todos de una: dice a quien se le puede
        // escribir gratis y a quien no. Dos queries para la lista entera.
        $windows = app(ServiceWindow::class)->forLeads($leads);

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
                'title' => $lead->title,
                'tags' => $lead->tags->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'color' => $t->color])->all(),
                'window' => $windows[$lead->id] ?? app(ServiceWindow::class)->build(null, null),
            ];
        }

        // Los que estan por vencer primero: si hay que apurar un envio, es a
        // ellos. Los que ya estan fuera de ventana, al final.
        usort($out, function ($a, $b) {
            if ($a->window['is_open'] !== $b->window['is_open']) {
                return $a->window['is_open'] ? -1 : 1;
            }

            return $a->window['remaining_seconds'] <=> $b->window['remaining_seconds'];
        });

        return $out;
    }
}
