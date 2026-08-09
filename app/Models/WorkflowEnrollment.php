<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'account_id', 'workflow_id', 'lead_id', 'status', 'current_step_id',
    'enroll_reason', 'enroll_count', 'steps_run', 'enrolled_at', 'finished_at',
])]
class WorkflowEnrollment extends Model
{
    use BelongsToAccount, HasUuids;

    public const ACTIVE = 'active';

    public const COMPLETED = 'completed';

    public const GOAL_MET = 'goal_met';

    public const UNENROLLED = 'unenrolled';

    public const FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'enrolled_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function stepRuns(): HasMany
    {
        return $this->hasMany(WorkflowStepRun::class, 'enrollment_id');
    }

    public function pending(): HasMany
    {
        return $this->hasMany(WorkflowPendingExecution::class, 'enrollment_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** Cierra la inscripción con un desenlace y limpia lo que quedara pendiente. */
    public function finish(string $status, ?string $detail = null): void
    {
        $this->pending()->delete();

        $this->forceFill([
            'status' => $status,
            'finished_at' => now(),
            'current_step_id' => null,
        ])->save();

        if ($detail) {
            $this->stepRuns()->create(['status' => 'ok', 'detail' => $detail]);
        }
    }
}
