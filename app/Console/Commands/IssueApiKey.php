<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\ApiKey;
use Illuminate\Console\Command;

/**
 * Genera una API key desde la terminal.
 *
 * La UI ya permite crearlas (Ajustes → Equipo → API Keys), pero cuando se
 * está configurando la integración desde el servidor es más práctico —y
 * menos propenso a errores de copiado— sacarla por acá y pegarla directo en
 * el `.env` del otro proyecto.
 *
 * La clave en claro se muestra UNA sola vez: en la base solo queda su hash.
 *
 * Uso:
 *   php artisan komo:api-key --list
 *   php artisan komo:api-key "wacrm" --account=UUID --scopes=team:write
 */
class IssueApiKey extends Command
{
    protected $signature = 'komo:api-key
        {name? : Nombre para identificarla (ej. "wacrm")}
        {--account= : UUID de la cuenta}
        {--scopes=team:write : Scopes separados por coma}
        {--list : Solo lista las cuentas con su UUID}';

    protected $description = 'Genera una API key de la API pública de Komo';

    public function handle(): int
    {
        $accounts = Account::orderBy('name')->get(['id', 'name']);

        if ($accounts->isEmpty()) {
            $this->error('No hay cuentas en esta instalación.');

            return self::FAILURE;
        }

        if ($this->option('list') || ! $this->argument('name')) {
            $this->info('Cuentas disponibles:');
            $this->newLine();
            foreach ($accounts as $account) {
                $this->line("  {$account->id}  {$account->name}");
            }
            $this->newLine();
            $this->line('Después: php artisan komo:api-key "wacrm" --account=UUID');

            return self::SUCCESS;
        }

        // Con una sola cuenta no tiene sentido obligar a escribir el UUID.
        $accountId = $this->option('account') ?: ($accounts->count() === 1 ? $accounts->first()->id : null);

        if (! $accountId) {
            $this->error('Hay varias cuentas: indicá cuál con --account=UUID.');
            $this->newLine();
            foreach ($accounts as $account) {
                $this->line("  {$account->id}  {$account->name}");
            }

            return self::FAILURE;
        }

        $account = $accounts->firstWhere('id', $accountId);

        if (! $account) {
            $this->error("No existe la cuenta {$accountId}.");

            return self::FAILURE;
        }

        $scopes = collect(explode(',', (string) $this->option('scopes')))
            ->map(fn (string $s) => trim($s))
            ->filter()
            ->values()
            ->all();

        $invalidos = array_diff($scopes, ApiKey::SCOPES);

        if ($invalidos !== []) {
            $this->error('Scopes inválidos: '.implode(', ', $invalidos));
            $this->line('Disponibles: '.implode(', ', ApiKey::SCOPES));

            return self::FAILURE;
        }

        [, $plaintext] = ApiKey::issue($account->id, null, $this->argument('name'), $scopes);

        $this->newLine();
        $this->info("API key creada para «{$account->name}» con scopes: ".implode(', ', $scopes));
        $this->newLine();
        $this->line('  <options=bold>'.$plaintext.'</>');
        $this->newLine();
        $this->warn('Se muestra UNA sola vez: en la base solo queda su hash.');
        $this->newLine();
        $this->line('Para el .env del crm-whatsapp:');
        $this->line('  KOMO_URL='.rtrim(config('app.url'), '/'));
        $this->line('  KOMO_API_KEY='.$plaintext);

        return self::SUCCESS;
    }
}
