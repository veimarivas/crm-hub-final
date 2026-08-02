<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Models\Pipeline;
use App\Services\Wacrm\Client;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Replica en el wacrm la estructura completa de pipelines/etapas de la
 * cuenta: Komo es la fuente de verdad de las columnas y el wacrm las espeja
 * en /pipelines.
 *
 * Lo dispara PipelineController ante cualquier cambio en /settings/pipelines
 * (crear/renombrar/borrar pipeline, etapas y reordenar). Corre en cola porque
 * implica un HTTP al wacrm; los fallos se loguean sin reintentar.
 */
class SyncPipelinesToWacrmJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $accountId) {}

    public function handle(): void
    {
        try {
            $this->sync();
        } catch (\Throwable $e) {
            Log::warning('Sync de pipelines → wacrm falló', [
                'account_id' => $this->accountId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function sync(): void
    {
        $integration = Integration::forAccount($this->accountId)->first();

        if (! $integration || ! $integration->wacrm_url || ! $integration->wacrm_api_key) {
            return;
        }

        $pipelines = Pipeline::forAccount($this->accountId)
            ->with(['stages' => fn ($q) => $q->orderBy('position')])
            ->orderBy('created_at')
            ->get()
            ->map(fn (Pipeline $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'is_default' => (bool) $p->is_default,
                'stages' => $p->stages->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'position' => $s->position,
                    'color' => $s->color,
                    'stage_type' => $s->stage_type,
                ])->values()->all(),
            ])
            ->values()
            ->all();

        Client::for($integration)->syncPipelines($pipelines);
    }
}
