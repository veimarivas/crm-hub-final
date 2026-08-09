<?php

namespace App\Services\Dashboard\Widgets;

use App\Models\Lead;
use App\Models\Task;
use App\Services\Dashboard\WidgetContext;

/**
 * La fila de indicadores, con su variación contra el periodo anterior
 * comparable.
 *
 * Cada métrica se compara con su equivalente honesto, no con «hace 30 días» a
 * secas: abiertos contra los que estaban abiertos hace un mes (reconstruido
 * desde `closed_at`), ganados del mes contra el **mismo tramo** del mes pasado
 * (para no comparar 7 días contra 30), tareas de hoy contra las que vencían
 * ayer.
 *
 * `leadsWithoutTask` no lleva delta: no hay histórico del que sacar cuántos
 * estaban sin tarea ayer, y un número inventado se leería como real.
 */
class KpiWidget
{
    public function resolve(WidgetContext $c): array
    {
        $open = fn () => $c->leads(Lead::forAccount($c->accountId)->where('status', 'open'));
        $wonThisMonth = fn () => $c->leads(Lead::forAccount($c->accountId)->where('status', 'won')
            ->where('closed_at', '>=', now()->startOfMonth()));
        $tasksToday = fn () => $c->tasks(Task::forAccount($c->accountId)->pending()
            ->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()]));

        $withoutTask = $c->leads(Lead::forAccount($c->accountId)->where('status', 'open')
            ->whereDoesntHave('tasks', fn ($q) => $q->whereNull('completed_at')));

        $monthAgo = now()->subMonth();

        $openThen = $c->leads(Lead::forAccount($c->accountId)
            ->where('created_at', '<=', $monthAgo)
            ->where(fn ($q) => $q->whereNull('closed_at')->orWhere('closed_at', '>', $monthAgo)))
            ->count();

        $wonPrevMonth = $c->leads(Lead::forAccount($c->accountId)->where('status', 'won')
            ->whereBetween('closed_at', [$monthAgo->copy()->startOfMonth(), $monthAgo]))
            ->count();

        $tasksYesterday = $c->tasks(Task::forAccount($c->accountId)
            ->whereBetween('due_at', [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()]))
            ->count();

        return [
            'stats' => [
                'openLeads' => $open()->count(),
                'openValue' => (float) $open()->sum('value'),
                'wonThisMonth' => $wonThisMonth()->count(),
                'wonValueThisMonth' => (float) $wonThisMonth()->sum('value'),
                'overdueTasks' => $c->tasks(Task::forAccount($c->accountId)->overdue())->count(),
                'tasksToday' => $tasksToday()->count(),
                'leadsWithoutTask' => $withoutTask->count(),
            ],
            'deltas' => [
                'openLeads' => $this->pctChange($open()->count(), $openThen),
                'wonThisMonth' => $this->pctChange($wonThisMonth()->count(), $wonPrevMonth),
                'tasksToday' => $this->pctChange($tasksToday()->count(), $tasksYesterday),
            ],
            'currency' => $c->currency,
        ];
    }

    /** Variación porcentual; `null` cuando no hay base con la que comparar. */
    private function pctChange(int $current, int $previous): ?float
    {
        return $previous === 0 ? null : round(($current - $previous) / $previous * 100, 1);
    }
}
