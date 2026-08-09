<?php

namespace App\Services\Dashboard\Widgets;

use App\Models\Lead;
use App\Services\Copilot\LeadSignals;
use App\Services\Copilot\NextActions;
use App\Services\Dashboard\WidgetContext;

/**
 * Los leads de mayor puntaje que **además tienen algo pendiente**.
 *
 * La condición doble es el widget entero. Un ranking de score a secas es una
 * tabla de honor: los primeros puestos son casi siempre los mismos y no piden
 * nada. Lo accionable es el cruce — «vale mucho **y** hay algo que hacer hoy».
 * Un lead caliente al que ya se le contestó y tiene tarea agendada no aparece,
 * porque no hay nada que hacer con él ahora.
 */
class CopilotPrioritiesWidget
{
    /** Cuántos se muestran. Una lista larga no se prioriza, se hojea. */
    private const LIMIT = 5;

    /**
     * Candidatos que se examinan antes de filtrar por acción pendiente. Acotado
     * porque cada uno cuesta una ventana de servicio.
     */
    private const CANDIDATES = 25;

    public function resolve(WidgetContext $c): array
    {
        $candidates = $c->leads(Lead::forAccount($c->accountId)
            ->where('status', 'open')
            ->whereNotNull('score')
            ->with(['contact:id,name', 'stage:id,name,color'])
            ->orderByDesc('score'))
            ->limit(self::CANDIDATES)
            ->get();

        if ($candidates->isEmpty()) {
            return ['items' => [], 'scored' => false];
        }

        // Señales en lote: `NextActions` las recibe hechas para no recalcularlas
        // lead por lead.
        $signals = (new LeadSignals($c->accountId))->forLeads($candidates);
        $nextActions = app(NextActions::class);

        $items = $candidates
            ->map(function (Lead $lead) use ($signals, $nextActions) {
                $actions = $nextActions->forLead($lead, $signals[$lead->id] ?? null);

                return $actions === [] ? null : [
                    'id' => $lead->id,
                    'title' => $lead->title,
                    'contact' => $lead->contact?->name,
                    'score' => $lead->score,
                    'band' => $lead->score_band,
                    'stage' => $lead->stage ? ['name' => $lead->stage->name, 'color' => $lead->stage->color] : null,
                    // Solo la más urgente: el dashboard prioriza leads, la ficha
                    // muestra todo lo que se puede hacer con uno.
                    'action' => $actions[0],
                ];
            })
            ->filter()
            ->take(self::LIMIT)
            ->values();

        return [
            'items' => $items->all(),
            // Distingue «no hay nada pendiente» de «todavía no se puntuó nada»,
            // que en pantalla son mensajes muy distintos.
            'scored' => true,
        ];
    }
}
