<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Traza paso a paso de una inscripción.
 *
 * Imprescindible, no un lujo: hoy un fallo de automatización de etapa solo deja
 * un `Log::warning` que nadie lee, y el usuario ve la automatización «Activa»
 * sin que haya hecho nada. Acá cada paso deja qué pasó y por qué.
 */
#[Fillable(['enrollment_id', 'step_id', 'status', 'detail', 'idempotency_key'])]
class WorkflowStepRun extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(WorkflowEnrollment::class, 'enrollment_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'step_id');
    }
}
