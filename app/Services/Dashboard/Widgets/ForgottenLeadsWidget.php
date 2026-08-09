<?php

namespace App\Services\Dashboard\Widgets;

use App\Models\Lead;
use App\Services\Dashboard\WidgetContext;

/**
 * Los que llevan más tiempo abiertos sin una sola tarea agendada.
 *
 * El KPI dice cuántos son; esto dice cuáles. Un número no se acciona, una
 * lista de cinco nombres sí.
 */
class ForgottenLeadsWidget
{
    public function resolve(WidgetContext $c): array
    {
        $base = $c->leads(Lead::forAccount($c->accountId)
            ->where('status', 'open')
            ->whereDoesntHave('tasks', fn ($q) => $q->whereNull('completed_at')));

        return [
            'total' => (clone $base)->count(),
            'items' => (clone $base)
                ->with(['contact:id,name', 'stage:id,name,color'])
                ->orderBy('created_at')
                ->limit(5)
                ->get(['id', 'title', 'value', 'currency', 'contact_id', 'stage_id', 'created_at'])
                ->map(fn (Lead $lead) => [
                    'id' => $lead->id,
                    'title' => $lead->title,
                    'contact' => $lead->contact?->name,
                    'value' => (float) $lead->value,
                    'currency' => $lead->currency,
                    'stage' => $lead->stage ? ['name' => $lead->stage->name, 'color' => $lead->stage->color] : null,
                    'days_open' => (int) $lead->created_at->diffInDays(now(), true),
                ])
                ->all(),
        ];
    }
}
