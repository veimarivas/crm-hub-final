<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\User;
use App\Services\WhatsApp\ServiceWindow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bandeja de conversaciones, a tres columnas como el Inbox del wacrm:
 * lista de chats · hilo · datos del lead.
 *
 * Antes era un listado que sacaba de la bandeja: cada respuesta obligaba a
 * entrar a la ficha del lead y volver. Ahora se lee y se contesta sin salir,
 * que es lo que hace usable atender veinte conversaciones seguidas.
 *
 * El alcance por rol no cambia y se corta en el servidor: el admin ve todo,
 * el agente ve y contesta EXCLUSIVAMENTE los leads que tiene asignados.
 */
class InboxController extends Controller
{
    // Umbral SLA: leads con ultimo mensaje entrante hace mas de N minutos y sin respuesta
    private const SLA_MINUTES = 30;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $accountId = $user->account_id;
        $isAdmin = $user->hasRoleAtLeast(User::ROLE_ADMIN);
        $filter = $request->query('filter', 'mine');
        $q = trim((string) $request->query('q', ''));

        // Las bandejas ajenas no existen para un agente: si llega un ?filter
        // de admin (link compartido, URL a mano) cae a la suya en vez de
        // mostrar un vacio que se lee como "no hay nada".
        if (! $isAdmin && ! in_array($filter, ['mine', 'unresponded'], true)) {
            $filter = 'mine';
        }

        // Base: leads abiertos con conversacion de WhatsApp
        $base = Lead::forAccount($accountId)
            ->where('status', 'open')
            ->whereNotNull('wacrm_conversation_id');

        // Scope por rol: el agente ve EXCLUSIVAMENTE lo que se le asigno. Ni
        // los leads sin responsable ni los de sus companeros — con el
        // round-robin repartiendo automaticamente, un lead sin asignar es
        // trabajo que el admin todavia no distribuyo, no una bandeja comun.
        if (! $isAdmin) {
            $base->where('responsible_user_id', $user->id);
        }

        // Ultimo evento por lead (mensaje entrante o saliente)
        $lastEvents = LeadEvent::select('lead_id', DB::raw('MAX(created_at) as last_at'))
            ->whereIn('event_type', ['message_in', 'message_out'])
            ->whereIn('lead_id', (clone $base)->pluck('id'))
            ->groupBy('lead_id')
            ->pluck('last_at', 'lead_id');

        // Traer los leads con relaciones
        $leadsQuery = (clone $base)
            ->with(['contact:id,name,phone,phone_normalized', 'responsible:id,name', 'stage:id,name,color', 'tags:id,name,color'])
            ->withCount(['tasks as pending_tasks_count' => fn ($qq) => $qq->whereNull('completed_at')]);

        if ($q !== '') {
            $leadsQuery->where(function ($qq) use ($q) {
                $qq->where('title', 'like', "%{$q}%")
                    ->orWhereHas('contact', function ($cq) use ($q) {
                        $cq->where('name', 'like', "%{$q}%")
                            ->orWhere('phone', 'like', "%{$q}%")
                            ->orWhere('phone_normalized', 'like', "%{$q}%");
                    });
            });
        }

        $leads = $leadsQuery->get();

        // Cargar los ultimos eventos de cada lead (para preview del mensaje)
        $leadIds = $leads->pluck('id')->all();
        $previewEvents = LeadEvent::whereIn('lead_id', $leadIds)
            ->whereIn('event_type', ['message_in', 'message_out'])
            ->orderByDesc('created_at')
            ->get(['id', 'lead_id', 'event_type', 'payload', 'created_at'])
            ->groupBy('lead_id')
            ->map(fn ($g) => $g->first());

        $now = now();

        // Ventana de servicio de cada conversación (24 h, o 72 h si vino de
        // un anuncio): en lote para no hacer dos queries por lead.
        $windows = app(ServiceWindow::class)->forLeads($leads);

        $items = $leads->map(function (Lead $lead) use ($previewEvents, $lastEvents, $now, $windows) {
            $last = $previewEvents->get($lead->id);
            $lastAt = $lastEvents->get($lead->id);
            $waiting = 0;
            $waitingSla = false;
            if ($last && $last->event_type === 'message_in') {
                $mins = (int) $now->diffInMinutes($last->created_at, true);
                $waiting = $mins;
                $waitingSla = $mins >= self::SLA_MINUTES;
            }
            $payload = $last?->payload ?? [];
            $preview = trim((string) ($payload['text'] ?? $payload['transcript'] ?? ''));
            if ($preview === '' && ($payload['type'] ?? null)) {
                $preview = match ($payload['type']) {
                    'audio' => '🎙 Audio',
                    'image' => '🖼️ Imagen',
                    'video' => '🎥 Video',
                    'document' => '📄 Documento',
                    'sticker' => '🟪 Sticker',
                    default => '['.$payload['type'].']',
                };
            }
            if (mb_strlen($preview) > 120) {
                $preview = mb_substr($preview, 0, 120).'…';
            }

            return [
                'id' => $lead->id,
                'title' => $lead->title,
                'stage' => $lead->stage ? ['name' => $lead->stage->name, 'color' => $lead->stage->color] : null,
                'contact' => $lead->contact ? [
                    'name' => $lead->contact->name,
                    'phone' => $lead->contact->phone,
                    'phone_normalized' => $lead->contact->phone_normalized,
                ] : null,
                'responsible' => $lead->responsible ? [
                    'id' => $lead->responsible->id,
                    'name' => $lead->responsible->name,
                ] : null,
                'tags' => $lead->tags->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'color' => $t->color])->all(),
                'ai_enabled' => (bool) $lead->ai_enabled,
                'value' => $lead->value,
                'pending_tasks' => (int) $lead->pending_tasks_count,
                'last_message' => $last ? [
                    'direction' => $last->event_type === 'message_in' ? 'in' : 'out',
                    'preview' => $preview,
                    'at' => $last->created_at,
                ] : null,
                'waiting_minutes' => $waiting,
                'waiting_sla' => $waitingSla,
                'service_window' => $windows[$lead->id] ?? null,
                'last_activity_at' => $lastAt ?? $lead->created_at,
            ];
        })
            ->sortByDesc('last_activity_at')
            ->values();

        // Aplicar filtro
        $filtered = match ($filter) {
            'unassigned' => $items->filter(fn ($i) => ! $i['responsible']),
            'unresponded' => $items->filter(fn ($i) => $i['waiting_sla']),
            'all' => $items,
            default => $items->filter(fn ($i) => $i['responsible'] && $i['responsible']['id'] === $user->id),
        };

        // Contadores para las pestañas
        $counts = [
            'mine' => $items->filter(fn ($i) => $i['responsible'] && $i['responsible']['id'] === $user->id)->count(),
            'unassigned' => $items->filter(fn ($i) => ! $i['responsible'])->count(),
            'unresponded' => $items->filter(fn ($i) => $i['waiting_sla'])->count(),
            'all' => $items->count(),
        ];

        $filtered = $filtered->values();

        // Conversación abierta: la pedida por ?lead=, y si no la primera de la
        // bandeja — entrar al Inbox y encontrar el panel vacío obliga a un
        // clic que no aporta nada.
        $selectedId = $request->query('lead') ?: $filtered->first()['id'] ?? null;

        return Inertia::render('Inbox/Index', [
            'items' => $filtered,
            'counts' => $counts,
            'filter' => $filter,
            'q' => $q,
            'isAdmin' => $isAdmin,
            'slaMinutes' => self::SLA_MINUTES,
            'conversation' => $selectedId ? $this->conversation($request, $selectedId) : null,
        ]);
    }

    /**
     * Hilo de una conversación para el panel central.
     *
     * Devuelve null en vez de 403 cuando el lead no es de quien mira: el id
     * puede venir de un enlace viejo o de una conversación que se reasignó, y
     * tumbar la pantalla entera por eso sería peor que mostrarla vacía. El
     * corte igual existe — sin este método no se ve ni un mensaje.
     *
     * @return array<string, mixed>|null
     */
    private function conversation(Request $request, string $leadId): ?array
    {
        $user = $request->user();

        $lead = Lead::forAccount($user->account_id)
            ->with(['contact', 'responsible:id,name', 'stage:id,name,color', 'tags:id,name,color'])
            ->find($leadId);

        if (! $lead) {
            return null;
        }

        if (! $user->hasRoleAtLeast(User::ROLE_ADMIN) && $lead->responsible_user_id !== $user->id) {
            return null;
        }

        // Solo el tramo reciente: el Inbox es para atender, no para auditar.
        // El historial completo sigue en la ficha del lead.
        $events = $lead->events()
            ->latest()
            ->limit(80)
            ->get(['id', 'lead_id', 'event_type', 'payload', 'created_at'])
            ->sortBy('created_at')
            ->values();

        // Próxima reunión reservada (solo si la fecha todavía no pasó).
        $nextBooking = $lead->events()
            ->where('event_type', 'booking')
            ->limit(50)
            ->get(['id', 'payload'])
            ->pluck('payload')
            ->map(fn ($p) => $p['scheduled_at'] ?? null)
            ->filter()
            ->filter(fn ($iso) => now()->lt($iso))
            ->sort()
            ->first();

        return [
            'lead' => [
                'id' => $lead->id,
                'title' => $lead->title,
                'value' => $lead->value,
                'currency' => $lead->currency,
                'status' => $lead->status,
                'ai_enabled' => (bool) $lead->ai_enabled,
                'ai_pending' => (bool) $lead->ai_pending,
                'ai_paused_until' => $lead->ai_paused_until,
                'next_booking' => $nextBooking ? ['scheduled_at' => $nextBooking] : null,
                'stage' => $lead->stage ? ['id' => $lead->stage->id, 'name' => $lead->stage->name, 'color' => $lead->stage->color] : null,
                'responsible' => $lead->responsible ? ['id' => $lead->responsible->id, 'name' => $lead->responsible->name] : null,
                'tags' => $lead->tags->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'color' => $t->color])->all(),
                'contact' => $lead->contact ? [
                    'id' => $lead->contact->id,
                    'name' => $lead->contact->name,
                    'phone' => $lead->contact->phone,
                    'phone_normalized' => $lead->contact->phone_normalized,
                    'email' => $lead->contact->email,
                ] : null,
            ],
            'events' => $events,
            'service_window' => app(ServiceWindow::class)->forLead($lead),
            'can_write' => true,
        ];
    }
}
