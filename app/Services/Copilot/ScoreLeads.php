<?php

namespace App\Services\Copilot;

use App\Models\Lead;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula y persiste el score de los leads abiertos de una cuenta.
 *
 * Solo los **abiertos**: un lead ganado o perdido conserva el score y la banda
 * que tenía al cerrarse. Eso no es pereza, es el requisito de la calibración —
 * si al cerrar se repuntuara, se perdería el dato de en qué banda estaba
 * cuando todavía se podía hacer algo, que es justamente lo que se quiere medir.
 */
class ScoreLeads
{
    public function __construct(
        private readonly LeadScorer $scorer = new LeadScorer,
    ) {}

    /**
     * @return array{scored: int, mode: string}
     */
    public function forAccount(string $accountId): array
    {
        $leads = Lead::forAccount($accountId)
            ->where('status', 'open')
            ->get(['id', 'stage_id', 'source', 'value', 'created_at']);

        if ($leads->isEmpty()) {
            return ['scored' => 0, 'mode' => 'absoluto'];
        }

        $signals = (new LeadSignals($accountId))->forLeads($leads);

        // Primera pasada: puntuar. Las bandas necesitan la distribución
        // completa, así que no se pueden asignar en el mismo recorrido.
        $scored = $leads->map(function (Lead $lead) use ($signals) {
            $result = $this->scorer->score($signals[$lead->id]);

            return ['lead' => $lead, 'score' => $result['score'], 'factors' => $result['factors']];
        });

        $bands = $this->scorer->bands($scored->pluck('score'));
        $now = now();

        // Segunda pasada: banda + persistencia. Se guarda por modelo (y no con
        // un `update()` de query builder) para que el cast `array` de
        // `score_factors` haga el encode una sola vez; los volúmenes acá son de
        // miles, no de millones.
        DB::transaction(function () use ($scored, $bands, $now) {
            foreach ($scored as $row) {
                $row['lead']->forceFill([
                    'score' => $row['score'],
                    'score_band' => $this->scorer->bandFor($row['score'], $bands),
                    'score_factors' => $row['factors'],
                    'scored_at' => $now,
                ])->save();
            }
        });

        return ['scored' => $scored->count(), 'mode' => $bands['mode']];
    }

    /**
     * Repuntúa un lead suelto sin recalcular las bandas de la cuenta.
     *
     * Para las reacciones inmediatas (entró un mensaje, cambió de etapa). La
     * banda se deriva de los umbrales vigentes del resto de la cartera, así que
     * puede quedar levemente desfasada hasta la próxima pasada completa —
     * aceptable a cambio de no recorrer la cuenta entera en cada mensaje.
     */
    public function forLead(Lead $lead): void
    {
        if ($lead->status !== Lead::STATUS_OPEN) {
            return;
        }

        $signals = (new LeadSignals($lead->account_id))->forLeads(collect([$lead]));
        $result = $this->scorer->score($signals[$lead->id]);

        $bands = $this->scorer->bands(
            Lead::forAccount($lead->account_id)->where('status', 'open')
                ->whereNotNull('score')->pluck('score')
        );

        $lead->forceFill([
            'score' => $result['score'],
            'score_band' => $this->scorer->bandFor($result['score'], $bands),
            'score_factors' => $result['factors'],
            'scored_at' => now(),
        ])->save();
    }
}
