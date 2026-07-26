<?php

namespace App\Console\Commands;

use App\Jobs\SyncLeadAssignmentToWacrmJob;
use App\Models\Integration;
use App\Models\Lead;
use Illuminate\Console\Command;

/**
 * Recorre todos los leads con responsable asignado + wacrm_conversation_id
 * y sincroniza la asignación en el Inbox del wacrm. Idempotente — reasigna
 * lo mismo si ya coincide, no rompe.
 *
 * Uso: php artisan komo:sync-assignments
 */
class SyncAssignmentsToWacrm extends Command
{
    protected $signature = 'komo:sync-assignments {--account= : UUID de la cuenta (opcional; sin él sincroniza todas)}';

    protected $description = 'Espeja al wacrm la asignación de responsable de cada lead que vino de WhatsApp';

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

            $leads = Lead::forAccount($integration->account_id)
                ->whereNotNull('wacrm_conversation_id')
                ->whereNotNull('responsible_user_id')
                ->get(['id', 'title', 'wacrm_conversation_id', 'responsible_user_id']);

            $bar = $this->output->createProgressBar($leads->count());
            $bar->start();

            foreach ($leads as $lead) {
                $total++;

                // Mismo camino que el sync en vivo (incluye el auto-provisionado
                // del agente en el wacrm si aún no existe allá): una sola
                // implementación, sin riesgo de que las dos se desalineen.
                try {
                    (new SyncLeadAssignmentToWacrmJob($lead->id))->sync();
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
