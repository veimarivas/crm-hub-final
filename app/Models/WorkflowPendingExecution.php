<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una espera pendiente: el paso que hay que correr cuando llegue `run_at`. */
#[Fillable(['enrollment_id', 'step_id', 'run_at'])]
class WorkflowPendingExecution extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['run_at' => 'datetime'];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(WorkflowEnrollment::class, 'enrollment_id');
    }

    /**
     * El paso en el que quedó esperando.
     *
     * Sin esta relación `$pending->step` devuelve `null` **en silencio** y el
     * motor da la espera por inválida: la secuencia se corta después del primer
     * `wait` y nadie se entera.
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'step_id');
    }
}
