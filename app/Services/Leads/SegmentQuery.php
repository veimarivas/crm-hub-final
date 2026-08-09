<?php

namespace App\Services\Leads;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;

/**
 * Evaluador de definiciones de segmento: un árbol de condiciones con grupos
 * Y/O, traducido a una sola consulta SQL.
 *
 * ## Qué significa «dinámico»
 *
 * Un segmento **no es una lista de leads, es una pregunta**. Se guarda la
 * pregunta y se contesta cada vez que se usa, así que un lead que ayer no
 * calificaba y hoy sí, entra solo. Lo que sigue congelándose es el envío: quién
 * recibió qué es un hecho histórico, no una consulta (por eso
 * `broadcast_recipients` sigue siendo una foto del momento del envío).
 *
 * ## Un solo evaluador
 *
 * Este es el **único** lugar donde una condición se convierte en SQL.
 * `LeadFilter` quedó como adaptador de los filtros planos de la query string:
 * los normaliza, los sube a árbol y delega acá. Tener dos traductores es
 * exactamente lo que hizo que una lista guardada seleccionara distinto en
 * `/leads` que en un envío (ver T0 en CLAUDE_komo.md).
 *
 * ## Formato
 *
 * ```json
 * {
 *   "version": 2,
 *   "match": "all",
 *   "conditions": [
 *     {"field": "stage_id", "op": "in", "value": ["uuid"]},
 *     {"match": "any", "conditions": [
 *        {"field": "last_inbound", "op": "older_than", "value": 7},
 *        {"field": "has_pending_task", "op": "is", "value": false}
 *     ]}
 *   ]
 * }
 * ```
 *
 * `version` existe desde el día uno para poder cambiar el formato sin romper
 * las listas ya guardadas: la v1 (plana) se sube a v2 al leerla.
 */
class SegmentQuery
{
    public const VERSION = 2;

    /**
     * Campos disponibles y sus operadores.
     *
     * **`service_window_open` no está y es a propósito**: la ventana de
     * servicio se calcula en PHP a partir de eventos y no es expresable en
     * SQL. Filtrar por ella obligaría a traer todo y descartar en memoria,
     * que rompe el modelo de «un segmento es una consulta» y la paginación.
     * La pantalla de envío ya muestra quién está dentro y fuera de ventana, y
     * el costo de escribirle a los de afuera.
     */
    public const FIELDS = [
        // --- Atributos ---
        'stage_id' => ['label' => 'Etapa', 'group' => 'Atributos', 'type' => 'stage', 'ops' => ['in', 'not_in']],
        'pipeline_id' => ['label' => 'Pipeline', 'group' => 'Atributos', 'type' => 'pipeline', 'ops' => ['in']],
        'status' => ['label' => 'Estado', 'group' => 'Atributos', 'type' => 'status', 'ops' => ['in', 'not_in']],
        'source' => ['label' => 'Fuente', 'group' => 'Atributos', 'type' => 'source', 'ops' => ['in', 'not_in']],
        'responsible_user_id' => ['label' => 'Responsable', 'group' => 'Atributos', 'type' => 'user', 'ops' => ['in', 'not_in', 'is_empty']],
        'tag_id' => ['label' => 'Etiqueta', 'group' => 'Atributos', 'type' => 'tag', 'ops' => ['in', 'not_in']],
        'company_id' => ['label' => 'Empresa', 'group' => 'Atributos', 'type' => 'company', 'ops' => ['in', 'is_empty']],
        'value' => ['label' => 'Valor', 'group' => 'Atributos', 'type' => 'number', 'ops' => ['gte', 'lte']],
        'title' => ['label' => 'Título o contacto', 'group' => 'Atributos', 'type' => 'text', 'ops' => ['contains']],

        // --- Marketing ---
        'utm_source' => ['label' => 'UTM source', 'group' => 'Marketing', 'type' => 'text', 'ops' => ['eq', 'contains', 'is_empty']],
        'utm_campaign' => ['label' => 'UTM campaign', 'group' => 'Marketing', 'type' => 'text', 'ops' => ['eq', 'contains', 'is_empty']],

        // --- Comportamiento ---
        'last_inbound' => ['label' => 'Último mensaje del cliente', 'group' => 'Comportamiento', 'type' => 'days', 'ops' => ['older_than', 'newer_than', 'never']],
        'inbound_count' => ['label' => 'Mensajes recibidos', 'group' => 'Comportamiento', 'type' => 'number', 'ops' => ['gte', 'lte']],
        'human_replied' => ['label' => 'Le contestó un humano', 'group' => 'Comportamiento', 'type' => 'bool', 'ops' => ['is']],
        'has_pending_task' => ['label' => 'Tiene tarea pendiente', 'group' => 'Comportamiento', 'type' => 'bool', 'ops' => ['is']],

        // --- Copiloto ---
        'score' => ['label' => 'Puntaje del copiloto', 'group' => 'Copiloto', 'type' => 'number', 'ops' => ['gte', 'lte']],
        'score_band' => ['label' => 'Banda', 'group' => 'Copiloto', 'type' => 'band', 'ops' => ['in', 'not_in']],
        'score_cooled' => ['label' => 'Se enfrió', 'group' => 'Copiloto', 'type' => 'bool', 'ops' => ['is']],

        // --- Tiempo ---
        'created_at' => ['label' => 'Creado', 'group' => 'Tiempo', 'type' => 'days', 'ops' => ['newer_than', 'older_than']],
        'closed_at' => ['label' => 'Cerrado', 'group' => 'Tiempo', 'type' => 'days', 'ops' => ['newer_than', 'older_than']],
    ];

    public const MATCH_ALL = 'all';

    public const MATCH_ANY = 'any';

    /** Profundidad máxima de anidado; sin tope, un JSON armado a mano cuelga el evaluador. */
    private const MAX_DEPTH = 4;

    public function __construct(private readonly User $user) {}

    public static function for(User $user): self
    {
        return new self($user);
    }

    /**
     * Sube una definición vieja al formato vigente.
     *
     * La v1 era el JSON plano de `LeadFilter` (`{tags, responsible, source,
     * stage_id, no_task, q, include_closed, pipeline_id}`). Las listas
     * guardadas antes de T4 lo tienen, y **tienen que seguir funcionando**:
     * migrar la columna en la base habría dejado sin listas a quien no
     * corriera la migración de datos.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public static function upgrade(array $definition): array
    {
        if (($definition['version'] ?? 1) >= self::VERSION) {
            return $definition;
        }

        $flat = LeadFilter::normalize(array_diff_key($definition, ['version' => null]));
        $conditions = [];

        foreach ($flat as $key => $value) {
            $conditions[] = match ($key) {
                'tags' => ['field' => 'tag_id', 'op' => 'in', 'value' => $value],
                'stage_id' => ['field' => 'stage_id', 'op' => 'in', 'value' => [$value]],
                'pipeline_id' => ['field' => 'pipeline_id', 'op' => 'in', 'value' => [$value]],
                'source' => ['field' => 'source', 'op' => 'in', 'value' => [$value]],
                'responsible' => $value === 'none'
                    ? ['field' => 'responsible_user_id', 'op' => 'is_empty', 'value' => true]
                    : ['field' => 'responsible_user_id', 'op' => 'in', 'value' => [$value]],
                'no_task' => ['field' => 'has_pending_task', 'op' => 'is', 'value' => false],
                'q' => ['field' => 'title', 'op' => 'contains', 'value' => $value],
                // `include_closed` no era un criterio sino una opción del
                // llamador: sigue viajando aparte, no como condición.
                'include_closed' => null,
                default => null,
            };
        }

        return [
            'version' => self::VERSION,
            'match' => self::MATCH_ALL,
            'conditions' => array_values(array_filter($conditions)),
            'include_closed' => (bool) ($flat['include_closed'] ?? false),
        ];
    }

    /**
     * Valida una definición y devuelve su forma canónica. Lanza si algo no
     * cuadra: un criterio que no se entiende se ignoraría en silencio y el
     * usuario mandaría un broadcast a una audiencia distinta de la que vio.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public static function validate(array $definition): array
    {
        $definition = self::upgrade($definition);

        return [
            'version' => self::VERSION,
            'match' => self::matchMode($definition),
            'conditions' => self::validateConditions($definition['conditions'] ?? [], 1),
            'include_closed' => (bool) ($definition['include_closed'] ?? false),
        ];
    }

    /**
     * Aplica la definición a una consulta de leads, con el scope de rol.
     *
     * @param  Builder<\App\Models\Lead>|Relation<\App\Models\Lead, *, *>  $query
     * @param  array<string, mixed>  $definition
     */
    public function apply(Builder|Relation $query, array $definition, bool $openOnly = false): Builder|Relation
    {
        $definition = self::validate($definition);
        $isAdmin = $this->user->hasRoleAtLeast(User::ROLE_ADMIN);

        // El corte del agente: su cartera y nada más. Va antes que cualquier
        // condición y no es negociable desde la definición.
        $query->when(! $isAdmin, fn ($q) => $q->where('responsible_user_id', $this->user->id));

        if ($definition['conditions'] !== []) {
            $query->where(fn ($q) => $this->applyGroup($q, $definition, $isAdmin));
        }

        if ($openOnly && ! $definition['include_closed']) {
            $query->where('status', 'open');
        }

        return $query;
    }

    /** @param array<string, mixed> $group */
    private function applyGroup(mixed $query, array $group, bool $isAdmin): void
    {
        $boolean = self::matchMode($group) === self::MATCH_ANY ? 'or' : 'and';

        foreach ($group['conditions'] as $node) {
            $apply = isset($node['conditions'])
                ? fn ($q) => $this->applyGroup($q, $node, $isAdmin)
                : fn ($q) => $this->applyCondition($q, $node, $isAdmin);

            $boolean === 'or' ? $query->orWhere($apply) : $query->where($apply);
        }
    }

    /** @param array<string, mixed> $c */
    private function applyCondition(mixed $query, array $c, bool $isAdmin): void
    {
        [$field, $op, $value] = [$c['field'], $c['op'], $c['value'] ?? null];

        // Elegir responsable es del admin. Para un agente la condición se
        // descarta en vez de aplicarse: combinada con su corte de rol daría
        // una lista vacía, que se lee como «no hay leads» y no como «eso no es
        // tuyo». Mismo criterio que LeadFilter.
        if ($field === 'responsible_user_id' && ! $isAdmin) {
            return;
        }

        match ($field) {
            'stage_id', 'pipeline_id', 'status', 'source', 'company_id', 'score_band' => $this->applySet($query, $field, $op, $value),
            'responsible_user_id' => $op === 'is_empty'
                ? $query->whereNull('responsible_user_id')
                : $this->applySet($query, $field, $op, $value),
            'tag_id' => $op === 'not_in'
                ? $query->whereDoesntHave('tags', fn ($q) => $q->whereIn('tags.id', (array) $value))
                : $query->whereHas('tags', fn ($q) => $q->whereIn('tags.id', (array) $value)),
            'value', 'score' => $query->where($field, $op === 'gte' ? '>=' : '<=', $value),
            'title' => $query->where(fn ($q) => $q
                ->where('title', 'like', "%{$value}%")
                ->orWhereHas('contact', fn ($cq) => $cq
                    ->where('name', 'like', "%{$value}%")
                    ->orWhere('phone', 'like', "%{$value}%")
                    ->orWhere('phone_normalized', 'like', "%{$value}%"))),
            'utm_source', 'utm_campaign' => match ($op) {
                'is_empty' => $query->where(fn ($q) => $q->whereNull($field)->orWhere($field, '')),
                'contains' => $query->where($field, 'like', "%{$value}%"),
                default => $query->where($field, $value),
            },
            'created_at', 'closed_at' => $op === 'newer_than'
                ? $query->where($field, '>=', now()->subDays((int) $value))
                : $query->where($field, '<', now()->subDays((int) $value)),
            'has_pending_task' => $value
                ? $query->whereHas('tasks', fn ($q) => $q->whereNull('completed_at'))
                : $query->whereDoesntHave('tasks', fn ($q) => $q->whereNull('completed_at')),
            'human_replied' => $this->applyHumanReplied($query, (bool) $value),
            'last_inbound' => $this->applyLastInbound($query, $op, $value),
            'inbound_count' => $this->applyInboundCount($query, $op, (int) $value),
            'score_cooled' => $this->applyCooled($query, (bool) $value),
            default => throw new InvalidArgumentException("Criterio sin implementar: «{$field}»."),
        };
    }

    private function applySet(mixed $query, string $field, string $op, mixed $value): void
    {
        $values = array_values(array_filter((array) $value, fn ($v) => $v !== null && $v !== ''));

        if ($values === []) {
            return;
        }

        $op === 'not_in' ? $query->whereNotIn($field, $values) : $query->whereIn($field, $values);
    }

    /**
     * Un saliente humano cualquiera. Misma convención que el resto del sistema:
     * `payload.sender = 'bot'` es la IA, todo lo demás es una persona.
     */
    private function applyHumanReplied(mixed $query, bool $expected): void
    {
        $has = fn ($q) => $q->where('event_type', 'message_out')
            ->whereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.sender')), 'agent') <> 'bot'");

        $expected ? $query->whereHas('events', $has) : $query->whereDoesntHave('events', $has);
    }

    /**
     * `older_than` incluye a los que **nunca** escribieron: «hace más de 30
     * días que no sé nada de este lead» es verdad también cuando nunca dijo
     * nada, y excluirlos dejaría afuera justo a los más abandonados.
     */
    private function applyLastInbound(mixed $query, string $op, mixed $value): void
    {
        $inbound = fn ($q) => $q->where('event_type', 'message_in');

        if ($op === 'never') {
            $query->whereDoesntHave('events', $inbound);

            return;
        }

        $since = now()->subDays((int) $value);
        $recent = fn ($q) => $inbound($q)->where('created_at', '>=', $since);

        $op === 'newer_than'
            ? $query->whereHas('events', $recent)
            : $query->whereDoesntHave('events', $recent);
    }

    private function applyInboundCount(mixed $query, string $op, int $value): void
    {
        $inbound = fn ($q) => $q->where('event_type', 'message_in');

        // `lte 0` es «ninguno»: `has(..., '<=', 0)` no matchea a los que no
        // tienen la relación, así que hay que preguntarlo al revés.
        if ($op === 'lte' && $value === 0) {
            $query->whereDoesntHave('events', $inbound);

            return;
        }

        $query->whereHas('events', $inbound, $op === 'gte' ? '>=' : '<=', $value);
    }

    /** Cayó de banda: `score_band_previous` era mejor que la actual. */
    private function applyCooled(mixed $query, bool $expected): void
    {
        $rank = "FIELD(score_band, 'frio', 'tibio', 'caliente')";
        $rankPrev = "FIELD(score_band_previous, 'frio', 'tibio', 'caliente')";

        $expected
            ? $query->whereNotNull('score_band_previous')->whereRaw("{$rank} < {$rankPrev}")
            : $query->where(fn ($q) => $q->whereNull('score_band_previous')->orWhereRaw("{$rank} >= {$rankPrev}"));
    }

    // ---- Validación ----

    /** @param array<int, mixed> $conditions */
    private static function validateConditions(array $conditions, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('El segmento tiene demasiados niveles anidados.');
        }

        return collect($conditions)->map(function ($node) use ($depth) {
            if (! is_array($node)) {
                throw new InvalidArgumentException('Condición mal formada.');
            }

            if (isset($node['conditions'])) {
                return [
                    'match' => self::matchMode($node),
                    'conditions' => self::validateConditions($node['conditions'], $depth + 1),
                ];
            }

            $field = $node['field'] ?? null;
            $definition = self::FIELDS[$field] ?? null;

            if (! $definition) {
                throw new InvalidArgumentException("Criterio de segmento desconocido: «{$field}».");
            }

            $op = $node['op'] ?? null;

            if (! in_array($op, $definition['ops'], true)) {
                throw new InvalidArgumentException("El criterio «{$definition['label']}» no admite el operador «{$op}».");
            }

            return ['field' => $field, 'op' => $op, 'value' => $node['value'] ?? null];
        })->values()->all();
    }

    private static function matchMode(array $group): string
    {
        return ($group['match'] ?? self::MATCH_ALL) === self::MATCH_ANY
            ? self::MATCH_ANY
            : self::MATCH_ALL;
    }

    /**
     * Catálogo de criterios para el constructor visual.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function catalog(): array
    {
        return collect(self::FIELDS)
            ->map(fn ($d, $key) => [
                'field' => $key,
                'label' => $d['label'],
                'group' => $d['group'],
                'type' => $d['type'],
                'ops' => $d['ops'],
            ])
            ->values()
            ->all();
    }
}
