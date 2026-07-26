<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notificaciones in-app. Se llama AppNotification (tabla
 * app_notifications) para no chocar con las database notifications
 * nativas de Laravel.
 */
#[Fillable(['account_id', 'user_id', 'type', 'category', 'lead_id', 'title', 'body', 'read_at', 'deliver_at', 'sent_by_user_id', 'batch_id'])]
class AppNotification extends Model
{
    use BelongsToAccount, HasUuids;

    public const UPDATED_AT = null;

    /** Apartados de los avisos que manda el admin al equipo. */
    public const CATEGORIES = ['seguimiento', 'personal', 'marketing'];

    /** Tipos que crea el admin a mano (el resto los genera el sistema). */
    public const TYPE_TEAM_NOTE = 'team_note';

    public const TYPE_TEAM_REMINDER = 'team_reminder';

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'deliver_at' => 'datetime'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Notificaciones que el destinatario ya puede ver. Un recordatorio
     * programado existe en la tabla desde que se crea, pero no cuenta ni
     * aparece hasta su `deliver_at` — así no hace falta cola ni cron.
     *
     * TODA lectura de notificaciones debe pasar por acá.
     */
    public function scopeDelivered(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('deliver_at')
            ->orWhere('deliver_at', '<=', now()));
    }

    /** Helper de creación con guard: nunca notificarse a uno mismo. */
    public static function notify(
        string $accountId,
        ?string $userId,
        string $type,
        string $title,
        ?string $body = null,
        ?string $leadId = null,
        ?string $actorId = null,
    ): void {
        if (! $userId || $userId === $actorId) {
            return;
        }

        static::create([
            'account_id' => $accountId,
            'user_id' => $userId,
            'type' => $type,
            'lead_id' => $leadId,
            'title' => $title,
            'body' => $body,
        ]);
    }
}
