<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Models\Lead;
use App\Services\Wacrm\Client;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Espeja la etapa del lead en el deal del wacrm, para que la columna de
 * /pipelines coincida con el kanban del Komo (que es la fuente de verdad
 * del pipeline).
 *
 * Es el único punto de sincronización de etapa: lo dispara
 * Lead::moveToStage (el único camino que cambia la etapa).
 *
 * Corre en cola porque implica un HTTP al wacrm: el drag & drop del kanban
 * responde al instante. Los fallos se loguean sin reintentar.
 */
class SyncLeadStageToWacrmJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $leadId) {}

    public function handle(): void
    {
        try {
            $this->sync();
        } catch (\Throwable $e) {
            Log::warning('Sync etapa → wacrm falló', [
                'lead_id' => $this->leadId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function sync(): void
    {
        $lead = Lead::find($this->leadId);

        // El lead no vino de WhatsApp → no hay deal que mover.
        if (! $lead || ! $lead->wacrm_conversation_id || ! $lead->stage) {
            return;
        }

        $integration = Integration::forAccount($lead->account_id)->first();

        if (! $integration || ! $integration->wacrm_url || ! $integration->wacrm_api_key) {
            return;
        }

        Client::for($integration)->setConversationStage(
            $lead->wacrm_conversation_id,
            $lead->stage->name,
            $lead->status,
        );
    }
}
