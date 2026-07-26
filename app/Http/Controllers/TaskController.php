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

        if ($view === 'calendar') {
            // Rango del mes visible (incluye dias de semanas parciales anteriores/posteriores)
            $month = $request->query('month'); // YYYY-MM
            $anchor = $month ? Carbon::createFromFormat('Y-m', $month)->startOfMonth() : now()->startOfMonth();
            $from = $anchor->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
            $to = $anchor->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

            $rangeTasks = Task::forAccount($accountId)
                ->with(['lead:id,title', 'contact:id,name', 'assignee:id,name'])
                ->when($mine, fn ($q) => $q->where('assigned_to', $userId))
                ->whereBetween('due_at', [$from, $to])
                ->orderBy('due_at')
                ->get();

            return Inertia::render('Tasks/Index', [
                'view' => 'calendar',
                'calendar' => [
                    'anchor' => $anchor->format('Y-m'),
                    'from' => $from->toIso8601String(),
                    'to' => $to->toIso8601String(),
                    'tasks' => $rangeTasks,
                ],
                'filters' => ['filter' => $filter, 'mine' => $mine, 'view' => 'calendar'],
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
