<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\Task;
use App\Models\User;
use App\Services\WhatsApp\ServiceWindow;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    // Mismo umbral que InboxController: >30 min sin respuesta = urgente
    private const SLA_MINUTES = 30;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $accountId = $user->account_id;
        $isAdmin = $user->hasRoleAtLeast(User::ROLE_ADMIN);

        // Scope Lead: admin ve todo, agent solo lo asignado.
        $leadScope = fn ($q) => $isAdmin ? $q : $q->where('responsible_user_id', $user->id);
        $taskScope = fn ($q) => $isAdmin ? $q : $q->where('assigned_to', $user->id);

        // Leads urgentes: ultimo evento es message_in hace > SLA_MINUTES
        $urgent = $this->computeUrgentLeads($accountId, $user, $isAdmin);

        return Inertia::render('Dashboard', [
            'stats' => [
                'openLeads' => $leadScope(Lead::forAccount($accountId)->where('status', 'open'))->count(),
                'openValue' => (float) $leadScope(Lead::forAccount($accountId)->where('status', 'open'))->sum('value'),
                'wonThisMonth' => $leadScope(Lead::forAccount($accountId)->where('status', 'won')
                    ->where('closed_at', '>=', now()->startOfMonth()))->count(),
                'wonValueThisMonth' => (float) $leadScope(Lead::forAccount($accountId)->where('status', 'won')
                    ->where('closed_at', '>=', now()->startOfMonth()))->sum('value'),
                'overdueTasks' => $taskScope(Task::forAccount($accountId)->overdue())->count(),
                'tasksToday' => $taskScope(Task::forAccount($accountId)->pending()
                    ->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()]))->count(),
                // Regla Kommo: leads abiertos SIN tarea pendiente = leads olvidados.
                'leadsWithoutTask' => $leadScope(Lead::forAccount($accountId)->where('status', 'open')
                    ->whereDoesntHave('tasks', fn ($q) => $q->whereNull('completed_at')))->count(),
                'urgentLeads' => $urgent['count'],
            ],
            'urgentLeads' => $urgent['items'],
            'slaMinutes' => self::SLA_MINUTES,
            // `source_ref` viaja porque la ventana de servicio lo usa como
            // fallback para los leads que vinieron de un anuncio.
            'recentLeads' => $this->withServiceWindow(
                $leadScope(Lead::forAccount($accountId)
                    ->with(['contact:id,name,phone', 'stage:id,name,color'])
                    ->latest())
                    ->limit(6)
                    ->get(['id', 'title', 'value', 'currency', 'status', 'contact_id', 'stage_id', 'responsible_user_id', 'source_ref', 'created_at'])
            ),
            'myTasks' => Task::forAccount($accountId)
                ->pending()
                ->where('assigned_to', $user->id)
                ->with('lead:id,title')
                ->orderBy('due_at')
                ->limit(6)
                ->get(),
            'currency' => $user->account->default_currency,
        ]);
    }

    /**
     * Calcula los leads urgentes: abiertos, con chat de WhatsApp, cuyo ultimo evento
     * es un mensaje entrante hace mas de SLA_MINUTES minutos.
     */
    private function computeUrgentLeads(string $accountId, User $user, bool $isAdmin): array
    {
        $leadsQ = Lead::forAccount($accountId)
            ->where('status', 'open')
            ->whereNotNull('wacrm_conversation_id');

        if (! $isAdmin) {
            $leadsQ->where('responsible_user_id', $user->id);
        }

        $leadIds = $leadsQ->pluck('id');
        if ($leadIds->isEmpty()) {
            return ['count' => 0, 'items' => []];
        }

        // Ultimo evento de mensaje por lead
        $lastEvents = LeadEvent::whereIn('lead_id', $leadIds)
            ->whereIn('event_type', ['message_in', 'message_out'])
            ->select('lead_id', 'event_type', 'created_at')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('lead_id')
            ->map(fn ($g) => $g->first());

        $threshold = now()->subMinutes(self::SLA_MINUTES);
        $urgentIds = $lastEvents
            ->filter(fn ($e) => $e->event_type === 'message_in' && $e->created_at < $threshold)
            ->keys();

        if ($urgentIds->isEmpty()) {
            return ['count' => 0, 'items' => []];
        }

        $now = now();
        $items = Lead::whereIn('id', $urgentIds)
            ->with(['contact:id,name,phone', 'stage:id,name,color'])
            ->get(['id', 'title', 'contact_id', 'stage_id', 'responsible_user_id'])
            ->map(function ($l) use ($lastEvents, $now) {
                $lastAt = $lastEvents[$l->id]->created_at;
                $mins = (int) $now->diffInMinutes($lastAt, true);

                return [
                    'id' => $l->id,
                    'title' => $l->title,
                    'contact' => $l->contact ? ['name' => $l->contact->name, 'phone' => $l->contact->phone] : null,
                    'stage' => $l->stage ? ['name' => $l->stage->name, 'color' => $l->stage->color] : null,
                    'waiting_minutes' => $mins,
                ];
            })
            ->sortByDesc('waiting_minutes')
            ->values()
            ->take(5); // top 5 mas urgentes en el widget

        return ['count' => $urgentIds->count(), 'items' => $items->all()];
    }

    /**
     * Adjunta la ventana de servicio de WhatsApp a cada lead del listado:
     * en el dashboard es lo que dice a quién todavía se le puede escribir
     * sin costo.
     *
     * @param  Collection<int, Lead>  $leads
     * @return Collection<int, Lead>
     */
    private function withServiceWindow($leads)
    {
        $windows = app(ServiceWindow::class)->forLeads($leads);

        return $leads->each(
            fn (Lead $lead) => $lead->setAttribute('service_window', $windows[$lead->id] ?? null)
        );
    }
}
