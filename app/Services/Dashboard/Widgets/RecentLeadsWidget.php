<?php

namespace App\Services\Dashboard\Widgets;

use App\Models\Lead;
use App\Services\Dashboard\WidgetContext;
use App\Services\WhatsApp\ServiceWindow;

/**
 * Los últimos que entraron al embudo, con su ventana de servicio.
 *
 * `source_ref` viaja porque `ServiceWindow` lo usa como respaldo para los leads
 * que vinieron de un anuncio (ventana de 72 h en vez de 24 h).
 */
class RecentLeadsWidget
{
    public function resolve(WidgetContext $c): array
    {
        $leads = $c->leads(Lead::forAccount($c->accountId)
            ->with(['contact:id,name,phone', 'stage:id,name,color'])
            ->withCount(['tasks as pending_tasks_count' => fn ($q) => $q->whereNull('completed_at')])
            ->latest())
            ->limit(6)
            ->get(['id', 'title', 'value', 'currency', 'status', 'contact_id', 'stage_id', 'responsible_user_id', 'source_ref', 'created_at']);

        $windows = app(ServiceWindow::class)->forLeads($leads);

        return [
            'currency' => $c->currency,
            'items' => $leads->map(fn (Lead $lead) => [
                'id' => $lead->id,
                'title' => $lead->title,
                'value' => (float) $lead->value,
                'currency' => $lead->currency,
                'contact' => $lead->contact ? ['name' => $lead->contact->name] : null,
                'stage' => $lead->stage ? ['name' => $lead->stage->name, 'color' => $lead->stage->color] : null,
                'hasPendingTask' => $lead->pending_tasks_count > 0,
                'service_window' => $windows[$lead->id] ?? null,
                'created_at' => $lead->created_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
