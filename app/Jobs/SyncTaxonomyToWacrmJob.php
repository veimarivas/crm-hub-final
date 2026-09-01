<?php

namespace App\Jobs;

use App\Models\CustomField;
use App\Models\Integration;
use App\Models\Tag;
use App\Services\Wacrm\Client;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Replica en el wacrm el catálogo de etiquetas y campos personalizados de la
 * cuenta: Komo es la fuente de verdad de la taxonomía y el wacrm la espeja.
 *
 * Hasta D2 los dos proyectos tenían catálogos separados que NO se
 * sincronizaban —a diferencia de los pipelines, que sí— así que una etiqueta
 * puesta en el inbox no existía acá y viceversa.
 *
 * Lo dispara `TagController` y `CustomFieldController` ante cualquier cambio.
 * Corre en cola porque implica un HTTP al wacrm; los fallos se loguean sin
 * reintentar (mismo criterio que `SyncPipelinesToWacrmJob`: la próxima
 * modificación vuelve a mandar el catálogo COMPLETO, así que un envío perdido
 * se corrige solo).
 */
class SyncTaxonomyToWacrmJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $accountId) {}

    public function handle(): void
    {
        try {
            $this->sync();
        } catch (\Throwable $e) {
            Log::warning('Sync de taxonomía → wacrm falló', [
                'account_id' => $this->accountId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return array<string, mixed>  el informe del wacrm, o [] si no hay integración */
    public function sync(bool $dryRun = false): array
    {
        $integration = Integration::forAccount($this->accountId)->first();

        if (! $integration || ! $integration->wacrm_url || ! $integration->wacrm_api_key) {
            return [];
        }

        return Client::for($integration)->syncTaxonomy(
            self::tagPayload($this->accountId),
            self::customFieldPayload($this->accountId),
            $dryRun,
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function tagPayload(string $accountId): array
    {
        return Tag::forAccount($accountId)
            ->orderBy('name')
            ->get(['id', 'name', 'color'])
            ->map(fn (Tag $t) => ['id' => $t->id, 'name' => $t->name, 'color' => $t->color])
            ->values()
            ->all();
    }

    /**
     * **Solo los campos de contacto.** Los `custom_fields` del wacrm son
     * contact-only (cuelgan de `ContactCustomValue`), así que un campo de lead
     * o de empresa crearía allá una columna que nadie podría llenar nunca. El
     * recorte va acá porque este es el lado que sabe de entidades.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function customFieldPayload(string $accountId): array
    {
        return CustomField::forAccount($accountId)
            ->where('entity', 'contact')
            ->orderBy('position')
            ->get(['id', 'name', 'field_type', 'options'])
            ->map(fn (CustomField $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'field_type' => $f->field_type,
                'options' => $f->options,
            ])
            ->values()
            ->all();
    }
}
