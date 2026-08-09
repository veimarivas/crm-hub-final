<?php

namespace App\Services\Leads;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;

/**
 * Adaptador de los filtros planos de la query string (`/leads?tag=…&source=…`)
 * al formato de segmento que evalúa `SegmentQuery`.
 *
 * Desde T4 **no traduce a SQL por su cuenta**: normaliza, y delega. Mantener
 * dos traductores es exactamente lo que hizo que una lista guardada
 * seleccionara distinto en `/leads` que en un envío. Acá vive el contrato de
 * la query string; en `SegmentQuery` vive el de las condiciones.
 *
 * Antes esta cadena estaba escrita tres veces —`LeadController@index`,
 * `LeadController@export` y `BroadcastController@recipientPhones`— y ya había
 * divergido: el listado entendía `stage_id` y los broadcasts no; los broadcasts
 * entendían `tags[]` e `include_closed` y el listado no. Como
 * `saved_segments.filters` guarda ese mismo JSON, **una lista guardada desde
 * `/leads` con filtro de etapa se ignoraba en silencio al usarla en un envío**:
 * el usuario veía 12 leads en pantalla y le llegaba el mensaje a 300.
 *
 * El scope de rol vive acá y no en cada llamador **a propósito**: es el corte
 * que impide que un agente escriba a la cartera de otro, y un corte que hay que
 * acordarse de repetir es un corte que algún día se olvida.
 */
class LeadFilter
{
    /**
     * Contrato de `filters`. Una clave fuera de esta lista es un error, no algo
     * que se ignora: ignorar en silencio es exactamente cómo se produjo la
     * divergencia que esta clase viene a arreglar.
     *
     * - `responsible`  id de usuario | 'none' (sin responsable). Solo admin.
     * - `tag`          id de etiqueta (forma vieja, la guardan los segmentos).
     * - `tags`         array de ids de etiqueta (OR entre ellas).
     * - `source`       origen del lead (`whatsapp`, `booking`, `manual`…).
     * - `stage_id`     id de etapa del pipeline.
     * - `pipeline_id`  id de pipeline.
     * - `no_task`      bool: solo leads sin tarea pendiente (regla Kommo).
     * - `q`            texto libre: título, nombre o teléfono del contacto.
     * - `include_closed` bool: incluir ganados/perdidos cuando el llamador
     *                    pide solo abiertos (ver `$openOnly` en `apply()`).
     */
    public const KEYS = [
        'responsible', 'tag', 'tags', 'source', 'stage_id',
        'pipeline_id', 'no_task', 'q', 'include_closed',
    ];

    public function __construct(private readonly User $user) {}

    public static function for(User $user): self
    {
        return new self($user);
    }

    /**
     * Normaliza filtros crudos (query string o `saved_segments.filters`) al
     * contrato: descarta vacíos, castea booleanos y unifica `tag`/`tags`.
     *
     * Los vacíos se descartan acá y no en `apply()` porque `''`, `null` y `0`
     * llegan mezclados según vengan de la URL, de un JSON guardado o del form
     * de broadcasts, y cada llamador los estaba interpretando a su manera.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public static function normalize(array $raw): array
    {
        $out = [];

        foreach ($raw as $key => $value) {
            if (! in_array($key, self::KEYS, true)) {
                throw new InvalidArgumentException("Filtro de leads desconocido: «{$key}».");
            }

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $out[$key] = match ($key) {
                'no_task', 'include_closed' => filter_var($value, FILTER_VALIDATE_BOOL),
                'q' => trim((string) $value),
                'tags' => array_values(array_filter((array) $value)),
                default => $value,
            };
        }

        // `tag` (una) y `tags` (varias) son el mismo criterio: se unifican para
        // que el resto del código tenga un solo camino.
        $tags = array_merge($out['tags'] ?? [], array_filter([$out['tag'] ?? null]));
        unset($out['tag'], $out['tags']);

        if ($tags !== []) {
            $out['tags'] = array_values(array_unique($tags));
        }

        // Un `q` que quedó en blanco tras el trim no es un filtro.
        if (isset($out['q']) && $out['q'] === '') {
            unset($out['q']);
        }

        // `false` no filtra nada en ninguno de los dos casos.
        foreach (['no_task', 'include_closed'] as $flag) {
            if (isset($out[$flag]) && $out[$flag] === false) {
                unset($out[$flag]);
            }
        }

        return $out;
    }

    /**
     * Aplica scope de rol + filtros sobre una consulta de leads.
     *
     * Acepta `Builder` y también una relación (`$pipeline->leads()`), que es lo
     * que pasa `/leads`: la relación reenvía todos los métodos que se usan acá.
     *
     * @param  Builder<\App\Models\Lead>|Relation<\App\Models\Lead, *, *>  $query
     * @param  array<string, mixed>  $filters  crudos; se normalizan acá
     * @param  bool  $openOnly  el llamador solo quiere leads abiertos salvo que
     *                          los filtros pidan `include_closed` (los envíos
     *                          masivos: escribirle a un lead ya cerrado es un
     *                          error caro). El tablero de leads pasa `false`
     *                          porque muestra ganados y perdidos a propósito.
     * @return Builder<\App\Models\Lead>|Relation<\App\Models\Lead, *, *>
     */
    public function apply(Builder|Relation $query, array $filters, bool $openOnly = false): Builder|Relation
    {
        return SegmentQuery::for($this->user)->apply($query, self::normalize($filters), $openOnly);
    }
}
