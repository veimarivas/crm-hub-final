<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'account_id', 'user_id', 'email', 'provider',
    'access_token', 'refresh_token', 'token_expires_at',
    'last_history_id', 'last_synced_at', 'last_error', 'is_active',
])]
class EmailAccount extends Model
{
    use BelongsToAccount, HasUuids;

    protected function casts(): array
    {
        return [
            // Cifrado en reposo: un refresh token de Workspace da acceso al
            // correo de la institución.
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * El token de acceso vence en una hora. Se considera vencido un minuto
     * antes para no perder la carrera contra una petición en vuelo.
     */
    public function tokenExpired(): bool
    {
        return ! $this->token_expires_at || $this->token_expires_at->subMinute()->isPast();
    }
}
