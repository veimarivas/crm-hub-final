<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Integration;
use App\Services\Wacrm\AiStatusProbe;
use App\Services\Wacrm\Client;
use Illuminate\Console\Command;

/**
 * Por qué el indicador de IA del header dice lo que dice.
 *
 * El badge tiene una etiqueta y nada más; cuando marca un problema hay que
 * saber cuál de los tres es (URL, key/scope, o el wacrm) sin ponerse a leer
 * logs. Este comando lo dice en una corrida, sin caché de por medio.
 */
class DiagnoseAiStatus extends Command
{
    protected $signature = 'komo:ai-status {--account= : UUID de la cuenta (por defecto, todas)}';

    protected $description = 'Diagnostica el indicador de IA: integración, API key, scopes y respuesta del wacrm.';

    public function handle(AiStatusProbe $probe): int
    {
        $accounts = Account::query()
            ->when($this->option('account'), fn ($q, $id) => $q->where('id', $id))
            ->orderBy('created_at')
            ->get();

        if ($accounts->isEmpty()) {
            $this->error('No hay cuentas'.($this->option('account') ? ' con ese UUID.' : '.'));

            return self::FAILURE;
        }

        $problemas = 0;

        foreach ($accounts as $account) {
            $this->newLine();
            $this->line("<options=bold>{$account->name}</> <fg=gray>({$account->id})</>");

            $integration = Integration::forAccount($account->id)->first();

            if (! $integration || ! $integration->wacrm_url || ! $integration->wacrm_api_key) {
                $this->warn('  Sin integración con el wacrm: el header no muestra nada de IA (esto no es un error).');
                $this->line('  Se cablea en Ajustes → Integración: URL del wacrm + API key.');

                continue;
            }

            $this->line("  URL: {$integration->wacrm_url}");

            // Primero /me: separa "la key no sirve" de "la key no alcanza para
            // este endpoint". Si /me pasa y /ai/status no, es cuestión de scope.
            try {
                $me = Client::for($integration)->me();
                $scopes = $me['key']['scopes'] ?? [];
                $this->info('  API key: válida — scopes: '.(empty($scopes) ? '(ninguno)' : implode(', ', $scopes)));

                if (! in_array('conversations:read', $scopes, true)) {
                    $this->warn('  Le falta el scope conversations:read, que es el que pide /ai/status.');
                }
            } catch (\Throwable $e) {
                $this->error('  API key: NO válida — '.$e->getMessage());
            }

            $status = $probe->probe($integration);

            if ($status['available'] ?? false) {
                $this->info("  IA: disponible ({$status['provider']} / {$status['model']}).");

                continue;
            }

            $problemas++;
            $motivo = $status['reason'] ?? 'desconocido';
            $this->error("  IA: no disponible — motivo «{$motivo}»".
                (isset($status['http_status']) && $status['http_status'] ? " (HTTP {$status['http_status']})" : ''));

            if (isset($status['detail'])) {
                $this->line("  <fg=gray>{$status['detail']}</>");
            }

            $this->line('  → '.match ($motivo) {
                'unauthorized' => 'La API key no tiene scope conversations:read o fue revocada. Generá una nueva en el wacrm (Ajustes → Equipo) y pegala en Ajustes → Integración.',
                'not_supported' => 'Ese wacrm no expone /api/v1/ai/status: desplegalo con la versión que lo trae.',
                'unreachable' => 'No se llegó al wacrm: revisá la URL, el DNS y que el sitio esté levantado desde ESTE servidor (curl -I '.$integration->wacrm_url.').',
                'provider_down' => 'El wacrm responde pero su modelo no: revisá Ollama en el servidor del wacrm (systemctl status ollama).',
                'not_configured' => 'El wacrm no tiene IA configurada en esa cuenta: Ajustes → IA del wacrm.',
                'inactive', 'auto_reply_off' => 'La IA está apagada a propósito en el wacrm. No hay nada roto.',
                'after_hours' => 'Estamos fuera del horario de atención configurado en el wacrm. No hay nada roto.',
                default => 'Motivo no reconocido; revisá el log del wacrm.',
            });
        }

        $this->newLine();

        return $problemas > 0 ? self::FAILURE : self::SUCCESS;
    }
}
