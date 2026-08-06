<?php

namespace App\Services\Supervision;

use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\Task;
use App\Models\User;
use App\Services\WhatsApp\ServiceWindow;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Métricas de seguimiento para el admin: qué está pasando con los contactos
 * de cada responsable.
 *
 * Todo sale de `lead_events` (message_in / message_out), que es el espejo en
 * Komo de la conversación de WhatsApp. No hace falta consultar al wacrm.
 *
 * Definiciones — importan para leer bien los números:
 *
 *  - «Esperando»: el contacto escribió y todavía NO contestó un humano. Una
 *    respuesta de la IA NO cierra la espera: para el admin lo relevante es si
 *    el responsable atendió, y la IA solo gana tiempo. (Por eso se lleva
 *    `awaitingHuman` aparte de la actividad general.)
 *  - «Tiempo de respuesta»: segundos entre que el contacto escribe (el primer
 *    mensaje de la ráfaga, no el último) y el primer mensaje humano posterior.
 *    Los mensajes seguidos del contacto no reinician el reloj.
 *  - «Primera respuesta»: el primero de esos tiempos en la conversación.
 *  - «Quién respondió primero»: quién mandó el primer saliente tras el primer
 *    entrante — la IA, el responsable del lead, u otro miembro del equipo.
 *
 * La ventana temporal recorta los eventos: una conversación que arrancó antes
 * del periodo se mide solo por lo que ocurrió dentro de él.
 */
class ResponseMetrics
{
    /** Minutos sin respuesta humana a partir de los cuales el lead se marca en rojo. */
    public const SLA_MINUTES = 30;

    public function __construct(
        private readonly string $accountId,
        private readonly CarbonInterface $since,
    ) {}

    /** Acumuladores por día (Y-m-d), llenados durante el recorrido de mensajes. */
    private array $daily = [];

    /** Ventana de servicio de WhatsApp por lead, indexada por lead_id. */
    private array $windows = [];

    /**
     * @return array{agents: array<int, mixed>, leads: array<int, mixed>, totals: array<string, mixed>, daily: array<int, mixed>, stages: array<int, mixed>}
     */
    public function build(): array
    {
        $leads = Lead::forAccount($this->accountId)
            ->with(['contact:id,name,phone', 'responsible:id,name', 'stage:id,name,color'])
            ->get(['id', 'title', 'contact_id', 'responsible_user_id', 'stage_id', 'status', 'created_at']);

        $perLead = $this->measureConversations($leads->pluck('id'));

        // Ventana de WhatsApp por lead: en la tabla contacto-por-contacto dice
        // si todavía se le puede escribir sin costo, que es lo que decide si
        // el admin reclama la respuesta ahora o ya da igual.
        $windows = app(ServiceWindow::class)->forLeads($leads);
        $this->windows = $windows;

        // Solo interesan los leads con conversación dentro del periodo.
        $rows = $leads
            ->filter(fn (Lead $lead) => isset($perLead[$lead->id]))
            ->map(fn (Lead $lead) => $this->leadRow($lead, $perLead[$lead->id]))
            ->sortByDesc(fn ($row) => $row['awaiting_minutes'] ?? -1)
            ->values();

        return [
            'agents' => $this->aggregateByAgent($rows, $leads),
            'leads' => $rows->all(),
            'totals' => $this->totals($rows),
            'daily' => $this->dailySeries(),
            'stages' => $this->stageDistribution($rows),
        ];
    }

    /**
     * Drill-down de UN responsable (la «ficha» hasta ahora pendiente): KPIs,
     * histograma de tiempos, embudo de sus leads abiertos y los pendientes
     * operativos (esperando respuesta / ventana cerrada).
     *
     * Reusa los mismos recorridos que `build()` para que un número signifique
     * lo mismo a nivel equipo que a nivel responsable.
     *
     * @return array{agent: array<string, mixed>, kpis: array<string, mixed>, histogram: array<int, array<string, mixed>>, daily: array<int, array<string, mixed>>, leads: array<int, array<string, mixed>>}
     */
    public function forResponsible(string $userId): array
    {
        $agent = User::where('account_id', $this->accountId)->findOrFail($userId);

        $leads = Lead::forAccount($this->accountId)
            ->where('responsible_user_id', $agent->id)
            ->with(['contact:id,name,phone', 'responsible:id,name', 'stage:id,name,color'])
            ->get(['id', 'title', 'contact_id', 'responsible_user_id', 'stage_id', 'status', 'created_at']);

        $perLead = $this->measureConversations($leads->pluck('id'));

        $this->windows = app(ServiceWindow::class)->forLeads($leads);

        $rows = $leads
            ->filter(fn (Lead $lead) => isset($perLead[$lead->id]))
            ->map(fn (Lead $lead) => $this->leadRow($lead, $perLead[$lead->id]))
            ->sortByDesc(fn ($row) => $row['awaiting_minutes'] ?? -1)
            ->values();

        $kpis = collect($this->aggregateByAgent($rows, $leads))
            ->firstWhere('id', $agent->id);

        return [
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'role' => $agent->account_role,
                'email' => $agent->email,
            ],
            'kpis' => $kpis ?? [
                'name' => $agent->name,
                'role' => $agent->account_role,
                'leads' => 0,
                'open_leads' => 0,
                'answered' => 0,
                'never_answered' => 0,
                'waiting_now' => 0,
                'breached_sla' => 0,
                'avg_first_response_seconds' => null,
                'avg_response_seconds' => null,
                'slowest_response_seconds' => null,
                'ia_first' => 0,
                'responsible_first' => 0,
                'other_agent_first' => 0,
                'unknown_first' => 0,
                'messages_sent' => 0,
                'messages_received' => 0,
                'last_activity_at' => null,
                'by_stage' => [],
            ],
            // Cierre del ciclo: ganados/perdidos/conversión en el periodo.
            'conversion' => $this->conversionFor($agent->id),
            'histogram' => $this->histogram($rows),
            'daily' => $this->dailySeries(),
            'leads' => $rows->all(),
            // Lo que hay que atender AHORA, sin ventana de tiempo.
            'operatives' => $this->operativesFor($agent->id),
        ];
    }

    /**
     * Ganados / perdidos / conversión / ticket promedio del responsable en el
     * periodo, cerrados sobre `closed_at` (para no contar lo que se ganó en
     * otra ventana).
     *
     * @return array{won: int, lost: int, conversion: int, avg_value_cents: int|null}
     */
    private function conversionFor(string $userId): array
    {
        $closed = Lead::forAccount($this->accountId)
            ->where('responsible_user_id', $userId)
            ->whereIn('status', ['won', 'lost'])
            ->where('closed_at', '>=', $this->since)
            ->get(['status', 'value']);

        $won = $closed->where('status', 'won');
        $lost = $closed->where('status', 'lost');

        return [
            'won' => $won->count(),
            'lost' => $lost->count(),
            'conversion' => $won->count() + $lost->count() > 0
                ? (int) round(($won->count() / ($won->count() + $lost->count())) * 100) : 0,
            'avg_value' => $won->count() > 0
                ? (int) round($won->avg('value')) : null,
        ];
    }

    /**
     * Pendientes operativos del agente (el AHORA, lo que diferencia ver de
     * actuar): esperando respuesta humana, ventana de servicio cerrada,
     * tareas vencidas y leads estancados en la misma etapa.
     *
     * @return array{pending: array<int, mixed>, tasks: int, stagnant: int}
     */
    private function operativesFor(string $userId): array
    {
        $leads = Lead::forAccount($this->accountId)
            ->where('responsible_user_id', $userId)
            ->with(['contact:id,name,phone', 'stage:id,name,color'])
            ->get(['id', 'title', 'contact_id', 'stage_id', 'status', 'created_at']);

        $windows = app(ServiceWindow::class)->forLeads($leads);

        $stillOpen = $leads->where('status', 'open');
        // Leads con mensajes en espera: su último evento es un message_in.
        $waitingIds = LeadEvent::forAccount($this->accountId)
            ->whereIn('lead_id', $stillOpen->pluck('id'))
            ->whereIn('event_type', ['message_in', 'message_out'])
            ->orderByDesc('created_at')
            ->get(['lead_id', 'event_type', 'created_at'])
            ->unique('lead_id')
            ->where('event_type', 'message_in')
            ->pluck('lead_id')
            ->all();

        $pending = [];

        foreach ($leads as $lead) {
            $window = $windows[$lead->id] ?? null;
            $windowClosed = ($window['source'] ?? 'none') !== 'none' && ! ($window['is_open'] ?? false);

            // Estancado: abierto y sin cambiar de etapa hace más de 7 días (el
            // primer stage_changed del lead banca la fecha base).
            $lastChange = $lead->events()
                ->where('event_type', 'stage_changed')
                ->latest('created_at')->value('created_at') ?? $lead->created_at;
            $stagnant = $lead->status === 'open' && $lastChange->lt(now()->subDays(7));

            $labels = [];
            if (in_array($lead->id, $waitingIds, true)) {
                $labels[] = 'Esperando respuesta';
            }
            if ($windowClosed) {
                $labels[] = 'Ventana cerrada';
            }
            if ($stagnant) {
                $labels[] = 'Estancado';
            }

            if ($labels !== []) {
                $pending[] = [
                    'lead_id' => $lead->id,
                    'title' => $lead->title,
                    'contact' => $lead->contact?->name ?? $lead->title,
                    'phone' => $lead->contact?->phone,
                    'stage' => $lead->stage ? ['name' => $lead->stage->name, 'color' => $lead->stage->color] : null,
                    'window' => $window,
                    'labels' => $labels,
                ];
            }
        }

        return [
            'pending' => $pending,
            'tasks' => Task::forAccount($this->accountId)
                ->whereHas('lead', fn ($q) => $q->where('responsible_user_id', $userId))
                ->pending()->overdue()->count(),
        ];
    }

    /**
     * Distribución de los tiempos de primera respuesta en baldes.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array{label: string, count: int}>
     */
    private function histogram(Collection $rows): array
    {
        $buckets = [
            ['label' => 'Menos de 1 m', 'min' => null, 'max' => 60],
            ['label' => '1 a 5 m', 'min' => 60, 'max' => 300],
            ['label' => '5 a 15 m', 'min' => 300, 'max' => 900],
            ['label' => '15 a 30 m', 'min' => 900, 'max' => 1800],
            ['label' => '30 m a 1 h', 'min' => 1800, 'max' => 3600],
            ['label' => 'Más de 1 h', 'min' => 3600, 'max' => null],
        ];

        $counts = array_fill(0, count($buckets), 0);

        foreach ($rows as $row) {
            $seconds = $row['first_response_seconds'];
            if ($seconds === null) {
                continue;
            }
            foreach ($buckets as $i => $bucket) {
                if (($bucket['min'] === null || $seconds >= $bucket['min'])
                    && ($bucket['max'] === null || $seconds < $bucket['max'])) {
                    $counts[$i]++;
                    break;
                }
            }
        }

        return collect($buckets)
            ->map(fn (array $b, int $i) => ['label' => $b['label'], 'count' => $counts[$i]])
            ->values()
            ->all();
    }

    /**
     * Serie por día para las gráficas. Rellena los días sin actividad con
     * ceros — si no, el eje miente: un hueco se leería como continuidad.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dailySeries(): array
    {
        $out = [];
        $cursor = $this->since->copy()->startOfDay();
        $end = now()->startOfDay();

        while ($cursor <= $end) {
            $key = $cursor->format('Y-m-d');
            $bucket = $this->daily[$key] ?? [];
            $responses = $bucket['responses'] ?? [];

            $out[] = [
                'date' => $key,
                'label' => $cursor->translatedFormat('d M'),
                'inbound' => $bucket['inbound'] ?? 0,
                'human_replies' => $bucket['human'] ?? 0,
                'bot_replies' => $bucket['bot'] ?? 0,
                'avg_response_seconds' => $responses
                    ? (int) round(array_sum($responses) / count($responses))
                    : null,
            ];

            $cursor = $cursor->addDay();
        }

        return $out;
    }

    /**
     * Reparto de las conversaciones del periodo por etapa. Contesta «en qué
     * proceso está cada contacto» sin tener que abrir responsable por
     * responsable.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function stageDistribution(Collection $rows): array
    {
        return $rows
            ->groupBy(fn ($r) => $r['stage']['name'] ?? 'Sin etapa')
            ->map(fn (Collection $group, string $name) => [
                'name' => $name,
                'color' => $group->first()['stage']['color'] ?? null,
                'count' => $group->count(),
                'waiting' => $group->filter(fn ($r) => $r['awaiting_minutes'] !== null)->count(),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * Recorre los mensajes de cada lead en orden y saca sus tiempos.
     *
     * @param  Collection<int, string>  $leadIds
     * @return array<string, array<string, mixed>>
     */
    private function measureConversations(Collection $leadIds): array
    {
        $events = LeadEvent::forAccount($this->accountId)
            ->whereIn('lead_id', $leadIds)
            ->whereIn('event_type', ['message_in', 'message_out'])
            ->where('created_at', '>=', $this->since)
            ->orderBy('created_at')
            ->get(['lead_id', 'user_id', 'event_type', 'payload', 'created_at'])
            ->groupBy('lead_id');

        $out = [];

        foreach ($events as $leadId => $timeline) {
            $awaitingHumanSince = null;
            $inbound = 0;
            $humanReplies = 0;
            $botReplies = 0;
            $responseSeconds = [];
            $firstResponseSeconds = null;
            $firstResponder = null;      // 'ia' | User id | 'desconocido'
            $lastAt = null;

            foreach ($timeline as $event) {
                $lastAt = $event->created_at;
                $day = $event->created_at->format('Y-m-d');

                if ($event->event_type === 'message_in') {
                    $inbound++;
                    $this->daily[$day]['inbound'] = ($this->daily[$day]['inbound'] ?? 0) + 1;
                    // Solo el primer mensaje de la ráfaga arranca el reloj: si el
                    // contacto manda cinco seguidos, esperó desde el primero.
                    $awaitingHumanSince ??= $event->created_at;

                    continue;
                }

                $isBot = ($event->payload['sender'] ?? 'agent') === 'bot';

                if ($isBot) {
                    $botReplies++;
                    $this->daily[$day]['bot'] = ($this->daily[$day]['bot'] ?? 0) + 1;
                    $firstResponder ??= 'ia';

                    continue;
                }

                $humanReplies++;
                $this->daily[$day]['human'] = ($this->daily[$day]['human'] ?? 0) + 1;
                $firstResponder ??= $event->user_id ?? 'desconocido';

                // Un saliente humano sin espera abierta es un seguimiento
                // proactivo, no una respuesta: no entra en los promedios.
                if ($awaitingHumanSince !== null) {
                    $seconds = (int) $awaitingHumanSince->diffInSeconds($event->created_at, true);
                    $responseSeconds[] = $seconds;
                    $firstResponseSeconds ??= $seconds;
                    // La respuesta se imputa al día en que se dio, no al día en
                    // que entró el mensaje: así la gráfica muestra el desempeño
                    // del turno que efectivamente atendió.
                    $this->daily[$day]['responses'][] = $seconds;
                    $awaitingHumanSince = null;
                }
            }

            $out[$leadId] = [
                'inbound' => $inbound,
                'human_replies' => $humanReplies,
                'bot_replies' => $botReplies,
                'response_seconds' => $responseSeconds,
                'first_response_seconds' => $firstResponseSeconds,
                'first_responder' => $firstResponder,
                'awaiting_since' => $awaitingHumanSince,
                'last_at' => $lastAt,
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $m */
    private function leadRow(Lead $lead, array $m): array
    {
        $awaitingMinutes = $m['awaiting_since']
            ? (int) $m['awaiting_since']->diffInMinutes(now(), true)
            : null;

        return [
            'id' => $lead->id,
            'title' => $lead->title,
            'contact' => $lead->contact?->name ?? $lead->title,
            'phone' => $lead->contact?->phone,
            'status' => $lead->status,
            'stage' => $lead->stage ? ['name' => $lead->stage->name, 'color' => $lead->stage->color] : null,
            'responsible' => $lead->responsible
                ? ['id' => $lead->responsible->id, 'name' => $lead->responsible->name]
                : null,
            'inbound' => $m['inbound'],
            'human_replies' => $m['human_replies'],
            'bot_replies' => $m['bot_replies'],
            'first_response_seconds' => $m['first_response_seconds'],
            'avg_response_seconds' => $m['response_seconds']
                ? (int) round(array_sum($m['response_seconds']) / count($m['response_seconds']))
                : null,
            'slowest_response_seconds' => $m['response_seconds'] ? max($m['response_seconds']) : null,
            'first_responder' => $this->classifyFirstResponder($lead, $m['first_responder']),
            'awaiting_minutes' => $awaitingMinutes,
            'breached_sla' => $awaitingMinutes !== null && $awaitingMinutes >= self::SLA_MINUTES,
            'service_window' => $this->windows[$lead->id] ?? null,
            'last_activity_at' => $m['last_at'],
        ];
    }

    /**
     * 'ia' | 'responsable' | 'otro_agente' | 'sin_identificar' | 'sin_respuesta'.
     * Distinguir «responsable» de «otro agente» es lo que deja ver si el dueño
     * del lead realmente lo está trabajando o se lo están cubriendo.
     */
    private function classifyFirstResponder(Lead $lead, mixed $firstResponder): string
    {
        return match (true) {
            $firstResponder === null => 'sin_respuesta',
            $firstResponder === 'ia' => 'ia',
            $firstResponder === 'desconocido' => 'sin_identificar',
            $firstResponder === $lead->responsible_user_id => 'responsable',
            default => 'otro_agente',
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  Collection<int, Lead>  $leads
     * @return array<int, array<string, mixed>>
     */
    private function aggregateByAgent(Collection $rows, Collection $leads): array
    {
        $members = User::where('account_id', $this->accountId)
            ->orderBy('name')
            ->get(['id', 'name', 'account_role']);

        // Los leads sin responsable se reportan como una fila más: son
        // justamente los que nadie está trabajando.
        $buckets = $members->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'role' => $u->account_role,
        ])->push(['id' => null, 'name' => 'Sin responsable', 'role' => null]);

        return $buckets
            ->map(function (array $bucket) use ($rows, $leads) {
                $mine = $rows->filter(fn ($r) => ($r['responsible']['id'] ?? null) === $bucket['id']);

                if ($mine->isEmpty()) {
                    return null;
                }

                $firstResponses = $mine->pluck('first_response_seconds')->filter(fn ($s) => $s !== null);
                $avgResponses = $mine->pluck('avg_response_seconds')->filter(fn ($s) => $s !== null);

                $openLeads = $leads->filter(fn (Lead $l) => $l->responsible_user_id === $bucket['id'] && $l->status === 'open');

                return [
                    ...$bucket,
                    'leads' => $mine->count(),
                    'open_leads' => $openLeads->count(),
                    'answered' => $mine->filter(fn ($r) => $r['human_replies'] > 0)->count(),
                    'never_answered' => $mine->filter(fn ($r) => $r['human_replies'] === 0)->count(),
                    'waiting_now' => $mine->filter(fn ($r) => $r['awaiting_minutes'] !== null)->count(),
                    'breached_sla' => $mine->filter(fn ($r) => $r['breached_sla'])->count(),
                    'avg_first_response_seconds' => $firstResponses->isNotEmpty()
                        ? (int) round($firstResponses->avg()) : null,
                    'avg_response_seconds' => $avgResponses->isNotEmpty()
                        ? (int) round($avgResponses->avg()) : null,
                    'slowest_response_seconds' => $mine->pluck('slowest_response_seconds')
                        ->filter(fn ($s) => $s !== null)->max(),
                    'ia_first' => $mine->filter(fn ($r) => $r['first_responder'] === 'ia')->count(),
                    'responsible_first' => $mine->filter(fn ($r) => $r['first_responder'] === 'responsable')->count(),
                    'other_agent_first' => $mine->filter(fn ($r) => $r['first_responder'] === 'otro_agente')->count(),
                    // Salientes previos a que el wacrm mandara sender_email: se
                    // sabe que contestó un humano, pero no cuál.
                    'unknown_first' => $mine->filter(fn ($r) => $r['first_responder'] === 'sin_identificar')->count(),
                    'messages_sent' => $mine->sum('human_replies'),
                    'messages_received' => $mine->sum('inbound'),
                    'last_activity_at' => $mine->pluck('last_activity_at')->filter()->max(),
                    'by_stage' => $openLeads
                        ->groupBy(fn (Lead $l) => $l->stage?->name ?? 'Sin etapa')
                        ->map(fn ($group, $name) => [
                            'name' => $name,
                            'color' => $group->first()->stage?->color,
                            'count' => $group->count(),
                        ])->values()->all(),
                ];
            })
            ->filter()
            ->sortByDesc('breached_sla')
            ->values()
            ->all();
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function totals(Collection $rows): array
    {
        $firstResponses = $rows->pluck('first_response_seconds')->filter(fn ($s) => $s !== null);

        return [
            'leads' => $rows->count(),
            'waiting_now' => $rows->filter(fn ($r) => $r['awaiting_minutes'] !== null)->count(),
            'breached_sla' => $rows->filter(fn ($r) => $r['breached_sla'])->count(),
            'never_answered' => $rows->filter(fn ($r) => $r['human_replies'] === 0)->count(),
            'avg_first_response_seconds' => $firstResponses->isNotEmpty()
                ? (int) round($firstResponses->avg()) : null,
            'ia_first' => $rows->filter(fn ($r) => $r['first_responder'] === 'ia')->count(),
            'human_first' => $rows->filter(fn ($r) => in_array($r['first_responder'], ['responsable', 'otro_agente', 'sin_identificar'], true))->count(),
            'sla_minutes' => self::SLA_MINUTES,
        ];
    }
}
