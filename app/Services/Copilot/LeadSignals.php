<?php

namespace App\Services\Copilot;

use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\PipelineStage;
use App\Models\Task;
use Illuminate\Support\Collection;

/**
 * Señales crudas de un lead: los hechos, sin juicio de valor.
 *
 * Separado de `LeadScorer` a propósito. Acá se mide («hace 9 días que no
 * escribe»), allá se pondera («eso resta 12 puntos»). Si el día de mañana los
 * pesos se calculan con un modelo entrenado en vez de a mano, esta clase no
 * cambia: es la que sabe leer la base, no la que opina.
 *
 * Todo sale en **lote**: una consulta agregada por familia de señal para toda
 * la lista de leads. Puntuar una cuenta entera de noche con N+1 sería
 * impracticable.
 */
class LeadSignals
{
    public function __construct(private readonly string $accountId) {}

    /**
     * @param  Collection<int, Lead>  $leads
     * @return array<string, array<string, mixed>>  señales por lead_id
     */
    public function forLeads(Collection $leads): array
    {
        if ($leads->isEmpty()) {
            return [];
        }

        $ids = $leads->pluck('id');

        $messages = $this->messageAggregates($ids);
        $lastStageChange = $this->lastStageChange($ids);
        $withPendingTask = $this->leadsWithPendingTask($ids);
        $sourceWinRate = $this->sourceWinRates();
        $stagePositions = $this->stagePositions();
        $avgWonValue = $this->averageWonValue();

        $out = [];

        foreach ($leads as $lead) {
            $msg = $messages[$lead->id] ?? ['inbound' => 0, 'human_out' => 0, 'last_inbound_at' => null];

            // El reloj de «estancado» arranca en el último cambio de etapa; si
            // nunca se movió, en la creación del lead.
            $stageSince = $lastStageChange[$lead->id] ?? $lead->created_at;
            $position = $stagePositions[$lead->stage_id] ?? null;

            $out[$lead->id] = [
                'inbound_count' => (int) $msg['inbound'],
                'human_replies' => (int) $msg['human_out'],
                'days_since_inbound' => $msg['last_inbound_at']
                    ? $msg['last_inbound_at']->diffInDays(now(), true)
                    : null,
                'days_in_stage' => $stageSince ? $stageSince->diffInDays(now(), true) : 0.0,
                'age_days' => $lead->created_at ? $lead->created_at->diffInDays(now(), true) : 0.0,
                'has_pending_task' => isset($withPendingTask[$lead->id]),
                // Qué tan avanzado está en el pipeline, de 0 a 1. `null` cuando
                // la etapa es terminal o desconocida: no aporta.
                'stage_progress' => $position,
                'source_win_rate' => $sourceWinRate[$lead->source] ?? null,
                'value_ratio' => $avgWonValue > 0 ? (float) $lead->value / $avgWonValue : null,
            ];
        }

        return $out;
    }

    /**
     * Entrantes, respuestas humanas y último entrante, por lead.
     *
     * La distinción humano/IA sale del `payload.sender` que ya graba el
     * webhook del wacrm — misma convención que `ResponseMetrics`. Se agrega en
     * SQL y no en PHP porque acá se puntúa la cuenta entera, no una ficha.
     *
     * @param  Collection<int, string>  $ids
     * @return array<string, array<string, mixed>>
     */
    private function messageAggregates(Collection $ids): array
    {
        return LeadEvent::forAccount($this->accountId)
            ->whereIn('lead_id', $ids)
            ->whereIn('event_type', ['message_in', 'message_out'])
            ->selectRaw("lead_id,
                SUM(CASE WHEN event_type = 'message_in' THEN 1 ELSE 0 END) as inbound,
                SUM(CASE WHEN event_type = 'message_out'
                     AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.sender')), 'agent') <> 'bot'
                    THEN 1 ELSE 0 END) as human_out,
                MAX(CASE WHEN event_type = 'message_in' THEN created_at END) as last_inbound_at")
            ->groupBy('lead_id')
            ->get()
            ->keyBy('lead_id')
            ->map(fn ($row) => [
                'inbound' => $row->inbound,
                'human_out' => $row->human_out,
                'last_inbound_at' => $row->last_inbound_at ? \Carbon\Carbon::parse($row->last_inbound_at) : null,
            ])
            ->all();
    }

    /**
     * @param  Collection<int, string>  $ids
     * @return array<string, \Carbon\CarbonInterface>
     */
    private function lastStageChange(Collection $ids): array
    {
        return LeadEvent::forAccount($this->accountId)
            ->whereIn('lead_id', $ids)
            ->where('event_type', 'stage_changed')
            ->selectRaw('lead_id, MAX(created_at) as last_at')
            ->groupBy('lead_id')
            ->pluck('last_at', 'lead_id')
            ->map(fn ($at) => \Carbon\Carbon::parse($at))
            ->all();
    }

    /**
     * @param  Collection<int, string>  $ids
     * @return array<string, bool>
     */
    private function leadsWithPendingTask(Collection $ids): array
    {
        return Task::forAccount($this->accountId)
            ->whereIn('lead_id', $ids)
            ->whereNull('completed_at')
            ->distinct()
            ->pluck('lead_id')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    /**
     * Tasa histórica de cierre por fuente en esta cuenta.
     *
     * Es la señal más «predictiva» del conjunto y la más barata: si de WhatsApp
     * cierra el 40% y de formulario web el 5%, el origen del lead vale más que
     * cualquier heurística de actividad. Solo se devuelven las fuentes con al
     * menos 10 cerrados: con 3 casos, «100% de conversión» es ruido.
     *
     * @return array<string, float>
     */
    private function sourceWinRates(): array
    {
        return Lead::forAccount($this->accountId)
            ->whereIn('status', ['won', 'lost'])
            ->selectRaw("COALESCE(source, 'manual') as src,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END) as won")
            ->groupBy('src')
            ->having('total', '>=', 10)
            ->get()
            ->mapWithKeys(fn ($r) => [$r->src => (float) $r->won / (float) $r->total])
            ->all();
    }

    /**
     * Posición relativa (0..1) de cada etapa abierta dentro de su pipeline.
     *
     * Las terminales quedan fuera: un lead ganado o perdido no se prioriza.
     *
     * @return array<string, float>
     */
    private function stagePositions(): array
    {
        $out = [];

        PipelineStage::whereHas('pipeline', fn ($q) => $q->where('account_id', $this->accountId))
            ->where('stage_type', 'open')
            ->orderBy('position')
            ->get(['id', 'pipeline_id', 'position'])
            ->groupBy('pipeline_id')
            ->each(function (Collection $stages) use (&$out) {
                $last = max(1, $stages->count() - 1);
                $stages->values()->each(function ($stage, $i) use (&$out, $last) {
                    $out[$stage->id] = round($i / $last, 3);
                });
            });

        return $out;
    }

    /** Ticket promedio de los ganados; base para comparar el valor de un lead. */
    private function averageWonValue(): float
    {
        return (float) (Lead::forAccount($this->accountId)
            ->where('status', 'won')
            ->avg('value') ?? 0);
    }
}
