<?php

namespace App\Services\Supervision;

use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\User;
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

    /**
     * @return array{agents: array<int, array<string, mixed>>, leads: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function build(): array
    {
        $leads = Lead::forAccount($this->accountId)
            ->with(['contact:id,name,phone', 'responsible:id,name', 'stage:id,name,color'])
            ->get(['id', 'title', 'contact_id', 'responsible_user_id', 'stage_id', 'status', 'created_at']);

        $perLead = $this->measureConversations($leads->pluck('id'));

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
        ];
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

                if ($event->event_type === 'message_in') {
                    $inbound++;
                    // Solo el primer mensaje de la ráfaga arranca el reloj: si el
                    // contacto manda cinco seguidos, esperó desde el primero.
                    $awaitingHumanSince ??= $event->created_at;

                    continue;
                }

                $isBot = ($event->payload['sender'] ?? 'agent') === 'bot';

                if ($isBot) {
                    $botReplies++;
                    $firstResponder ??= 'ia';

                    continue;
                }

                $humanReplies++;
                $firstResponder ??= $event->user_id ?? 'desconocido';

                // Un saliente humano sin espera abierta es un seguimiento
                // proactivo, no una respuesta: no entra en los promedios.
                if ($awaitingHumanSince !== null) {
                    $seconds = (int) $awaitingHumanSince->diffInSeconds($event->created_at, true);
                    $responseSeconds[] = $seconds;
                    $firstResponseSeconds ??= $seconds;
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
