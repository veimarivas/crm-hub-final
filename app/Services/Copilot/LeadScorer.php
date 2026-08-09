<?php

namespace App\Services\Copilot;

use App\Models\Lead;
use Illuminate\Support\Collection;

/**
 * Score de probabilidad de cierre — explicable, no mágico.
 *
 * ## Por qué no hay ML acá
 *
 * No hay infraestructura de entrenamiento ni volumen garantizado por cuenta.
 * Un «87% de probabilidad de cierre» salido de pesos inventados se lee como
 * ciencia y es decoración: la primera vez que el 87% no cierra, el equipo deja
 * de mirar el módulo entero. Así que:
 *
 *  - Los pesos son **fijos, pocos y justificados uno por uno** (abajo).
 *  - Cada lead guarda el **desglose** de dónde salió su puntaje.
 *  - La **calibración** no la inventa el modelo: se mide contra los leads ya
 *    cerrados de la cuenta («de los que estuvieron en caliente, cerró el 34%»).
 *    Con menos de `MIN_CLOSED_FOR_CALIBRATION` cerrados, la UI dice
 *    **«sin calibrar»** en vez de mostrar un porcentaje que no significa nada.
 *
 * Cuando una cuenta acumule volumen, `LeadSignals` ya devuelve la matriz de
 * señales lista para entrenar algo de verdad; esta clase se reemplaza sin tocar
 * la de al lado.
 *
 * ## Los pesos
 *
 * Suman 100 en el mejor caso posible. Cada factor devuelve entre 0 y su peso.
 */
class LeadScorer
{
    /**
     * Peso máximo de cada factor y por qué está.
     *
     * - `engagement` (25): que el cliente escriba es la señal más fuerte que
     *   existe de que hay negocio. Un lead que nunca escribió es un contacto.
     * - `recency` (25): el interés se enfría. Un lead que escribió ayer no se
     *   parece en nada al mismo lead hace tres semanas.
     * - `stage_progress` (15): avanzar en el pipeline es progreso real medido
     *   por quien lo trabaja.
     * - `source_quality` (15): de dónde vino, ponderado por lo que esa fuente
     *   cerró históricamente en ESTA cuenta.
     * - `attention` (10): si nadie le contestó nunca, el lead se pierde por
     *   desatención y no por falta de interés — y eso es accionable.
     * - `momentum` (10): castiga el estancamiento en la misma etapa.
     */
    private const WEIGHTS = [
        'engagement' => 25,
        'recency' => 25,
        'stage_progress' => 15,
        'source_quality' => 15,
        'attention' => 10,
        'momentum' => 10,
    ];

    /** Cerrados mínimos para que la calibración signifique algo. */
    public const MIN_CLOSED_FOR_CALIBRATION = 200;

    /**
     * Leads abiertos mínimos para cortar bandas por percentil. Debajo de esto
     * los terciles son ruido (con 4 leads, «el tercio superior» es uno) y se
     * usan umbrales absolutos.
     */
    public const MIN_LEADS_FOR_PERCENTILE = 12;

    public const BAND_HOT = 'caliente';

    public const BAND_WARM = 'tibio';

    public const BAND_COLD = 'frio';

    /**
     * Puntúa un lead a partir de sus señales.
     *
     * @param  array<string, mixed>  $signals  salida de `LeadSignals`
     * @return array{score: int, factors: array<int, array<string, mixed>>}
     */
    public function score(array $signals): array
    {
        $factors = [
            $this->engagement($signals),
            $this->recency($signals),
            $this->stageProgress($signals),
            $this->sourceQuality($signals),
            $this->attention($signals),
            $this->momentum($signals),
        ];

        $total = array_sum(array_column($factors, 'points'));

        return [
            'score' => (int) max(0, min(100, round($total))),
            'factors' => $factors,
        ];
    }

    /** Cuánto escribió el cliente. Satura: 6 mensajes ya dicen lo mismo que 40. */
    private function engagement(array $s): array
    {
        $count = (int) $s['inbound_count'];
        $points = min(1, $count / 6) * self::WEIGHTS['engagement'];

        return $this->factor('engagement', 'Interés del cliente', $points, self::WEIGHTS['engagement'],
            $count === 0 ? 'Nunca escribió' : "{$count} mensajes recibidos");
    }

    /** Cuán reciente es el último mensaje del cliente. */
    private function recency(array $s): array
    {
        $days = $s['days_since_inbound'];

        if ($days === null) {
            return $this->factor('recency', 'Actividad reciente', 0, self::WEIGHTS['recency'], 'Sin mensajes del cliente');
        }

        // Plena hasta 2 días, cae linealmente y se agota a los 30.
        $freshness = $days <= 2 ? 1.0 : max(0, 1 - ($days - 2) / 28);
        $label = $days < 1 ? 'Escribió hoy' : 'Escribió hace '.round($days).' d';

        return $this->factor('recency', 'Actividad reciente', $freshness * self::WEIGHTS['recency'], self::WEIGHTS['recency'], $label);
    }

    private function stageProgress(array $s): array
    {
        $progress = $s['stage_progress'];

        if ($progress === null) {
            return $this->factor('stage_progress', 'Avance en el pipeline', 0, self::WEIGHTS['stage_progress'], 'Etapa sin posición');
        }

        return $this->factor('stage_progress', 'Avance en el pipeline',
            $progress * self::WEIGHTS['stage_progress'], self::WEIGHTS['stage_progress'],
            round($progress * 100).'% del recorrido');
    }

    /**
     * Calidad de la fuente. Sin historia suficiente se otorga la mitad del peso:
     * ni premia ni castiga a un lead por venir de un canal que todavía no se
     * puede juzgar.
     */
    private function sourceQuality(array $s): array
    {
        $rate = $s['source_win_rate'];
        $weight = self::WEIGHTS['source_quality'];

        if ($rate === null) {
            return $this->factor('source_quality', 'Calidad de la fuente', $weight / 2, $weight, 'Sin historia suficiente');
        }

        return $this->factor('source_quality', 'Calidad de la fuente', $rate * $weight, $weight,
            'Esta fuente cierra el '.round($rate * 100).'%');
    }

    /** ¿Lo atendió un humano alguna vez? */
    private function attention(array $s): array
    {
        $replies = (int) $s['human_replies'];
        $weight = self::WEIGHTS['attention'];

        // Sin mensajes entrantes no hay nada que atender: no se castiga.
        if ((int) $s['inbound_count'] === 0) {
            return $this->factor('attention', 'Atención recibida', $weight, $weight, 'Sin conversación');
        }

        return $replies > 0
            ? $this->factor('attention', 'Atención recibida', $weight, $weight, 'Atendido por el equipo')
            : $this->factor('attention', 'Atención recibida', 0, $weight, 'Escribió y nadie le contestó');
    }

    /** Estancamiento: plena bajo 7 días en la etapa, nula a los 30. */
    private function momentum(array $s): array
    {
        $days = (float) $s['days_in_stage'];
        $weight = self::WEIGHTS['momentum'];
        $value = $days <= 7 ? 1.0 : max(0, 1 - ($days - 7) / 23);

        return $this->factor('momentum', 'Ritmo', $value * $weight, $weight,
            $days <= 7 ? 'En movimiento' : 'Estancado hace '.round($days).' d en la etapa');
    }

    /** @return array<string, mixed> */
    private function factor(string $key, string $label, float $points, int $max, string $detail): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'points' => round($points, 1),
            'max' => $max,
            'detail' => $detail,
        ];
    }

    /**
     * Corta los scores en bandas.
     *
     * Por **terciles** cuando hay leads suficientes: la pregunta del asesor es
     * «¿a quién llamo primero?», y eso es un ranking relativo a su propia
     * cartera, no un umbral universal. Con pocos leads se cae a umbrales
     * absolutos porque un tercil sobre 5 leads no significa nada.
     *
     * @param  Collection<int, int>  $scores
     * @return array{hot: int, warm: int, mode: string}  umbrales inferiores
     */
    public function bands(Collection $scores): array
    {
        if ($scores->count() < self::MIN_LEADS_FOR_PERCENTILE) {
            return ['hot' => 66, 'warm' => 33, 'mode' => 'absoluto'];
        }

        $sorted = $scores->sort()->values();
        $at = fn (float $p) => (int) $sorted[(int) floor(($sorted->count() - 1) * $p)];

        return ['hot' => $at(2 / 3), 'warm' => $at(1 / 3), 'mode' => 'percentil'];
    }

    /** @param array{hot: int, warm: int} $bands */
    public function bandFor(int $score, array $bands): string
    {
        return match (true) {
            $score >= $bands['hot'] => self::BAND_HOT,
            $score >= $bands['warm'] => self::BAND_WARM,
            default => self::BAND_COLD,
        };
    }

    /**
     * Qué porcentaje de los leads YA CERRADOS de cada banda terminó ganado.
     *
     * Es la única afirmación honesta que este módulo puede hacer sobre
     * probabilidad, porque es medición y no predicción. Se calcula sobre los
     * cerrados que conservan `score_band` (los puntuados en su momento).
     *
     * @return array{calibrated: bool, closed: int, bands: array<string, array{won: int, total: int, rate: float}>}
     */
    public function calibration(string $accountId): array
    {
        $rows = Lead::forAccount($accountId)
            ->whereIn('status', ['won', 'lost'])
            ->whereNotNull('score_band')
            ->selectRaw("score_band,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END) as won")
            ->groupBy('score_band')
            ->get();

        $closed = (int) $rows->sum('total');

        return [
            'calibrated' => $closed >= self::MIN_CLOSED_FOR_CALIBRATION,
            'closed' => $closed,
            'bands' => $rows->mapWithKeys(fn ($r) => [$r->score_band => [
                'won' => (int) $r->won,
                'total' => (int) $r->total,
                'rate' => $r->total > 0 ? round($r->won / $r->total * 100, 1) : 0.0,
            ]])->all(),
        ];
    }
}
