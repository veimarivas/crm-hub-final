<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['workflow_id', 'parent_id', 'branch_key', 'position', 'step_type', 'config'])]
class WorkflowStep extends Model
{
    use HasUuids;

    /** Acciones: hacen algo con el lead. */
    public const ACTIONS = [
        'send_whatsapp', 'create_task', 'add_note', 'add_tag', 'remove_tag',
        'change_stage', 'assign_responsible', 'notify_user',
    ];

    /** Control de flujo: deciden por dónde y cuándo sigue. */
    public const FLOW = ['wait', 'wait_until', 'branch', 'end'];

    /** Pasos que salen hacia el cliente; cuentan para el tope diario. */
    public const OUTBOUND = ['send_whatsapp'];

    protected function casts(): array
    {
        return ['config' => 'array'];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    public function isOutbound(): bool
    {
        return in_array($this->step_type, self::OUTBOUND, true);
    }
}
