<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'account_id', 'created_by', 'name', 'key_prefix', 'key_hash',
    'scopes', 'last_used_at', 'expires_at', 'revoked_at',
])]
class ApiKey extends Model
{
    use BelongsToAccount, HasUuids;

    public const UPDATED_AT = null;

    /** Scopes disponibles en la API pública del komo. */
    public const SCOPES = ['leads:read', 'leads:write', 'contacts:read', 'notifications:read', 'team:write'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Genera una clave nueva. Devuelve [modelo, claveEnTextoPlano] —
     * el texto plano se muestra UNA sola vez; en BD solo va el hash.
     */
    public static function issue(string $accountId, ?string $createdBy, string $name, array $scopes): array
    {
        $plaintext = 'komo_live_'.Str::random(40);

        $key = static::create([
            'account_id' => $accountId,
            'created_by' => $createdBy,
            'name' => $name,
            'key_prefix' => substr($plaintext, 0, 18),
            'key_hash' => hash('sha256', $plaintext),
            'scopes' => $scopes,
        ]);

        return [$key, $plaintext];
    }

    public static function findByPlaintext(string $plaintext): ?self
    {
        return static::where('key_hash', hash('sha256', $plaintext))->first();
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
