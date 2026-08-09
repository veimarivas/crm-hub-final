<?php

namespace App\Services\Dashboard\Widgets;

use App\Models\Task;
use App\Services\Dashboard\WidgetContext;

/**
 * Las tareas del que mira, por vencimiento.
 *
 * No usa `taskScope`: incluso un admin quiere ver **las suyas** acá, no las de
 * todo el equipo. Para eso está el panel de seguimiento.
 */
class MyTasksWidget
{
    public function resolve(WidgetContext $c): array
    {
        return [
            'items' => Task::forAccount($c->accountId)
                ->pending()
                ->where('assigned_to', $c->user->id)
                ->with('lead:id,title')
                ->orderBy('due_at')
                ->limit(6)
                ->get(['id', 'text', 'due_at', 'completed_at', 'task_type', 'lead_id'])
                ->all(),
        ];
    }
}
