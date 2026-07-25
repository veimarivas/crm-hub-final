<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
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
        $mine = $request->boolean('mine', true);

        $baseScope = fn ($q) => $mine ? $q->where('assigned_to', $userId) : $q;

        if ($view === 'calendar') {
            // Rango del mes visible (incluye dias de semanas parciales anteriores/posteriores)
            $month = $request->query('month'); // YYYY-MM
            $anchor = $month ? \Carbon\Carbon::createFromFormat('Y-m', $month)->startOfMonth() : now()->startOfMonth();
            $from = $anchor->copy()->startOfMonth()->startOfWeek(\Carbon\Carbon::MONDAY);
            $to = $anchor->copy()->endOfMonth()->endOfWeek(\Carbon\Carbon::SUNDAY);

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
        abort_if($task->account_id !== $request->user()->account_id, 403);

        $validated = $request->validate(['result_note' => 'nullable|string|max:2000']);

        $task->complete($validated['result_note'] ?? null, $request->user());

        return back()->with('success', 'Tarea completada.');
    }

    public function destroy(Request $request, Task $task): RedirectResponse
    {
        abort_if($task->account_id !== $request->user()->account_id, 403);
        $task->delete();

        return back()->with('success', 'Tarea eliminada.');
    }
}
