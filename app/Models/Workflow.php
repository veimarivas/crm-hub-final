<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'account_id', 'created_by', 'name', 'description',
    'enrollment_type', 'enrollment_filters', 'trigger_type', 'trigger_config',
    'allow_reenrollment', 'reenrollment_cooldown_minutes',
    'goal_filters', 'unenroll_when_criteria_lost',
    'execution_window', 'is_active', 'last_swept_at',
])]
class Workflow extends Model
{
    use BelongsToAccount, HasUuids;

    public const ENROLLMENT_FILTER = 'filter';

    public const ENROLLMENT_EVENT = 'event';

    /** Eventos que pueden inscribir un lead al instante. */
    public const TRIGGERS = ['lead_created', 'stage_changed', 'status_changed', 'tag_added', 'score_band_changed'];

    protected function casts(): array
    {
        return [
            'enrollment_filters' => 'array',
            'trigger_config' => 'array',
            'goal_filters' => 'array',
            'execution_window' => 'array',
            'allow_reenrollment' => 'boolean',
            'unenroll_when_criteria_lost' => 'boolean',
            'is_active' => 'boolean',
            'last_swept_at' => 'datetime',
        ];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class)->orderBy('position');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(WorkflowEnrollment::class);
    }

    /** Pasos raíz, en orden: el tronco del árbol. */
    public function rootSteps()
    {
        return $this->steps()->whereNull('parent_id')->get();
    }

    /**
     * ¿Se puede ejecutar algo ahora, según la ventana del workflow?
     *
     * Un seguimiento automático que sale 3:40 AM es peor que no mandarlo: se
     * lee como spam de robot y quema el canal.
     */
    public function withinExecutionWindow(?\Carbon\CarbonInterface $moment = null): bool
    {
        $window = $this->execution_window;

        if (! $window || empty($window['from']) || empty($window['to'])) {
            return true;
        }

        $moment ??= now();
        $days = $window['days'] ?? [1, 2, 3, 4, 5];

        if (! in_array($moment->dayOfWeekIso, $days, true)) {
            return false;
        }

        return $moment->format('H:i') >= $window['from'] && $moment->format('H:i') < $window['to'];
    }

    /** El próximo momento en que la ventana admite ejecutar. */
    public function nextWindowOpening(?\Carbon\CarbonInterface $from = null): \Carbon\CarbonInterface
    {
        $cursor = ($from ?? now())->copy();

        // Se busca por horas y no por minutos para no iterar 10.000 veces; la
        // precisión de una hora alcanza para un seguimiento comercial.
        for ($i = 0; $i < 24 * 8; $i++) {
            if ($this->withinExecutionWindow($cursor)) {
                return $cursor;
            }
            $cursor = $cursor->addHour()->startOfHour();
        }

        return $cursor;
    }
}
