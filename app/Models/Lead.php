<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

#[Fillable([
    'account_id', 'pipeline_id', 'stage_id', 'contact_id', 'company_id',
    'responsible_user_id', 'ai_enabled', 'title', 'value', 'currency', 'source',
    'source_ref', 'source_url', 'meta_leadgen_id',
    'status', 'closed_at', 'wacrm_conversation_id', 'ai_pending',
    'invoiced_cents', 'collected_cents',
])]
class Lead extends Model
{
    use BelongsToAccount, \App\Models\Concerns\HasCustomFields, HasUuids;

    public const STATUS_OPEN = 'open';
    public const STATUS_WON = 'won';
    public const STATUS_LOST = 'lost';

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'closed_at' => 'datetime',
            'ai_enabled' => 'boolean',
            'ai_pending' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Digital Pipeline: crear un lead también cuenta como "entrar"
        // a su primera etapa (cubre creación manual, WhatsApp y web forms).
        static::created(function (Lead $lead) {
            \App\Jobs\RunStageAutomationsJob::dispatch($lead->id, $lead->stage_id);
        });
    }

    /**
     * Mueve el lead a otra etapa; el estado (open/won/lost) se deriva
     * del tipo de la etapa y todo queda registrado en el timeline.
     * Es EL punto de entrada para cambios de etapa — no asignar
     * stage_id a mano fuera de aquí.
     */
    public function moveToStage(PipelineStage $stage, ?User $actor = null): void
    {
        if ($stage->pipeline_id !== $this->pipeline_id) {
            throw new \InvalidArgumentException('La etapa no pertenece al pipeline del lead.');
        }

        if ($stage->id === $this->stage_id) {
            return;
        }

        $previous = $this->stage;
        $wasTerminal = $this->status !== self::STATUS_OPEN;

        $newStatus = match ($stage->stage_type) {
            PipelineStage::TYPE_WON => self::STATUS_WON,
            PipelineStage::TYPE_LOST => self::STATUS_LOST,
            default => self::STATUS_OPEN,
        };

        $this->update([
            'stage_id' => $stage->id,
            'status' => $newStatus,
            'closed_at' => $newStatus === self::STATUS_OPEN ? null : now(),
        ]);

        $this->recordEvent('stage_changed', $actor, [
            'from' => $previous?->name,
            'to' => $stage->name,
        ]);

        if ($newStatus === self::STATUS_WON) {
            $this->recordEvent('won', $actor, ['value' => (string) $this->value]);
        } elseif ($newStatus === self::STATUS_LOST) {
            $this->recordEvent('lost', $actor, []);
        } elseif ($wasTerminal) {
            $this->recordEvent('reopened', $actor, []);
        }

        // Digital Pipeline: dispara las automatizaciones de la etapa destino.
        \App\Jobs\RunStageAutomationsJob::dispatch($this->id, $stage->id);
    }

    public function recordEvent(string $type, ?User $actor = null, array $payload = []): LeadEvent
    {
        return $this->events()->create([
            'account_id' => $this->account_id,
            'user_id' => $actor?->id,
            'event_type' => $type,
            'payload' => $payload,
        ]);
    }

    /** ¿Tiene alguna tarea pendiente? (regla Kommo: ningún lead sin tarea). */
    public function hasPendingTask(): bool
    {
        return $this->tasks()->whereNull('completed_at')->exists();
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(LeadEvent::class)->latest();
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'noteable')->latest();
    }
}
