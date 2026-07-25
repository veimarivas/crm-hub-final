<?php

namespace App\Services\LeadAssignment;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RoundRobin
{
    /**
     * Sources que disparan la asignacion automatica cuando el lead nace sin responsable.
     * Los leads creados manualmente por un user (source=manual) NO se reasignan — el
     * store del LeadController ya usa el user actual como responsable por defecto.
     */
    public const AUTO_SOURCES = ['whatsapp', 'web_form', 'lead_ad', 'api'];

    /**
     * Elige el agente con menos leads abiertos en la cuenta. Ignora viewer.
     * En caso de empate, el que tenga la asignacion mas antigua (least recently used).
     */
    public function pickAssignee(string $accountId): ?User
    {
        $eligible = User::where('account_id', $accountId)
            ->whereIn('account_role', [User::ROLE_OWNER, User::ROLE_ADMIN, User::ROLE_AGENT])
            ->get(['id']);

        if ($eligible->isEmpty()) {
            return null;
        }

        // Cuenta de leads abiertos por user
        $counts = Lead::where('account_id', $accountId)
            ->where('status', 'open')
            ->whereIn('responsible_user_id', $eligible->pluck('id'))
            ->select('responsible_user_id', DB::raw('COUNT(*) as c'))
            ->groupBy('responsible_user_id')
            ->pluck('c', 'responsible_user_id');

        // Ultima asignacion por user (empate)
        $lastAt = Lead::where('account_id', $accountId)
            ->whereIn('responsible_user_id', $eligible->pluck('id'))
            ->select('responsible_user_id', DB::raw('MAX(created_at) as last_at'))
            ->groupBy('responsible_user_id')
            ->pluck('last_at', 'responsible_user_id');

        // Ordenar por (count asc, last_at asc) — el que menos tiene y hace mas rato
        $ranked = $eligible->map(fn ($u) => [
            'id' => $u->id,
            'count' => (int) ($counts[$u->id] ?? 0),
            'last_at' => $lastAt[$u->id] ?? '0',
        ])->sort(function ($a, $b) {
            return [$a['count'], $a['last_at']] <=> [$b['count'], $b['last_at']];
        })->values();

        $winnerId = $ranked->first()['id'] ?? null;

        return $winnerId ? User::find($winnerId) : null;
    }

    /**
     * Aplica round-robin al lead si la cuenta lo tiene activo, el lead esta sin responsable
     * y su source pertenece a AUTO_SOURCES. Devuelve el user asignado o null.
     */
    public function assignIfEligible(Lead $lead): ?User
    {
        if ($lead->responsible_user_id) {
            return null;
        }
        if (! in_array($lead->source, self::AUTO_SOURCES, true)) {
            return null;
        }

        $account = $lead->account;
        if (! $account || ! $account->auto_assign_leads) {
            return null;
        }

        $winner = $this->pickAssignee($lead->account_id);
        if (! $winner) {
            return null;
        }

        $lead->forceFill(['responsible_user_id' => $winner->id])->saveQuietly();

        return $winner;
    }
}
