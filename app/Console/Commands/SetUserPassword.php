<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Asigna la contraseña de un miembro desde la terminal.
 *
 * Hace falta porque los miembros que llegan por `wacrm:sync-team-to-komo` se
 * crean con una clave ALEATORIA: las del wacrm están hasheadas y no se pueden
 * reenviar. Y como el correo de producción todavía usa el driver `log`, el
 * "olvidé mi contraseña" no le llega a nadie.
 *
 * Uso:
 *   php artisan komo:set-password                       (lista los miembros)
 *   php artisan komo:set-password lucio@esam.edu.bo
 *   php artisan komo:set-password lucio@esam.edu.bo --password=Temporal2026
 */
class SetUserPassword extends Command
{
    protected $signature = 'komo:set-password
        {email? : Email del miembro; sin él, lista los miembros}
        {--password= : Contraseña a fijar (si no, se genera una)}';

    protected $description = 'Fija la contraseña de un miembro de Komo';

    public function handle(): int
    {
        $email = $this->argument('email');

        if (! $email) {
            $this->info('Miembros en esta instalación:');
            $this->newLine();
            foreach (User::orderBy('name')->get(['name', 'email', 'account_role']) as $u) {
                $this->line(sprintf('  %-32s %s (%s)', $u->email, $u->name, $u->account_role ?? 'sin rol'));
            }
            $this->newLine();
            $this->line('Después: php artisan komo:set-password EMAIL');

            return self::SUCCESS;
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No hay ningún miembro con el email {$email}.");
            $this->line('Corré el comando sin argumentos para ver la lista.');

            return self::FAILURE;
        }

        // Sin --password se genera una: es preferible a repetir una clave
        // conocida, y evita elegir algo débil por comodidad.
        $password = $this->option('password') ?: Str::password(14, symbols: false);

        $user->forceFill(['password' => Hash::make($password)])->save();

        $this->newLine();
        $this->info("Contraseña actualizada para {$user->name} <{$user->email}>");
        $this->newLine();
        $this->line('  <options=bold>'.$password.'</>');
        $this->newLine();
        $this->warn('Pasásela por un canal seguro y pedile que la cambie al entrar.');

        return self::SUCCESS;
    }
}
