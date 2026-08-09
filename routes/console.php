<?php

use App\Models\AppNotification;
use App\Models\Task;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Avisa al asignado cuando una tarea vence (una sola vez por tarea).
Artisan::command('tasks:notify-overdue', function () {
    $due = Task::overdue()
        ->whereNull('overdue_notified_at')
        ->whereNotNull('assigned_to')
        ->with('lead:id,title')
        ->limit(200)
        ->get();

    foreach ($due as $task) {
        AppNotification::notify(
            $task->account_id,
            $task->assigned_to,
            'task_overdue',
            'Tarea vencida',
            $task->text.($task->lead ? " — {$task->lead->title}" : ''),
            $task->lead_id,
        );

        $task->update(['overdue_notified_at' => now()]);
    }

    $this->info("Notificadas: {$due->count()}");
})->purpose('Notifica tareas vencidas a sus asignados');

Schedule::command('tasks:notify-overdue')->everyTenMinutes();

// Copiloto: repuntúa los leads abiertos de todas las cuentas.
//
// De noche y no cada hora porque las señales que mueven el score se miden en
// días (recencia, estancamiento), no en minutos: recalcular seguido gastaría
// base para mover el número en decimales. Lo urgente ya se repuntúa solo
// cuando entra un mensaje o cambia la etapa (ScoreLeads::forLead).
Artisan::command('copilot:score-leads', function () {
    $total = 0;

    foreach (\App\Models\Account::pluck('id') as $accountId) {
        $result = app(\App\Services\Copilot\ScoreLeads::class)->forAccount($accountId);
        $total += $result['scored'];
    }

    $this->info("Leads puntuados: {$total}");
})->purpose('Recalcula el score del copiloto para los leads abiertos');

Schedule::command('copilot:score-leads')->dailyAt('03:30');

// El envío de recordatorios por WhatsApp `komo:remind-daily-tasks` se dejó
// en el código pero NO se agenda: Meta cobra por conversaciones iniciadas
// desde el negocio fuera de la ventana de 24h (~$0.01-0.03 USD por agente
// por día en Bolivia) y además requiere un template aprobado. Preferimos
// las notificaciones in-app (AppNotification + tasks:notify-overdue arriba).
// Si más adelante se aprueba un template en Meta, reactivar acá.
