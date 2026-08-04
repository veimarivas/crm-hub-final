<?php

namespace App\Models;

use App\Jobs\RunStageAutomationsJob;
use App\Jobs\SyncLeadAssignmentToWacrmJob;
use App\Jobs\SyncLeadStageToWacrmJob;
use App\Models\Concerns\BelongsToAccount;
use App\Services\LeadAssignment\RoundRobin;
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
    'status', 'closed_at', 'wacrm_conversation_id', 'ai_pending', 'ai_paused_until',
    'invoiced_cents', 'collected_cents',
    'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
    'gclid', 'fbclid', 'ttclid', 'msclkid',
    'landing_url', 'referrer_url', 'first_touch_at',
])]
class Lead extends Model
{
    use \App\Models\Concerns\HasCustomFields, BelongsToAccount, HasUuids;

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
            'ai_paused_until' => 'datetime',
            'first_touch_at' => 'datetime',
        ];
    }

    /**
     * Campos que forman el "toque" de atribución. Todos son first-touch:
     * una vez seteados no se sobreescriben. Ver applyTracking().
     */
    public const TRACKING_FIELDS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'gclid', 'fbclid', 'ttclid', 'msclkid',
        'landing_url', 'referrer_url',
    ];

    /**
     * Aplica atribución al lead. Política first-touch: si el lead ya tiene
     * `first_touch_at`, solo se rellenan los campos que aún estén vacíos
     * (nunca se pisa un valor previo). Devuelve true si al menos un campo
     * cambió — útil para decidir si persistir o no.
     *
     * @param  array<string,string|null>  $tracking  claves de TRACKING_FIELDS
     */
    public function applyTracking(array $tracking): bool
    {
        $wasFirstTouch = $this->first_touch_at === null;
        $changes = [];

        foreach (self::TRACKING_FIELDS as $field) {
            $value = $tracking[$field] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            // First-touch: no pisar valores existentes.
            if (! empty($this->{$field})) {
                continue;
            }
            $changes[$field] = mb_substr((string) $value, 0, $field === 'landing_url' || $field === 'referrer_url' ? 2048 : 191);
        }

        if ($wasFirstTouch && $changes !== []) {
            $changes['first_touch_at'] = now();
        }

        if ($changes === []) {
            return false;
        }

        $this->fill($changes)->save();

        return true;
    }

    protected static function booted(): void
    {
        // Digital Pipeline: crear un lead también cuenta como "entrar"
        // a su primera etapa (cubre creación manual, WhatsApp y web forms).
        static::created(function (Lead $lead) {
            $lead->tagAsNew();

            // Round-robin: si la cuenta lo tiene activo y el lead entra sin
            // responsable via source automatico (whatsapp/web_form/lead_ad/api),
            // asignarlo al agente con menos leads abiertos.
            $assignee = app(RoundRobin::class)->assignIfEligible($lead);
            if ($assignee) {
                AppNotification::notify(
                    $lead->account_id,
                    $assignee->id,
                    'lead_assigned',
                    'Nuevo lead asignado (auto): '.$lead->title,
                    null,
                    $lead->id,
                );

                // Espeja la asignacion en el wacrm: sin esto la conversacion
                // se queda "Sin asignar" en el Inbox aunque el lead ya tenga
                // responsable aqui.
                SyncLeadAssignmentToWacrmJob::dispatch($lead->id);
            }

            RunStageAutomationsJob::dispatch($lead->id, $lead->stage_id);
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
        RunStageAutomationsJob::dispatch($this->id, $stage->id);

        // Espeja la etapa en el wacrm: la columna de /pipelines debe reflejar
        // la misma columna que el kanban del Komo. En cola — mover no espera al HTTP.
        SyncLeadStageToWacrmJob::dispatch($this->id);
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

    /**
     * Marca el lead recién nacido con la etiqueta «Nuevo».
     *
     * Sirve para difundir a los que acaban de llegar sin tener que etiquetar a
     * mano uno por uno — que en la práctica significaba no hacerlo. La etiqueta
     * se saca a mano cuando el lead se trabaja; no se quita sola, porque cuándo
     * deja de ser nuevo lo decide el equipo, no un reloj.
     *
     * Silencioso a propósito: que falle no puede impedir que el lead exista, y
     * un lead sin etiqueta se arregla en un clic.
     */
    public function tagAsNew(): void
    {
        rescue(function () {
            $tag = Tag::forAccount($this->account_id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(Tag::NEW_LEAD)])
                ->first()
                ?? Tag::create([
                    'account_id' => $this->account_id,
                    'name' => Tag::NEW_LEAD,
                    'color' => Tag::NEW_LEAD_COLOR,
                ]);

            $this->tags()->syncWithoutDetaching([$tag->id]);
        }, report: false);
    }
}
