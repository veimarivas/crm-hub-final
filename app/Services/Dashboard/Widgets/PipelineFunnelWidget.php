<?php

namespace App\Services\Dashboard\Widgets;

use App\Models\Lead;
use App\Models\PipelineStage;
use App\Services\Dashboard\WidgetContext;

/**
 * Cuántos leads abiertos hay en cada etapa **ahora mismo**.
 *
 * Estado actual, no del periodo: la pregunta que contesta es «¿dónde está
 * trabado el embudo hoy?». La evolución en el tiempo vive en `/reports`.
 */
class PipelineFunnelWidget
{
    public function resolve(WidgetContext $c): array
    {
        $stages = PipelineStage::whereHas('pipeline', fn ($q) => $q
            ->where('account_id', $c->accountId)->where('is_default', true))
            ->where('stage_type', 'open')
            ->orderBy('position')
            ->get(['id', 'name', 'color']);

        if ($stages->isEmpty()) {
            return ['steps' => []];
        }

        // Un solo GROUP BY para todas las etapas: una consulta por etapa sería
        // N+1 disfrazado de «son pocas».
        $counts = $c->leads(Lead::forAccount($c->accountId)->where('status', 'open'))
            ->whereIn('stage_id', $stages->pluck('id'))
            ->selectRaw('stage_id, COUNT(*) as total, COALESCE(SUM(value), 0) as value')
            ->groupBy('stage_id')
            ->get()
            ->keyBy('stage_id');

        return [
            'currency' => $c->currency,
            'steps' => $stages->map(fn ($stage) => [
                'id' => $stage->id,
                'name' => $stage->name,
                'color' => $stage->color,
                'count' => (int) ($counts[$stage->id]->total ?? 0),
                'value' => (float) ($counts[$stage->id]->value ?? 0),
            ])->all(),
        ];
    }
}
