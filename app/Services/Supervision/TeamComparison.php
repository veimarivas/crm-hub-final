<?php

namespace App\Services\Supervision;

use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Comparativas de equipo para `/supervision`: lo que `ResponseMetrics` mide
 * contacto por contacto, aquí se lee agregado y comparado.
 *
 * Vive aparte a propósito. `ResponseMetrics` es el GEMELO del wacrm y sus
 * definiciones están fijadas por `SupervisionMetricsTest`; tocarlo obligaría a
 * replicar el cambio allá. Esta clase solo *consume* esas definiciones:
 *
 *  - el reloj arranca en el primer mensaje de la ráfaga del contacto,
 *  - una respuesta de la IA no cierra la espera,
 *  - un saliente humano sin espera abierta es seguimiento proactivo y no cuenta
 *    como respuesta.
 *
 * Cuatro lecturas que la tabla no da:
 *  - `responseByAgent`: mediana de primera respuesta por responsable. Mediana y
 *    no promedio: un solo caso olvidado tres días le arruina el promedio a
 *    quien contesta bien el resto del tiempo.
 *  - `slaDaily`: % de respuestas dentro del objetivo, día a día.
 *  - `heatmap`: cuándo escriben los clientes (hora × día).
 *  - `backlog`: hace cuánto esperan los que hoy siguen sin respuesta humana.
 */
class TeamComparison
{
    /** Baldes de antigüedad del backlog, en horas (`max` null = sin techo). */
    private const BACKLOG_BUCKETS = [
        ['name' => '< 1 h', 'max' => 1, 'color' => '#10b981'],
        ['name' => '1–4 h', 'max' => 4, 'color' => '#34d399'],
        ['name' => '4–12 h', 'max' => 12, 'color' => '#f59e0b'],
        ['name' => '12–24 h', 'max' => 24, 'color' => '#f97316'],
        ['name' => '1–3 d', 'max' => 72, 'color' => '#e11d48'],
        ['name' => '> 3 d', 'max' => null, 'color' => '#9f1239'],
    ];

    public function __construct(
        private readonly string $accountId,
        private readonly CarbonInterface $since,
    ) {}

    /**
     * @return array{responseByAgent: array<int, mixed>, slaDaily: array<int, mixed>, heatmap: array<int, mixed>, backlog: array<int, mixed>, teamDaily: array<int, mixed>, slaMinutes: int}
     */
    public function build(): array
    {
        [$responses, $inbound] = $this->walkWindow();

        return [
            'responseByAgent' => $this->medianByAgent($responses),
            'slaDaily' => $this->slaDaily($responses),
            'teamDaily' => $this->teamDaily($responses),
            'heatmap' => $this->heatmap($inbound),
            'backlog' => $this->backlog(),
            'slaMinutes' => ResponseMetrics::SLA_MINUTES,
        ];
    }

    /**
     * Promedio diario del equipo, para superponerlo a las series de un agente
     * en su ficha. Se expone suelto porque la ficha no necesita el resto.
     *
     * @return array<int, array{date: string, team_avg_response_seconds: int|null}>
     */
    public function teamDailyAverage(): array
    {
        [$responses] = $this->walkWindow();

        return $this->teamDaily($responses);
    }

    /**
     * Recorre los mensajes del periodo una sola vez y devuelve:
     *  - las respuestas humanas medidas `[agente, segundos, momento]`,
     *  - los entrantes contados por día de semana y hora.
     *
     * @return array{0: Collection<int, array{agent: ?string, seconds: int, at: CarbonInterface}>, 1: array<int, array<int, int>>}
     */
    private function walkWindow(): array
    {
        $responsibleByLead = Lead::forAccount($this->accountId)->pluck('responsible_user_id', 'id');

        $timelines = LeadEvent::forAccount($this->accountId)
            ->whereIn('event_type', ['message_in', 'message_out'])
            ->where('created_at', '>=', $this->since)
            ->orderBy('created_at')
            ->get(['lead_id', 'user_id', 'event_type', 'payload', 'created_at'])
            ->groupBy('lead_id');

        $responses = collect();
        $inbound = [];

        foreach ($timelines as $leadId => $timeline) {
            $awaitingSince = null;

            foreach ($timeline as $event) {
                if ($event->event_type === 'message_in') {
                    $day = $event->created_at->dayOfWeekIso; // 1 = lunes
                    $inbound[$day][$event->created_at->hour] = ($inbound[$day][$event->created_at->hour] ?? 0) + 1;
                    $awaitingSince ??= $event->created_at;

                    continue;
                }

                // La IA no cierra la espera: gana tiempo, no atiende.
                if (($event->payload['sender'] ?? 'agent') === 'bot') {
                    continue;
                }

                if ($awaitingSince === null) {
                    continue; // seguimiento proactivo
                }

                $responses->push([
                    'agent' => $responsibleByLead[$leadId] ?? null,
                    'seconds' => (int) $awaitingSince->diffInSeconds($event->created_at, true),
                    'at' => $event->created_at,
                ]);
                $awaitingSince = null;
            }
        }

        return [$responses, $inbound];
    }

    /**
     * Mediana de respuesta por responsable, en minutos, ordenada de la más
     * rápida a la más lenta. Alimenta el CompareBars horizontal con la línea
     * de SLA.
     *
     * @param  Collection<int, array<string, mixed>>  $responses
     * @return array<int, array<string, mixed>>
     */
    private function medianByAgent(Collection $responses): array
    {
        $names = User::where('account_id', $this->accountId)->pluck('name', 'id');

        return $responses
            ->groupBy(fn (array $r) => $r['agent'] ?? 'none')
            ->map(fn (Collection $group, string $agentId) => [
                'id' => $agentId === 'none' ? null : $agentId,
                'name' => $agentId === 'none' ? 'Sin responsable' : ($names[$agentId] ?? 'Usuario retirado'),
                'value' => round($this->median($group->pluck('seconds')) / 60, 1),
                'samples' => $group->count(),
            ])
            ->sortBy('value')
            ->values()
            ->all();
    }

    /**
     * Cumplimiento diario del SLA: % de respuestas dentro del objetivo. Los
     * días sin respuestas van con `pct` null para que la línea no baje a cero
     * por un fin de semana sin tráfico.
     *
     * @param  Collection<int, array<string, mixed>>  $responses
     * @return array<int, array<string, mixed>>
     */
    private function slaDaily(Collection $responses): array
    {
        $limit = ResponseMetrics::SLA_MINUTES * 60;
        $byDay = $responses->groupBy(fn (array $r) => $r['at']->format('Y-m-d'));

        return $this->eachDay(function (CarbonInterface $day, string $key) use ($byDay, $limit) {
            $group = $byDay[$key] ?? collect();
            $within = $group->filter(fn (array $r) => $r['seconds'] <= $limit)->count();

            return [
                'date' => $key,
                'label' => $day->translatedFormat('d M'),
                'total' => $group->count(),
                'within' => $within,
                'pct' => $group->count() > 0 ? round($within / $group->count() * 100, 1) : null,
            ];
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $responses
     * @return array<int, array{date: string, team_avg_response_seconds: int|null}>
     */
    private function teamDaily(Collection $responses): array
    {
        $byDay = $responses->groupBy(fn (array $r) => $r['at']->format('Y-m-d'));

        return $this->eachDay(fn (CarbonInterface $day, string $key) => [
            'date' => $key,
            'team_avg_response_seconds' => isset($byDay[$key]) && $byDay[$key]->isNotEmpty()
                ? (int) round($byDay[$key]->avg('seconds'))
                : null,
        ]);
    }

    /**
     * Entrantes por hora y día de semana. Formato de `HeatmapGrid`: una fila
     * por día con 24 celdas.
     *
     * @param  array<int, array<int, int>>  $inbound
     * @return array<int, array{label: string, hours: array<int, int>}>
     */
    private function heatmap(array $inbound): array
    {
        $labels = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

        return collect(range(1, 7))
            ->map(fn (int $iso) => [
                'label' => $labels[$iso - 1],
                'hours' => collect(range(0, 23))
                    ->map(fn (int $hour) => (int) ($inbound[$iso][$hour] ?? 0))
                    ->all(),
            ])
            ->all();
    }

    /**
     * Antigüedad del backlog: leads abiertos cuyo último mensaje es del
     * contacto (nadie humano contestó todavía), agrupados por cuánto llevan
     * esperando. Es el AHORA, así que no se recorta a la ventana.
     *
     * @return array<int, array{name: string, value: int, color: string}>
     */
    private function backlog(): array
    {
        $openLeadIds = Lead::forAccount($this->accountId)->where('status', 'open')->pluck('id');

        if ($openLeadIds->isEmpty()) {
            return [];
        }

        $timelines = LeadEvent::forAccount($this->accountId)
            ->whereIn('lead_id', $openLeadIds)
            ->whereIn('event_type', ['message_in', 'message_out'])
            ->orderBy('created_at')
            ->get(['lead_id', 'event_type', 'payload', 'created_at'])
            ->groupBy('lead_id');

        $waits = [];

        foreach ($timelines as $timeline) {
            $awaitingSince = null;

            foreach ($timeline as $event) {
                if ($event->event_type === 'message_in') {
                    $awaitingSince ??= $event->created_at;

                    continue;
                }

                if (($event->payload['sender'] ?? 'agent') === 'bot') {
                    continue;
                }

                $awaitingSince = null;
            }

            if ($awaitingSince !== null) {
                $waits[] = $awaitingSince->diffInMinutes(now(), true) / 60;
            }
        }

        $lower = 0;
        $out = [];

        foreach (self::BACKLOG_BUCKETS as $bucket) {
            $max = $bucket['max'];
            $out[] = [
                'name' => $bucket['name'],
                'value' => collect($waits)
                    ->filter(fn (float $hours) => $hours >= $lower && ($max === null || $hours < $max))
                    ->count(),
                'color' => $bucket['color'],
            ];
            $lower = $max ?? $lower;
        }

        return collect($out)->filter(fn (array $b) => $b['value'] > 0)->values()->all();
    }

    /**
     * Recorre día a día la ventana y arma una fila por cada uno. Los huecos
     * rellenados evitan que el eje mienta (mismo criterio que `dailySeries`).
     *
     * @return array<int, mixed>
     */
    private function eachDay(callable $row): array
    {
        $out = [];
        $cursor = $this->since->copy()->startOfDay();
        $end = now()->startOfDay();

        while ($cursor <= $end) {
            $out[] = $row($cursor, $cursor->format('Y-m-d'));
            $cursor = $cursor->copy()->addDay();
        }

        return $out;
    }

    /** @param Collection<int, int> $values */
    private function median(Collection $values): float
    {
        $sorted = $values->sort()->values();
        $count = $sorted->count();

        if ($count === 0) {
            return 0.0;
        }

        $mid = intdiv($count, 2);

        return $count % 2 === 1
            ? (float) $sorted[$mid]
            : ($sorted[$mid - 1] + $sorted[$mid]) / 2;
    }
}
