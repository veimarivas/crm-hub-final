<?php

namespace App\Services\Dashboard;

use App\Models\User;
use Closure;

/**
 * Lo que todo widget necesita saber para calcularse.
 *
 * Los scopes viajan como closures ya construidos y no como un booleano de rol
 * repetido en cada widget: si cada resolver decidiera por su cuenta cómo
 * recortar por responsable, tarde o temprano uno se olvidaría y un agente
 * vería números del equipo.
 */
class WidgetContext
{
    public function __construct(
        public readonly string $accountId,
        public readonly User $user,
        public readonly bool $isAdmin,
        /** @var Closure(mixed): mixed */
        public readonly Closure $leadScope,
        /** @var Closure(mixed): mixed */
        public readonly Closure $taskScope,
        public readonly string $currency,
        public readonly int $slaMinutes,
    ) {}

    public static function for(User $user, int $slaMinutes): self
    {
        $isAdmin = $user->hasRoleAtLeast(User::ROLE_ADMIN);

        return new self(
            accountId: $user->account_id,
            user: $user,
            isAdmin: $isAdmin,
            leadScope: fn ($q) => $isAdmin ? $q : $q->where('responsible_user_id', $user->id),
            taskScope: fn ($q) => $isAdmin ? $q : $q->where('assigned_to', $user->id),
            currency: $user->account->default_currency,
            slaMinutes: $slaMinutes,
        );
    }

    /** Azúcar para no escribir `($context->leadScope)(...)` en cada widget. */
    public function leads(mixed $query): mixed
    {
        return ($this->leadScope)($query);
    }

    public function tasks(mixed $query): mixed
    {
        return ($this->taskScope)($query);
    }
}
