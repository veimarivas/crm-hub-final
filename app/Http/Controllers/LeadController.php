<?php

namespace App\Http\Controllers;

use App\Jobs\SyncLeadAssignmentToWacrmJob;
use App\Models\AppNotification;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\SavedSegment;
use App\Models\Tag;
use App\Models\User;
use App\Services\Wacrm\Client;
use App\Services\WhatsApp\ServiceWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadController extends Controller
{
    public function index(Request $request): Response
    {
        $accountId = $request->user()->account_id;

        $pipelines = Pipeline::forAccount($accountId)->with('stages')->orderBy('created_at')->get();
        $selected = $pipelines->firstWhere('id', $request->query('pipeline'))
            ?? $pipelines->firstWhere('is_default', true)
            ?? $pipelines->first();

        $user = $request->user();
        $isAdmin = $user->hasRoleAtLeast(User::ROLE_ADMIN);

        // Filtros (persisten via query string).
        //
        // El filtro por responsable es del admin: elige a cualquier asesor o
        // los ve a todos juntos. Para un agente no aplica —ya solo ve los
        // suyos— y dejarlo pasar significaba que un ?responsible=<otro> le
        // devolviera una lista vacia, que se lee como "no hay leads" y no
        // como "eso no es tuyo".
        $filters = [
            'responsible' => $isAdmin ? $request->query('responsible') : null,
            'tag' => $request->query('tag'),
            'source' => $request->query('source'),
            'stage_id' => $request->query('stage_id'),
            'no_task' => (bool) $request->query('no_task'),
            'q' => trim((string) $request->query('q', '')),
        ];

        $query = $selected?->leads()
            ->with(['contact:id,name,phone,phone_normalized', 'responsible:id,name', 'tags'])
            ->withCount(['tasks as pending_tasks_count' => fn ($q) => $q->whereNull('completed_at')])
            ->when(! $isAdmin, fn ($q) => $q->where('responsible_user_id', $user->id))
            ->when($filters['responsible'], fn ($q, $v) => $v === 'none' ? $q->whereNull('responsible_user_id') : $q->where('responsible_user_id', $v))
            ->when($filters['source'], fn ($q, $v) => $q->where('source', $v))
            ->when($filters['stage_id'], fn ($q, $v) => $q->where('stage_id', $v))
            ->when($filters['no_task'], fn ($q) => $q->whereDoesntHave('tasks', fn ($t) => $t->whereNull('completed_at')))
            ->when($filters['tag'], fn ($q, $tagId) => $q->whereHas('tags', fn ($tq) => $tq->where('tags.id', $tagId)))
            ->when($filters['q'] !== '', function ($q) use ($filters) {
                $t = $filters['q'];
                $q->where(function ($qq) use ($t) {
                    $qq->where('title', 'like', "%{$t}%")
                        ->orWhereHas('contact', fn ($cq) => $cq->where('name', 'like', "%{$t}%")->orWhere('phone', 'like', "%{$t}%")->orWhere('phone_normalized', 'like', "%{$t}%"));
                });
            })
            ->orderByDesc('created_at');

        $leads = $query ? $query->get() : collect();

        // Enriquecimiento SLA: ultimo mensaje entrante y minutos de espera
        $enrichedLeads = $this->enrichLeadsWithSla($leads);

        // Tags disponibles del pipeline para el filtro
        $tags = Tag::forAccount($accountId)->orderBy('name')->get(['id', 'name', 'color']);

        // Segments accesibles: propios o compartidos del equipo
        $segments = SavedSegment::forAccount($accountId)
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('is_shared', true))
            ->orderBy('name')
            ->get(['id', 'name', 'filters', 'user_id', 'is_shared']);

        return Inertia::render('Leads/Index', [
            'pipelines' => $pipelines->map(fn ($p) => ['id' => $p->id, 'name' => $p->name]),
            'pipeline' => $selected ? ['id' => $selected->id, 'name' => $selected->name, 'stages' => $selected->stages] : null,
            'leads' => $enrichedLeads,
            'members' => User::where('account_id', $accountId)->get(['id', 'name']),
            'contacts' => Contact::forAccount($accountId)->orderBy('name')->limit(500)->get(['id', 'name', 'phone']),
            'allTags' => $tags,
            'filters' => $filters,
            'segments' => $segments,
            'currency' => $request->user()->account->default_currency,
            'slaMinutes' => 30,
            'isAdmin' => $isAdmin,
        ]);
    }

    /**
     * Agrega last_message_at, last_message_direction y waiting_minutes a cada lead.
     * Usa una sola query batched para evitar N+1.
     */
    /**
     * Bulk actions sobre multiples leads: move (etapa), assign (responsable),
     * tag (agregar/quitar), delete. Scopeado por rol.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $accountId = $request->user()->account_id;
        $user = $request->user();
        $isAdmin = $user->hasRoleAtLeast(User::ROLE_ADMIN);

        $validated = $request->validate([
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'uuid',
            'action' => 'required|in:move,assign,tag,delete',
            'stage_id' => 'nullable|uuid|required_if:action,move',
            'responsible_user_id' => 'nullable|uuid|required_if:action,assign',
            'tag_id' => 'nullable|uuid|required_if:action,tag',
            'tag_mode' => 'nullable|in:add,remove',
        ]);

        $leads = Lead::forAccount($accountId)
            ->whereIn('id', $validated['ids'])
            ->when(! $isAdmin, fn ($q) => $q->where('responsible_user_id', $user->id))
            ->get();

        if ($leads->isEmpty()) {
            return back()->with('success', '0 leads afectados.');
        }

        $count = 0;
        switch ($validated['action']) {
            case 'move':
                $stage = PipelineStage::whereHas('pipeline', fn ($q) => $q->where('account_id', $accountId))
                    ->findOrFail($validated['stage_id']);
                foreach ($leads as $lead) {
                    if ($lead->pipeline_id === $stage->pipeline_id && $lead->stage_id !== $stage->id) {
                        $lead->moveToStage($stage, $user);
                        $count++;
                    }
                }
                break;
            case 'assign':
                // Agent no puede reasignar
                abort_unless($isAdmin, 403, 'Solo admin puede reasignar en bulk.');
                $newResp = $validated['responsible_user_id'];
                abort_unless(User::where('account_id', $accountId)->where('id', $newResp)->exists(), 422);
                foreach ($leads as $lead) {
                    if ($lead->responsible_user_id !== $newResp) {
                        $lead->update(['responsible_user_id' => $newResp]);
                        AppNotification::notify(
                            $accountId, $newResp, 'lead_assigned',
                            "Lead reasignado: {$lead->title}", null, $lead->id, $user->id,
                        );
                        // Mismo espejo que en el update individual: la
                        // conversacion del wacrm sigue al nuevo responsable.
                        SyncLeadAssignmentToWacrmJob::dispatch($lead->id);
                        $count++;
                    }
                }
                break;
            case 'tag':
                $tag = Tag::forAccount($accountId)->findOrFail($validated['tag_id']);
                $mode = $validated['tag_mode'] ?? 'add';
                foreach ($leads as $lead) {
                    if ($mode === 'add') {
                        $lead->tags()->syncWithoutDetaching([$tag->id]);
                    } else {
                        $lead->tags()->detach($tag->id);
                    }
                    $count++;
                }
                break;
            case 'delete':
                abort_unless($isAdmin, 403, 'Solo admin puede borrar en bulk.');
                foreach ($leads as $lead) {
                    $lead->delete();
                    $count++;
                }
                break;
        }

        return back()->with('success', "{$count} leads afectados.");
    }

    /**
     * Streamea CSV de leads respetando los mismos filtros que index().
     * No pagina — streamea todo (memoria acotada por chunks).
     */
    public function export(Request $request): StreamedResponse
    {
        $accountId = $request->user()->account_id;
        $user = $request->user();
        $isAdmin = $user->hasRoleAtLeast(User::ROLE_ADMIN);

        $pipelines = Pipeline::forAccount($accountId)->get();
        $selected = $pipelines->firstWhere('id', $request->query('pipeline'))
            ?? $pipelines->firstWhere('is_default', true)
            ?? $pipelines->first();

        $filters = [
            'responsible' => $request->query('responsible'),
            'tag' => $request->query('tag'),
            'source' => $request->query('source'),
            'stage_id' => $request->query('stage_id'),
            'no_task' => (bool) $request->query('no_task'),
            'q' => trim((string) $request->query('q', '')),
        ];

        $filename = 'leads_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($selected, $isAdmin, $user, $filters) {
            $out = fopen('php://output', 'w');
            // BOM UTF-8 para que Excel abra bien acentos y emojis
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'ID', 'Titulo', 'Contacto', 'Telefono', 'Email',
                'Empresa', 'Etapa', 'Estado', 'Valor', 'Moneda',
                'Fuente', 'UTM Source', 'UTM Campaign',
                'Responsable', 'Etiquetas',
                'Creado', 'Cerrado',
            ]);

            $selected?->leads()
                ->with(['contact:id,name,phone,email', 'company:id,name', 'stage:id,name,stage_type', 'responsible:id,name', 'tags:id,name'])
                ->when(! $isAdmin, fn ($q) => $q->where('responsible_user_id', $user->id))
                ->when($filters['responsible'], fn ($q, $v) => $v === 'none' ? $q->whereNull('responsible_user_id') : $q->where('responsible_user_id', $v))
                ->when($filters['source'], fn ($q, $v) => $q->where('source', $v))
                ->when($filters['stage_id'], fn ($q, $v) => $q->where('stage_id', $v))
                ->when($filters['no_task'], fn ($q) => $q->whereDoesntHave('tasks', fn ($t) => $t->whereNull('completed_at')))
                ->when($filters['tag'], fn ($q, $tagId) => $q->whereHas('tags', fn ($tq) => $tq->where('tags.id', $tagId)))
                ->when($filters['q'] !== '', function ($q) use ($filters) {
                    $t = $filters['q'];
                    $q->where(function ($qq) use ($t) {
                        $qq->where('title', 'like', "%{$t}%")
                            ->orWhereHas('contact', fn ($cq) => $cq->where('name', 'like', "%{$t}%")->orWhere('phone', 'like', "%{$t}%"));
                    });
                })
                ->orderByDesc('created_at')
                ->chunk(500, function ($chunk) use ($out) {
                    foreach ($chunk as $lead) {
                        fputcsv($out, [
                            $lead->id,
                            $lead->title,
                            $lead->contact?->name ?? '',
                            $lead->contact?->phone ?? '',
                            $lead->contact?->email ?? '',
                            $lead->company?->name ?? '',
                            $lead->stage?->name ?? '',
                            $lead->status,
                            $lead->value,
                            $lead->currency,
                            $lead->source,
                            $lead->utm_source ?? '',
                            $lead->utm_campaign ?? '',
                            $lead->responsible?->name ?? '',
                            $lead->tags->pluck('name')->join(', '),
                            $lead->created_at?->toDateTimeString(),
                            $lead->closed_at?->toDateTimeString() ?? '',
                        ]);
                    }
                });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function enrichLeadsWithSla($leads)
    {
        $ids = $leads->pluck('id')->all();
        if (empty($ids)) {
            return $leads;
        }

        $lastMessages = LeadEvent::whereIn('lead_id', $ids)
            ->whereIn('event_type', ['message_in', 'message_out'])
            ->select('lead_id', 'event_type', 'created_at')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('lead_id')
            ->map(fn ($g) => $g->first());

        $now = now();

        // Ventana de servicio de WhatsApp (24 h / 72 h si vino de un anuncio):
        // el listado necesita ver de un vistazo a quién todavía se le puede
        // escribir gratis.
        $windows = app(ServiceWindow::class)->forLeads($leads);

        return $leads->map(function ($lead) use ($lastMessages, $now, $windows) {
            $last = $lastMessages->get($lead->id);
            $waiting = 0;
            if ($last && $last->event_type === 'message_in') {
                $waiting = (int) $now->diffInMinutes($last->created_at, true);
            }
            $lead->setAttribute('last_message_at', $last?->created_at);
            $lead->setAttribute('last_message_direction', $last ? ($last->event_type === 'message_in' ? 'in' : 'out') : null);
            $lead->setAttribute('waiting_minutes', $waiting);
            $lead->setAttribute('service_window', $windows[$lead->id] ?? null);

            return $lead;
        })
            // Ultima actividad primero: un mensaje que entra sube la tarjeta a
            // la cima de su columna, y un lead recien creado (sin mensajes aun)
            // arranca arriba por su created_at. Ordenar por created_at a secas
            // dejaba enterrada la conversacion que acababa de moverse, que es
            // justo la que hay que atender.
            ->sortByDesc(fn ($lead) => ($lead->last_message_at ?? $lead->created_at)?->getTimestamp() ?? 0)
            ->values();
    }

    public function store(Request $request): RedirectResponse
    {
        $accountId = $request->user()->account_id;

        $validated = $request->validate([
            'pipeline_id' => 'required|uuid',
            'stage_id' => 'nullable|uuid',
            'title' => 'required|string|max:255',
            'value' => 'nullable|numeric|min:0|max:9999999999.99',
            'contact_id' => 'nullable|uuid',
            'responsible_user_id' => 'nullable|uuid',
        ]);

        $pipeline = Pipeline::forAccount($accountId)->findOrFail($validated['pipeline_id']);

        $stage = $validated['stage_id'] ?? null
            ? $pipeline->stages()->findOrFail($validated['stage_id'])
            : $pipeline->stages()->where('stage_type', 'open')->orderBy('position')->firstOrFail();

        if ($validated['contact_id'] ?? null) {
            abort_unless(Contact::forAccount($accountId)->where('id', $validated['contact_id'])->exists(), 422);
        }

        $lead = Lead::create([
            'account_id' => $accountId,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'title' => $validated['title'],
            'value' => $validated['value'] ?? 0,
            'currency' => $request->user()->account->default_currency,
            'contact_id' => $validated['contact_id'] ?? null,
            'responsible_user_id' => $validated['responsible_user_id'] ?? $request->user()->id,
        ]);

        $lead->recordEvent('created', $request->user(), ['source' => 'manual']);

        AppNotification::notify(
            $accountId,
            $lead->responsible_user_id,
            'lead_assigned',
            'Lead asignado',
            "Te asignaron el lead «{$lead->title}»",
            $lead->id,
            $request->user()->id,
        );

        return redirect()->route('leads.show', $lead)->with('success', 'Lead creado.');
    }

    public function show(Request $request, Lead $lead): Response
    {
        $this->authorizeLead($request, $lead);

        $lead->load([
            'contact', 'company', 'responsible:id,name',
            'stage:id,name,color,stage_type',
            'pipeline:id,name',
            'tags:id,name,color',
        ]);

        $integration = $request->user()->account->integration;

        return Inertia::render('Leads/Show', [
            'lead' => $lead,
            'stages' => $lead->pipeline->stages()->get(),
            'events' => $lead->events()->with('actor:id,name')->limit(60)->get(),
            'tasks' => $lead->tasks()->with('assignee:id,name')->orderByRaw('completed_at IS NULL DESC')->orderBy('due_at')->get(),
            'notes' => $lead->notes()->with('author:id,name')->limit(30)->get(),
            'members' => User::where('account_id', $lead->account_id)->get(['id', 'name']),
            'contacts' => Contact::forAccount($lead->account_id)->orderBy('name')->limit(500)->get(['id', 'name', 'phone']),
            'companies' => Company::forAccount($lead->account_id)->orderBy('name')->limit(500)->get(['id', 'name']),
            'allTags' => Tag::forAccount($lead->account_id)->orderBy('name')->get(['id', 'name', 'color']),
            'customFields' => CustomField::forAccount($lead->account_id)
                ->where('entity', 'lead')->orderBy('position')->get(),
            'customValues' => $lead->customFieldValues(),
            'whatsappEnabled' => (bool) ($integration?->is_active && $lead->contact?->phone),
            'serviceWindow' => app(ServiceWindow::class)->forLead($lead),
        ]);
    }

    public function update(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorizeLead($request, $lead);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'value' => 'nullable|numeric|min:0|max:9999999999.99',
            'contact_id' => 'nullable|uuid',
            'company_id' => 'nullable|uuid',
            'responsible_user_id' => 'nullable|uuid|exists:users,id',
            'custom_values' => 'nullable|array',
            'custom_values.*' => 'nullable|string|max:1000',
        ]);

        // Solo admin/owner puede cambiar el responsable. Los agents no pueden
        // reasignarse un lead ni pasarlo a otro miembro del equipo.
        if (! $request->user()->hasRoleAtLeast(User::ROLE_ADMIN)) {
            unset($validated['responsible_user_id']);
        }

        $customValues = $validated['custom_values'] ?? null;
        unset($validated['custom_values']);

        foreach (['contact_id' => Contact::class, 'company_id' => Company::class] as $field => $model) {
            if ($validated[$field] ?? null) {
                abort_unless($model::forAccount($lead->account_id)->where('id', $validated[$field])->exists(), 422);
            }
        }

        $oldValue = (string) $lead->value;
        $oldResponsible = $lead->responsible_user_id;
        $lead->update([...$validated, 'value' => $validated['value'] ?? 0]);

        if ($customValues !== null) {
            $lead->syncCustomFieldValues($customValues, 'lead');
        }

        if ($lead->responsible_user_id !== $oldResponsible) {
            if ($lead->responsible_user_id) {
                AppNotification::notify(
                    $lead->account_id,
                    $lead->responsible_user_id,
                    'lead_assigned',
                    'Lead asignado',
                    "Te asignaron el lead «{$lead->title}»",
                    $lead->id,
                    $request->user()->id,
                );
            }

            // Espeja la asignación en el wacrm: la conversación pasa al
            // Inbox del agente responsable. En cola — el guardado de la
            // ficha no espera al HTTP.
            SyncLeadAssignmentToWacrmJob::dispatch($lead->id);
        }

        if ($oldValue !== (string) $lead->value) {
            $lead->recordEvent('value_changed', $request->user(), ['from' => $oldValue, 'to' => (string) $lead->value]);
        }

        return back()->with('success', 'Lead actualizado.');
    }

    /** Envía un archivo por WhatsApp desde el chat del lead (a través del wacrm). */
    public function sendMedia(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeLead($request, $lead);

        $request->validate([
            'file' => 'required|file|max:16384', // 16MB
            'caption' => 'nullable|string|max:1024',
        ]);

        $integration = $request->user()->account->integration;
        if (! $integration?->is_active) {
            return response()->json(['message' => 'Integración con wacrm no activa.'], 422);
        }
        if (! $lead->contact?->phone) {
            return response()->json(['message' => 'El lead no tiene teléfono.'], 422);
        }

        $file = $request->file('file');

        try {
            Client::for($integration)->sendMedia(
                phone: $lead->contact->phone_normalized ?? $lead->contact->phone,
                fileBase64: base64_encode($file->get()),
                mimeType: $file->getMimeType() ?? 'application/octet-stream',
                filename: $file->getClientOriginalName(),
                caption: $request->input('caption'),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Proxy de media: descarga el archivo del wacrm y lo re-sirve desde Komo.
     * Evita problemas de CORS y sesión cross-domain al reproducir audios/ver
     * imágenes en el chat del lead. El navegador ve un path del propio Komo.
     * Se cachea 1h en el navegador.
     */
    public function media(Request $request, string $mediaId): \Symfony\Component\HttpFoundation\Response
    {
        $integration = $request->user()->account->integration;
        abort_unless($integration?->is_active, 422);

        try {
            [$contentType, $bytes] = Client::for($integration)->downloadMedia($mediaId);
        } catch (\Throwable $e) {
            abort(502, $e->getMessage());
        }

        return response($bytes, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** Devuelve las plantillas rápidas del equipo (delegadas al wacrm). */
    public function quickReplies(Request $request): JsonResponse
    {
        $integration = $request->user()->account->integration;
        if (! $integration?->is_active) {
            return response()->json([]);
        }

        try {
            return response()->json(Client::for($integration)->quickReplies());
        } catch (\Throwable $e) {
            return response()->json([]);
        }
    }

    /**
     * Toggle IA/Humano del lead. Solo lo puede cambiar el admin (si el lead
     * no tiene responsable) o el responsable asignado. Sincroniza al wacrm.
     */
    public function setAiMode(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorizeLead($request, $lead);

        $user = $request->user();
        // Si hay responsable asignado, solo el responsable o admin pueden cambiar el modo.
        if ($lead->responsible_user_id && $lead->responsible_user_id !== $user->id
            && ! $user->hasRoleAtLeast(User::ROLE_ADMIN)) {
            abort(403, 'Solo el responsable o el admin pueden cambiar el modo IA de este lead.');
        }

        $validated = $request->validate(['ai_enabled' => 'required|boolean']);
        $lead->update(['ai_enabled' => $validated['ai_enabled']]);

        // Espeja al wacrm.
        if ($lead->wacrm_conversation_id) {
            $integration = Integration::forAccount($lead->account_id)->first();
            if ($integration && $integration->wacrm_url && $integration->wacrm_api_key) {
                try {
                    Client::for($integration)->setAiMode($lead->wacrm_conversation_id, $validated['ai_enabled']);
                } catch (\Throwable $e) {
                    Log::warning('Sync ai-mode → wacrm falló', [
                        'lead_id' => $lead->id, 'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return back();
    }

    /** Mover de etapa (Kanban o ficha). El estado se deriva de la etapa. */
    public function move(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorizeLead($request, $lead);

        $validated = $request->validate(['stage_id' => 'required|uuid']);
        $stage = PipelineStage::findOrFail($validated['stage_id']);

        try {
            $lead->moveToStage($stage, $request->user());
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['stage_id' => $e->getMessage()]);
        }

        return back();
    }

    /**
     * Borrar un lead se lleva su historial de conversación por delante y no
     * hay vuelta atrás, así que queda reservado a admin/owner. Un responsable
     * no puede hacer desaparecer el registro de lo que habló con el contacto.
     */
    public function destroy(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorizeLead($request, $lead);

        abort_unless($request->user()->hasRoleAtLeast(User::ROLE_ADMIN), 403,
            'Solo un administrador puede eliminar un lead y su historial.');

        $lead->delete();

        return redirect()->route('leads.index')->with('success', 'Lead eliminado.');
    }

    public function syncTags(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorizeLead($request, $lead);

        $validated = $request->validate(['tag_ids' => 'nullable|array', 'tag_ids.*' => 'uuid']);

        $valid = Tag::forAccount($lead->account_id)
            ->whereIn('id', $validated['tag_ids'] ?? [])
            ->pluck('id');

        $lead->tags()->sync($valid);

        return back();
    }

    public function addNote(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorizeLead($request, $lead);

        $validated = $request->validate(['text' => 'required|string|max:5000']);

        $lead->notes()->create([
            'account_id' => $lead->account_id,
            'user_id' => $request->user()->id,
            'text' => $validated['text'],
        ]);

        $lead->recordEvent('note_added', $request->user(), ['text' => mb_substr($validated['text'], 0, 200)]);

        return back()->with('success', 'Nota añadida.');
    }

    /** Envía un WhatsApp al contacto del lead a través del wacrm. */
    public function sendWhatsapp(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorizeLead($request, $lead);

        $validated = $request->validate(['text' => 'required|string|max:4096']);

        $integration = $request->user()->account->integration;

        if (! $integration?->is_active) {
            throw ValidationException::withMessages(['text' => 'La integración con el CRM de WhatsApp no está activa.']);
        }

        if (! $lead->contact?->phone) {
            throw ValidationException::withMessages(['text' => 'El lead no tiene un contacto con teléfono.']);
        }

        try {
            Client::for($integration)->sendMessage(
                $lead->contact->phone_normalized ?? $lead->contact->phone,
                $validated['text'],
            );
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['text' => $e->getMessage()]);
        }

        // NO grabamos el evento message_out localmente: el wacrm dispara el
        // webhook `message.sent` que EventProcessor@handleOutboundMessage
        // registra en el timeline. Si lo grabáramos acá también, el mensaje
        // aparecería duplicado. El webhook es la única fuente de verdad.

        return back()->with('success', 'WhatsApp enviado.');
    }

    /**
     * Crea una cotización en Komo Invoice pre-llenada con datos del lead
     * (Fase 4 F4-Invoice). Devuelve el link público que abre la cotización
     * en Invoice para completar los items.
     */
    public function createQuote(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorizeLead($request, $lead);

        $integration = $request->user()->account->integration;
        if (! $integration?->invoice_url || ! $integration->invoice_api_key) {
            return back()->withErrors(['quote' => 'La integración con Komo Invoice no está cableada — pídele al hub que reaprovisione el ecosistema.']);
        }

        if (! $lead->contact) {
            return back()->withErrors(['quote' => 'El lead no tiene contacto — no se puede cotizar sin cliente.']);
        }

        $payload = [
            'customer' => [
                'name' => $lead->contact->name ?? $lead->title,
                'email' => $lead->contact->email,
                'phone' => $lead->contact->phone,
                'company' => $lead->company?->name,
                'komo_contact_id' => $lead->contact->id,
            ],
            'komo_lead_id' => $lead->id,
            'currency' => $lead->currency ?? 'USD',
            // Item inicial con el valor estimado del lead; el vendedor lo
            // ajustará en Invoice antes de enviar la cotización.
            'items' => [[
                'name' => $lead->title,
                'qty' => 1,
                'unit_price_cents' => (int) round(((float) $lead->value) * 100),
                'tax_rate_bps' => 0,
            ]],
        ];

        try {
            $data = \App\Services\Invoice\Client::for($integration)->createQuote($payload);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['quote' => $e->getMessage()]);
        }

        $lead->recordEvent('quote_created', $request->user(), [
            'quote_id' => $data['data']['id'] ?? null,
            'number' => $data['data']['number'] ?? null,
        ]);

        // Devuelve el edit_url para que el vendedor termine de armar los items en Invoice.
        return redirect()->away($data['data']['edit_url']);
    }

    private function authorizeLead(Request $request, Lead $lead): void
    {
        $user = $request->user();

        abort_if($lead->account_id !== $user->account_id, 403);

        // Agent/viewer: solo puede ver/editar/escribir en leads asignados a él.
        // admin/owner: acceso completo (para hacer seguimiento del equipo).
        if (! $user->hasRoleAtLeast(User::ROLE_ADMIN)) {
            abort_if($lead->responsible_user_id !== $user->id, 403,
                'No tienes acceso a este lead. Pídele al admin que te lo asigne.');
        }
    }
}
