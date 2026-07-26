<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Models\Lead;
use App\Models\User;
use App\Services\Wacrm\Client;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Espeja el responsable del lead en la conversacion del wacrm, para que
 * la conversacion aparezca en el Inbox del agente correcto (y no como
 * "Sin asignar").
 *
 * Es UN SOLO punto de sincronizacion usado por los dos caminos que
 * cambian el responsable:
 *   - Round-robin automatico al crearse el lead (Lead::booted).
 *   - Cambio manual del responsable en la ficha (LeadController@update).
 *
 * Corre en cola porque implica un HTTP al wacrm: el webhook entrante y
 * el guardado de la ficha responden al instante. Los fallos se loguean
 * sin reintentar — el comando `komo:sync-assignments` sirve de red de
 * seguridad para reparar desincronizaciones.
 *
 * Pasar responsable null desasigna la conversacion en el wacrm.
 */
class SyncLeadAssignmentToWacrmJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $leadId) {}

    /** En cola nunca reventamos: se loguea y `komo:sync-assignments` repara. */
    public function handle(): void
    {
        try {
            $this->sync();
        } catch (\Throwable $e) {
            Log::warning('Sync asignacion → wacrm fallo', [
                'lead_id' => $this->leadId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * El sync en si. Lanza si el wacrm rechaza — lo usa el comando de
     * reparacion, que necesita saber que leads quedaron desincronizados.
     */
    public function sync(): void
    {
        $lead = Lead::find($this->leadId);

        // El lead no vino de WhatsApp → no hay conversacion que asignar.
        if (! $lead || ! $lead->wacrm_conversation_id) {
            return;
        }

        $integration = Integration::forAccount($lead->account_id)->first();

        if (! $integration || ! $integration->wacrm_url || ! $integration->wacrm_api_key) {
            return;
        }

        // La correlacion entre ambos sistemas es por email: el agente debe
        // existir en el wacrm con el mismo email (lo garantiza la
        // auto-provision al aceptar la invitacion).
        $responsible = $lead->responsible_user_id
            ? User::find($lead->responsible_user_id)
            : null;
        $email = $responsible?->email;

        $client = Client::for($integration);

        try {
            $client->assignConversation($lead->wacrm_conversation_id, $email);

            return;
        } catch (\Throwable $e) {
            // Causa mas comun: el agente existe en Komo pero nunca se
            // provisiono en el wacrm (se creo antes de configurar la
            // integracion, o a mano). Sin responsable no hay nada que
            // provisionar — el fallo es real y se propaga.
            if (! $responsible) {
                throw $e;
            }
        }

        // Lo damos de alta alla y reintentamos una vez: si no, la conversacion
        // se queda "Sin asignar" para siempre.
        $client->provisionUser(
            email: $responsible->email,
            name: $responsible->name,
            role: $responsible->hasRoleAtLeast(User::ROLE_ADMIN) ? 'admin' : 'agent',
        );

        $client->assignConversation($lead->wacrm_conversation_id, $email);
    }
}
