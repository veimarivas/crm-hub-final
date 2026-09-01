<?php

namespace App\Console\Commands;

use App\Jobs\SyncTaxonomyToWacrmJob;
use App\Models\Integration;
use Illuminate\Console\Command;

/**
 * Replica al wacrm el catálogo de etiquetas y campos personalizados.
 *
 * **Este comando existe sobre todo por la PRIMERA pasada.** Después el sync lo
 * dispara solo cada cambio en `/tags` y `/settings/custom-fields`; pero la
 * primera vez los dos proyectos tienen catálogos preexistentes que nunca se
 * hablaron, y la reconciliación puede enlazar etiquetas por nombre, renombrar
 * y borrar. Eso no se corre a ciegas:
 *
 *     php artisan komo:sync-taxonomy --dry-run     # qué haría
 *     php artisan komo:sync-taxonomy               # hacerlo
 *
 * `--dry-run` es la opción por la que este comando no es solo un atajo del job.
 */
class SyncTaxonomyToWacrm extends Command
{
    protected $signature = 'komo:sync-taxonomy
        {--account= : UUID de la cuenta (opcional; sin él sincroniza todas)}
        {--dry-run : Informa qué haría del otro lado sin tocar nada}';

    protected $description = 'Espeja al wacrm las etiquetas y los campos personalizados de contacto';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $integrations = Integration::query()
            ->when($this->option('account'), fn ($q, $id) => $q->where('account_id', $id))
            ->get()
            ->filter(fn (Integration $i) => $i->wacrm_url && $i->wacrm_api_key);

        if ($integrations->isEmpty()) {
            $this->warn('No hay integraciones con wacrm configuradas.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info('— Simulación: no se toca nada del otro lado —');
        }

        $huboProblema = false;

        foreach ($integrations as $integration) {
            $this->line("\nCuenta {$integration->account_id}");

            try {
                $report = (new SyncTaxonomyToWacrmJob($integration->account_id))->sync($dryRun);
            } catch (\Throwable $e) {
                $this->error('  falló: '.$e->getMessage());
                $huboProblema = true;

                continue;
            }

            $this->renderSection('Etiquetas', $report['tags'] ?? []);
            $this->renderSection('Campos personalizados', $report['custom_fields'] ?? []);
        }

        // Un fallo tiene que verse en el código de salida: este comando se
        // corre en un deploy, donde nadie lee la salida entera.
        return $huboProblema ? self::FAILURE : self::SUCCESS;
    }

    /** @param  array<string, mixed>  $section */
    private function renderSection(string $title, array $section): void
    {
        if ($section === []) {
            return;
        }

        $this->line("  {$title}:");

        foreach (['created' => 'crear', 'linked' => 'enlazar', 'updated' => 'renombrar', 'deleted' => 'borrar', 'kept_in_use' => 'conservar (en uso allá)'] as $key => $label) {
            $items = $section[$key] ?? [];

            if ($items === []) {
                continue;
            }

            $this->line(sprintf('    %-26s %d', $label, count($items)));

            foreach ($items as $item) {
                $this->line('      · '.$this->describe($item));
            }
        }
    }

    private function describe(mixed $item): string
    {
        if (is_string($item)) {
            return $item;
        }

        if (isset($item['from'], $item['to'])) {
            return "{$item['from']} → {$item['to']}";
        }

        // Lo conservado dice POR QUÉ: «en uso» sin el número no se puede juzgar.
        $detalle = collect($item)
            ->except('name', 'external_id')
            ->filter(fn ($v) => is_numeric($v) && $v > 0)
            ->map(fn ($v, $k) => "{$v} {$k}")
            ->implode(', ');

        return ($item['name'] ?? '?').($detalle !== '' ? " ({$detalle})" : '');
    }
}
