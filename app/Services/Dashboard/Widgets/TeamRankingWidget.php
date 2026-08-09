<?php

namespace App\Services\Dashboard\Widgets;

use App\Models\Lead;
use App\Models\User;
use App\Services\Dashboard\WidgetContext;

/**
 * Ganados y valor cerrado por responsable en el mes.
 *
 * Solo admin (declarado en el registro): un agente no compara su desempeño con
 * el del resto desde su propio tablero.
 */
class TeamRankingWidget
{
    public function resolve(WidgetContext $c): array
    {
        $members = User::where('account_id', $c->accountId)->get(['id', 'name'])->keyBy('id');

        $rows = Lead::forAccount($c->accountId)
            ->where('status', 'won')
            ->where('closed_at', '>=', now()->startOfMonth())
            ->whereNotNull('responsible_user_id')
            ->selectRaw('responsible_user_id, COUNT(*) as won, COALESCE(SUM(value), 0) as value')
            ->groupBy('responsible_user_id')
            ->get();

        return [
            'currency' => $c->currency,
            'items' => $rows
                ->map(fn ($r) => [
                    'id' => $r->responsible_user_id,
                    'name' => $members[$r->responsible_user_id]->name ?? 'Usuario retirado',
                    'won' => (int) $r->won,
                    'value' => (float) $r->value,
                ])
                ->sortByDesc('value')
                ->values()
                ->all(),
        ];
    }
}
