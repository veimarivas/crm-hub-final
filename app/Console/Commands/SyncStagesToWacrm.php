<?php

namespace App\Console\Commands;

use App\Jobs\SyncLeadStageToWacrmJob;
use App\Jobs\SyncPipelinesToWacrmJob;
use App\Models\Integration;
use App\Models\Lead;
use Illuminate\Console\Command;

/**
 * Re-sincroniza hacia el wacrm la estructura de pipelines/etapas y la etapa
 * de cada lead con conversación de WhatsApp. Idempotente — si ya coincide no
 * rompe.
 *
 * Útil la primera vez que se integra la estructura (para alinear las columnas
 * de /pipelines con el kanban de /leads) o para reparar desalineaciones.
 *
 * Uso: php artisan komo:sync-stages
 */
class SyncStagesToWacrm extends Command
{
    protected $signature = 'komo:sync-stages {--account= : UUID de la cuenta (opcional; sin él sincroniza todas)}';

    protected $description = 'Espeja al wacrm la etapa de cada lead que vino de WhatsApp';

    public function handle(): int
    {
        $accountId = $this->option('account');

        $integrations = Integration::query()
            ->when($accountId, fn ($q) => $q->where('account_id', $accountId))
            ->get()
            ->filter(fn (Integration $i) => $i->wacrm_url && $i->wacrm_api_key);

        if ($integrations->isEmpty()) {
            $this->warn('No hay integraciones con wacrm configuradas.');

            return self::SUCCESS;
        }

        $total = 0;
        $ok = 0;
        $fail = 0;

        foreach ($integrations as $integration) {
            $this->info("Cuenta {$integration->account_id}");

            // Primero la estructura de columnas, después la etapa de cada lead.
            try {
                (new SyncPipelinesToWacrmJob($integration->account_id))->sync();
                $this->info('  Estructura de pipelines sincronizada.');
            } catch (\Throwable $e) {
                $this->error("  Estructura: {$e->getMessage()}");
            }

            $leads = Lead::forAccount($integration->account_id)
                ->whereNotNull('wacrm_conversation_id')
                ->whereNotNull('stage_id')
                ->get(['id', 'title', 'wacrm_conversation_id', 'stage_id']);

            $bar = $this->output->createProgressBar($leads->count());
            $bar->start();

            foreach ($leads as $lead) {
                $total++;

                // Mismo camino que el sync en vivo (Lead::moveToStage): una
                // sola implementación, sin riesgo de que se desalineen.
                try {
                    (new SyncLeadStageToWacrmJob($lead->id))->sync();
                    $ok++;
                } catch (\Throwable $e) {
                    $fail++;
                    $this->newLine();
                    $this->error("  Lead {$lead->title}: {$e->getMessage()}");
                }
                $bar->advance();
            }
            $bar->finish();
            $this->newLine(2);
        }

        $this->info("Sincronización terminada: {$ok}/{$total} OK, {$fail} fallos.");

        return self::SUCCESS;
    }
}
