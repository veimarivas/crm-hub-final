<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    public function index(Request $request): Response
    {
        $accountId = $request->user()->account_id;
        $userId = $request->user()->id;
        $view = $request->query('view', 'calendar'); // calendar | list
        $filter = $request->query('filter', 'pending');
        $isAdmin = $request->user()->hasRoleAtLeast(User::ROLE_ADMIN);

        // El agente ve EXCLUSIVAMENTE sus tareas: el toggle "solo las mías" es
        // del admin, que necesita la vista del equipo para hacer seguimiento.
        // Sin esto un ?mine=0 a mano abría la agenda de todo el equipo.
        $mine = $isAdmin ? $request->boolean('mine', true) : true;

        $baseScope = fn ($q) => $mine ? $q->where('assigned_to', $userId) : $q;

        // Filtros del calendario. `responsible` solo lo aplica el admin y solo
        // tiene sentido cuando mira al equipo entero.
        $type = $request->query('type');
        $responsible = $isAdmin && ! $mine ? $request->query('responsible') : null;

        $extraFilters = fn ($q) => $q
            ->when($type, fn ($qq, $v) => $qq->where('task_type', $v))
            ->when($responsible, fn ($qq, $v) => $qq->where('assigned_to', $v));

        if ($view === 'calendar') {
            $tz = $request->user()->account->business_hours_timezone ?: config('app.timezone');

            // Mes / semana / día. El rango se calcula EN LA ZONA DE LA CUENTA:
            // «esta semana» empieza el lunes del usuario, no el del servidor.
            $mode = in_array($request->query('mode'), ['month', 'week', 'day'], true)
                ? $request->query('mode') : 'month';

            $anchor = $this->calendarAnchor($request, $mode, $tz);

            [$from, $to] = match ($mode) {
                'day' => [$anchor->copy()->startOfDay(), $anchor->copy()->endOfDay()],
                'week' => [$anchor->copy()->startOfWeek(Carbon::MONDAY), $anchor->copy()->endOfWeek(Carbon::SUNDAY)],
                default => [
                    $anchor->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY),
                    $anchor->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY),
                ],
            };

            $rangeTasks = Task::forAccount($accountId)
                ->with(['lead:id,title', 'contact:id,name', 'assignee:id,name'])
                ->when($mine, fn ($q) => $q->where('assigned_to', $userId))
                ->tap($extraFilters)
                // Los límites se convierten a la zona de la app antes de
                // consultar: Eloquent liga el Carbon formateado en la zona que
                // trae el objeto, así que un rango en La Paz recortaría mal
                // contra una columna guardada en UTC.
                ->whereBetween('due_at', [
                    $from->copy()->setTimezone(config('app.timezone')),
                    $to->copy()->setTimezone(config('app.timezone')),
                ])
                ->orderBy('due_at')
                ->get()
                ->map(function (Task $task) use ($tz) {
                    // El día al que pertenece la tarea se calcula EN EL SERVIDOR,
                    // en la zona de la cuenta. Agruparlo en el navegador ponía
                    // una tarea de las 23:30 en el día siguiente (o anterior)
                    // según dónde estuviera abierto el CRM.
                    $local = $task->due_at->copy()->setTimezone($tz);

                    return $task->setAttribute('due_date', $local->format('Y-m-d'))
                        ->setAttribute('due_time', $local->format('H:i'));
                });

            return Inertia::render('Tasks/Index', [
                'view' => 'calendar',
                'calendar' => [
                    'mode' => $mode,
                    'anchor' => $mode === 'month' ? $anchor->format('Y-m') : $anchor->format('Y-m-d'),
                    'from' => $from->format('Y-m-d'),
                    'to' => $to->format('Y-m-d'),
                    'tasks' => $rangeTasks,
                    'today' => now()->setTimezone($tz)->format('Y-m-d'),
                    'timezone' => $tz,
                    // Días laborables, para atenuar los que no lo son: mover una
                    // tarea a un domingo suele ser un error de arrastre.
                    'workingDays' => $this->workingDays($request),
                    // Franja horaria que dibuja la grilla de semana y día.
                    'hours' => $this->calendarHours($request),
                ],
                'filters' => ['filter' => $filter, 'mine' => $mine, 'view' => 'calendar', 'mode' => $mode, 'type' => $type, 'responsible' => $responsible],
                'members' => User::where('account_id', $accountId)->get(['id', 'name']),
                'isAdmin' => $isAdmin,
                'counts' => [
                    'overdue' => Task::forAccount($accountId)->overdue()->when($mine, $baseScope)->count(),
                    'today' => Task::forAccount($accountId)->pending()
                        ->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()])
                        ->when($mine, $baseScope)->count(),
                ],
            ]);
        }

        // Vista lista (comportamiento anterior)
        $tasks = Task::forAccount($accountId)
            ->with(['lead:id,title', 'contact:id,name', 'assignee:id,name'])
            ->when($mine, $baseScope)
            ->when($filter === 'pending', fn ($q) => $q->pending())
            ->when($filter === 'overdue', fn ($q) => $q->overdue())
            ->when($filter === 'today', fn ($q) => $q->pending()->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()]))
            ->when($filter === 'done', fn ($q) => $q->whereNotNull('completed_at')->latest('completed_at'))
            ->when($filter !== 'done', fn ($q) => $q->orderBy('due_at'))
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Tasks/Index', [
            'view' => 'list',
            'tasks' => $tasks,
            'filters' => ['filter' => $filter, 'mine' => $mine, 'view' => 'list'],
            'members' => User::where('account_id', $accountId)->get(['id', 'name']),
            'isAdmin' => $isAdmin,
            'counts' => [
                'overdue' => Task::forAccount($accountId)->overdue()->when($mine, $baseScope)->count(),
                'today' => Task::forAccount($accountId)->pending()
                    ->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()])
                    ->when($mine, $baseScope)->count(),
            ],
        ]);
    }

    /**
     * Día de referencia del calendario, en la zona de la cuenta.
     *
     * El mes sigue viajando como `?month=YYYY-MM` (los enlaces viejos no se
     * rompen); semana y día usan `?date=YYYY-MM-DD`.
     */
    private function calendarAnchor(Request $request, string $mode, string $tz): Carbon
    {
        if ($mode === 'month') {
            $month = $request->query('month');

            return $month
                ? Carbon::createFromFormat('Y-m-d H:i:s', $month.'-01 00:00:00', $tz)->startOfMonth()
                : now()->setTimezone($tz)->startOfMonth();
        }

        $date = $request->query('date');

        return $date
            ? Carbon::createFromFormat('Y-m-d H:i:s', $date.' 00:00:00', $tz)
            : now()->setTimezone($tz)->startOfDay();
    }

    /**
     * Franja horaria de la grilla de semana y día.
     *
     * Sale del horario comercial: dibujar de 00 a 23 obliga a scrollear por
     * catorce horas vacías para llegar a la mañana. Se ensancha una hora a cada
     * lado para que una tarea puesta justo antes de abrir siga siendo visible.
     *
     * @return array{from: int, to: int}
     */
    private function calendarHours(Request $request): array
    {
        $account = $request->user()->account;
        $schedule = $account->business_hours_enabled
            ? ($account->business_hours_schedule ?: \App\Services\BusinessHours\Schedule::DEFAULT_SCHEDULE)
            : [];

        $starts = collect($schedule)->filter()->pluck('from')->filter();
        $ends = collect($schedule)->filter()->pluck('to')->filter();

        if ($starts->isEmpty() || $ends->isEmpty()) {
            return ['from' => 7, 'to' => 21];
        }

        $from = (int) $starts->map(fn ($h) => (int) explode(':', $h)[0])->min();
        $to = (int) $ends->map(fn ($h) => (int) ceil((int) explode(':', $h)[0]))->max();

        return ['from' => max(0, $from - 1), 'to' => min(23, $to + 1)];
    }

    /**
     * Días de la semana (ISO 1-7) en los que la cuenta atiende.
     *
     * Sale del horario comercial que ya se configura en `/settings/business-hours`:
     * no hace falta un ajuste nuevo para saber qué días son laborables.
     *
     * @return array<int, int>
     */
    private function workingDays(Request $request): array
    {
        $account = $request->user()->account;

        if (! $account->business_hours_enabled) {
            return [1, 2, 3, 4, 5, 6, 7];
        }

        $schedule = $account->business_hours_schedule ?: \App\Services\BusinessHours\Schedule::DEFAULT_SCHEDULE;

        return collect(\App\Services\BusinessHours\Schedule::DAYS)
            ->map(fn (string $day, int $i) => ! empty($schedule[$day]['from'] ?? null) ? $i + 1 : null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Reprograma una tarea arrastrándola a otro día del calendario.
     *
     * **Conserva la hora** salvo que se mande una nueva: mover «llamar a Ana a
     * las 10:00» del martes al jueves no debería convertirla en una tarea de
     * medianoche, que es lo que pasa si solo se cambia la fecha.
     *
     * Queda registrado en el timeline del lead: una tarea que se corre tres
     * veces cuenta una historia, y esa historia se pierde si reprogramar es
     * silencioso.
     */
    public function reschedule(Request $request, Task $task): RedirectResponse
    {
        $this->authorizeTask($request, $task);

        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'time' => 'nullable|date_format:H:i',
        ]);

        $tz = $request->user()->account->business_hours_timezone ?: config('app.timezone');
        $previous = $task->due_at;

        // La fecha llega tal como la ve el usuario en su calendario, así que se
        // interpreta en la zona de la cuenta y recién ahí se guarda en UTC.
        $time = $validated['time'] ?? $previous->copy()->setTimezone($tz)->format('H:i');

        // ⚠️ El `setTimezone` final no es decorativo: Eloquent serializa el
        // Carbon **en la zona que trae el objeto**, sin convertir. Guardar uno
        // en `America/La_Paz` mete «10:00» literal en una columna que se lee
        // como UTC, y la tarea aparece 4 horas antes de lo pedido.
        $due = Carbon::createFromFormat('Y-m-d H:i', "{$validated['date']} {$time}", $tz)
            ->setTimezone(config('app.timezone'));

        $task->update(['due_at' => $due, 'overdue_notified_at' => null]);

        $task->lead?->recordEvent('task_rescheduled', $request->user(), [
            'text' => $task->text,
            'from' => $previous->toIso8601String(),
            'to' => $due->toIso8601String(),
        ]);

        return back()->with('success', 'Tarea reprogramada.');
    }

    public function store(Request $request): RedirectResponse
    {
        $accountId = $request->user()->account_id;

        $validated = $request->validate([
            'lead_id' => 'nullable|uuid',
            'assigned_to' => 'nullable|uuid|exists:users,id',
            'task_type' => 'required|in:call,meet,follow_up,email,other',
            'text' => 'required|string|max:2000',
            'due_at' => 'required|date',
        ]);

        $lead = null;
        if ($validated['lead_id'] ?? null) {
            $lead = Lead::forAccount($accountId)->findOrFail($validated['lead_id']);
            // Agent solo puede crear tareas en leads asignados a él.
            if (! $request->user()->hasRoleAtLeast(User::ROLE_ADMIN)) {
                abort_if($lead->responsible_user_id !== $request->user()->id, 403);
            }
        }

        $task = Task::create([
            ...$validated,
            'account_id' => $accountId,
            'contact_id' => $lead?->contact_id,
            'assigned_to' => $validated['assigned_to'] ?? $request->user()->id,
            'created_by' => $request->user()->id,
        ]);

        $lead?->recordEvent('task_created', $request->user(), [
            'text' => $task->text,
            'due_at' => $task->due_at->toIso8601String(),
        ]);

        return back()->with('success', 'Tarea creada.');
    }

    public function complete(Request $request, Task $task): RedirectResponse
    {
        $this->authorizeTask($request, $task);

        $validated = $request->validate(['result_note' => 'nullable|string|max:2000']);

        $task->complete($validated['result_note'] ?? null, $request->user());

        return back()->with('success', 'Tarea completada.');
    }

    /** Reabrir tarea (undo del complete). */
    public function uncomplete(Request $request, Task $task): RedirectResponse
    {
        $this->authorizeTask($request, $task);

        $task->update(['completed_at' => null, 'result_note' => null]);

        return back()->with('success', 'Tarea reabierta.');
    }

    /** Posponer una tarea N minutos hacia adelante desde su due_at actual. */
    public function snooze(Request $request, Task $task): RedirectResponse
    {
        $this->authorizeTask($request, $task);
        abort_if($task->completed_at !== null, 422, 'Tarea ya completada.');

        $validated = $request->validate([
            'minutes' => 'nullable|integer|min:5|max:20160', // hasta 14 dias
            'until' => 'nullable|date',
        ]);

        $newDue = null;
        if (! empty($validated['until'])) {
            $newDue = Carbon::parse($validated['until']);
        } elseif (! empty($validated['minutes'])) {
            // Suma desde el maximo entre due_at actual y ahora (evita snoozes que quedan en el pasado)
            $base = max($task->due_at, now());
            $newDue = $base->copy()->addMinutes($validated['minutes']);
        } else {
            abort(422, 'Debe indicar minutes o until.');
        }

        $task->update(['due_at' => $newDue]);

        return back()->with('success', 'Tarea pospuesta.');
    }

    public function destroy(Request $request, Task $task): RedirectResponse
    {
        $this->authorizeTask($request, $task);
        $task->delete();

        return back()->with('success', 'Tarea eliminada.');
    }

    /**
     * El agente solo toca sus propias tareas. Antes estos endpoints
     * verificaban únicamente la cuenta: con el ID de una tarea ajena se podía
     * completar, posponer o borrar la agenda de un compañero.
     * El admin puede operar sobre cualquiera (hace seguimiento del equipo).
     */
    private function authorizeTask(Request $request, Task $task): void
    {
        $user = $request->user();

        abort_if($task->account_id !== $user->account_id, 403);

        if (! $user->hasRoleAtLeast(User::ROLE_ADMIN)) {
            abort_if($task->assigned_to !== $user->id, 403, 'Esta tarea no es tuya.');
        }
    }
}
