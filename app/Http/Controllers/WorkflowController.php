<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowEnrollment;
use App\Models\WorkflowStep;
use App\Services\Leads\SegmentQuery;
use App\Services\Workflows\Guardrails;
use App\Services\Workflows\WorkflowSimulator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Constructor de workflows (T3, parte visual).
 *
 * Admin-only por la ruta: configurar automatizaciones que le escriben a
 * clientes no es trabajo diario del asesor.
 */
class WorkflowController extends Controller
{
    public function index(Request $request): Response
    {
        $accountId = $request->user()->account_id;

        $workflows = Workflow::forAccount($accountId)
            ->withCount([
                'steps',
                'enrollments as active_count' => fn ($q) => $q->where('status', WorkflowEnrollment::ACTIVE),
                'enrollments as goal_count' => fn ($q) => $q->where('status', WorkflowEnrollment::GOAL_MET),
                'enrollments as completed_count' => fn ($q) => $q->where('status', WorkflowEnrollment::COMPLETED),
                'enrollments as failed_count' => fn ($q) => $q->where('status', WorkflowEnrollment::FAILED),
            ])
            ->latest()
            ->get();

        return Inertia::render('Workflows/Index', [
            'workflows' => $workflows->map(fn (Workflow $w) => [
                'id' => $w->id,
                'name' => $w->name,
                'description' => $w->description,
                'is_active' => $w->is_active,
                'enrollment_type' => $w->enrollment_type,
                'steps_count' => $w->steps_count,
                'stats' => [
                    'active' => $w->active_count,
                    'goal' => $w->goal_count,
                    'completed' => $w->completed_count,
                    'failed' => $w->failed_count,
                ],
                'last_swept_at' => $w->last_swept_at?->toIso8601String(),
            ]),
            'paused' => (bool) $request->user()->account->workflows_paused_at,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['name' => 'required|string|max:150']);

        // Nace inactivo y sin pasos, siempre.
        $workflow = Workflow::create([
            'account_id' => $request->user()->account_id,
            'created_by' => $request->user()->id,
            'name' => $validated['name'],
            'enrollment_type' => Workflow::ENROLLMENT_FILTER,
            'enrollment_filters' => ['version' => 2, 'match' => 'all', 'conditions' => []],
            'is_active' => false,
        ]);

        return redirect()->route('workflows.edit', $workflow);
    }

    public function edit(Request $request, Workflow $workflow): Response
    {
        $this->authorizeWorkflow($request, $workflow);

        $accountId = $request->user()->account_id;

        return Inertia::render('Workflows/Edit', [
            'workflow' => [
                'id' => $workflow->id,
                'name' => $workflow->name,
                'description' => $workflow->description,
                'enrollment_type' => $workflow->enrollment_type,
                'enrollment_filters' => $workflow->enrollment_filters ?? ['version' => 2, 'conditions' => []],
                'trigger_type' => $workflow->trigger_type,
                'allow_reenrollment' => $workflow->allow_reenrollment,
                'reenrollment_cooldown_minutes' => $workflow->reenrollment_cooldown_minutes,
                'goal_filters' => $workflow->goal_filters ?? ['version' => 2, 'conditions' => []],
                'unenroll_when_criteria_lost' => $workflow->unenroll_when_criteria_lost,
                'execution_window' => $workflow->execution_window,
                'is_active' => $workflow->is_active,
            ],
            'tree' => $this->treeFor($workflow),
            'problems' => app(Guardrails::class)->activationProblems($workflow),
            'catalog' => SegmentQuery::catalog(),
            'options' => $this->optionsFor($accountId),
            'stepTypes' => $this->stepTypes(),
            'triggers' => Workflow::TRIGGERS,
            'limits' => [
                'maxSteps' => Guardrails::MAX_STEPS_PER_ENROLLMENT,
                'maxPerSweep' => Guardrails::MAX_ENROLLMENTS_PER_SWEEP,
                'maxOutboundPerDay' => Guardrails::MAX_OUTBOUND_PER_LEAD_PER_DAY,
                'minCooldown' => Guardrails::MIN_REENROLLMENT_COOLDOWN_MINUTES,
            ],
            // Leads reales para simular contra uno.
            'sampleLeads' => Lead::forAccount($accountId)->where('status', 'open')
                ->with('contact:id,name')->latest()->limit(25)
                ->get(['id', 'title', 'contact_id'])
                ->map(fn ($l) => ['id' => $l->id, 'label' => $l->contact?->name ?? $l->title]),
        ]);
    }

    public function update(Request $request, Workflow $workflow): RedirectResponse
    {
        $this->authorizeWorkflow($request, $workflow);

        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:500',
            'enrollment_type' => ['required', Rule::in([Workflow::ENROLLMENT_FILTER, Workflow::ENROLLMENT_EVENT])],
            'enrollment_filters' => 'nullable|array',
            'trigger_type' => ['nullable', Rule::in(Workflow::TRIGGERS)],
            'allow_reenrollment' => 'boolean',
            'reenrollment_cooldown_minutes' => 'nullable|integer|min:0|max:100000',
            'goal_filters' => 'nullable|array',
            'unenroll_when_criteria_lost' => 'boolean',
            'execution_window' => 'nullable|array',
        ]);

        try {
            $validated['enrollment_filters'] = SegmentQuery::validate($validated['enrollment_filters'] ?? []);
            $validated['goal_filters'] = SegmentQuery::validate($validated['goal_filters'] ?? []);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['enrollment_filters' => $e->getMessage()]);
        }

        $workflow->update($validated);

        return back()->with('success', 'Workflow guardado.');
    }

    /**
     * Reemplaza el árbol de pasos.
     *
     * **Upsert por id, no borrar y recrear.** Los pasos están referenciados por
     * las esperas pendientes y por `current_step_id`: recrearlos en cada
     * guardado dejaría a los leads que están esperando apuntando a un paso que
     * ya no existe, y su secuencia se cortaría en silencio. Las inscripciones
     * cuyo paso SÍ se eliminó se cierran con motivo, que es lo honesto.
     */
    public function saveSteps(Request $request, Workflow $workflow): RedirectResponse
    {
        $this->authorizeWorkflow($request, $workflow);

        $validated = $request->validate(['steps' => 'present|array']);

        $keep = [];

        DB::transaction(function () use ($workflow, $validated, &$keep) {
            $this->persistLevel($workflow, $validated['steps'], null, null, $keep);

            $removed = WorkflowStep::where('workflow_id', $workflow->id)
                ->whereNotIn('id', $keep ?: ['-'])
                ->pluck('id');

            if ($removed->isNotEmpty()) {
                // Los que estaban esperando en un paso borrado no pueden seguir:
                // se cierran diciendo por qué, en vez de quedar colgados.
                WorkflowEnrollment::where('workflow_id', $workflow->id)
                    ->where('status', WorkflowEnrollment::ACTIVE)
                    ->whereIn('current_step_id', $removed)
                    ->get()
                    ->each(fn ($e) => $e->finish(
                        WorkflowEnrollment::UNENROLLED,
                        'El paso en el que estaba se eliminó al editar el workflow.',
                    ));

                WorkflowStep::whereIn('id', $removed)->delete();
            }
        });

        return back()->with('success', 'Pasos guardados.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<int, string>  $keep
     */
    private function persistLevel(Workflow $workflow, array $steps, ?string $parentId, ?string $branchKey, array &$keep): void
    {
        foreach (array_values($steps) as $position => $node) {
            $type = $node['step_type'] ?? '';

            if (! in_array($type, [...WorkflowStep::ACTIONS, ...WorkflowStep::FLOW], true)) {
                throw ValidationException::withMessages(['steps' => "Paso desconocido: «{$type}»."]);
            }

            $attributes = [
                'workflow_id' => $workflow->id,
                'parent_id' => $parentId,
                'branch_key' => $node['branch_key'] ?? $branchKey,
                'position' => $position,
                'step_type' => $type,
                'config' => $node['config'] ?? [],
            ];

            $existing = ! empty($node['id'])
                ? WorkflowStep::where('workflow_id', $workflow->id)->find($node['id'])
                : null;

            $step = $existing
                ? tap($existing)->update($attributes)
                : WorkflowStep::create($attributes);

            $keep[] = $step->id;

            if (! empty($node['children'])) {
                $this->persistLevel($workflow, $node['children'], $step->id, null, $keep);
            }
        }
    }

    /**
     * Activa o desactiva.
     *
     * Activar exige que no haya problemas de configuración: es más barato
     * frenar acá que explicar después por qué le llegaron seis mensajes a un
     * cliente.
     */
    public function toggle(Request $request, Workflow $workflow): RedirectResponse
    {
        $this->authorizeWorkflow($request, $workflow);

        if ($workflow->is_active) {
            $workflow->update(['is_active' => false]);

            return back()->with('success', 'Workflow desactivado.');
        }

        $problems = app(Guardrails::class)->activationProblems($workflow);

        if ($problems !== []) {
            throw ValidationException::withMessages(['is_active' => implode(' ', $problems)]);
        }

        $workflow->update(['is_active' => true]);

        return back()->with('success', 'Workflow activado.');
    }

    /** Kill switch de la cuenta: para todos los workflows sin deploy. */
    public function togglePause(Request $request): RedirectResponse
    {
        $account = $request->user()->account;
        $account->forceFill(['workflows_paused_at' => $account->workflows_paused_at ? null : now()])->save();

        return back()->with('success', $account->workflows_paused_at
            ? 'Workflows en pausa: no se ejecuta nada.'
            : 'Workflows reanudados.');
    }

    /** Simula el árbol **que está en pantalla** contra un lead real. */
    public function simulate(Request $request, Workflow $workflow): JsonResponse
    {
        $this->authorizeWorkflow($request, $workflow);

        // `Validator` a mano y no `$request->validate()`: en el grupo `web` un
        // fallo de validación devuelve 302 y el fetch recibe HTML.
        $validator = validator($request->all(), [
            'lead_id' => 'required|uuid',
            'steps' => 'present|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $lead = Lead::forAccount($workflow->account_id)
            ->with(['contact', 'stage', 'responsible', 'account.integration'])
            ->find($request->input('lead_id'));

        if (! $lead) {
            return response()->json(['message' => 'El lead no pertenece a la cuenta.'], 422);
        }

        return response()->json([
            'lead' => $lead->contact?->name ?? $lead->title,
            'steps' => app(WorkflowSimulator::class)->run($lead, $request->input('steps', [])),
        ]);
    }

    /** Cuántos leads entrarían hoy con el criterio de inscripción. */
    public function enrollmentCount(Request $request, Workflow $workflow): JsonResponse
    {
        $this->authorizeWorkflow($request, $workflow);

        try {
            $definition = SegmentQuery::validate($request->input('filters', []));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $matching = SegmentQuery::for($request->user())
            ->apply(Lead::forAccount($workflow->account_id), $definition, openOnly: true)
            ->count();

        return response()->json([
            'matching' => $matching,
            // Con el tope por pasada, activar un filtro enorme no dispara todo
            // de golpe: decirlo acá evita el susto.
            'firstSweep' => min($matching, Guardrails::MAX_ENROLLMENTS_PER_SWEEP),
        ]);
    }

    public function destroy(Request $request, Workflow $workflow): RedirectResponse
    {
        $this->authorizeWorkflow($request, $workflow);
        $workflow->delete();

        return redirect()->route('workflows.index')->with('success', 'Workflow eliminado.');
    }

    private function authorizeWorkflow(Request $request, Workflow $workflow): void
    {
        abort_if($workflow->account_id !== $request->user()->account_id, 403);
    }

    /** @return array<int, array<string, string>> */
    private function stepTypes(): array
    {
        return [
            ['type' => 'send_whatsapp', 'label' => 'Enviar WhatsApp', 'group' => 'Acciones'],
            ['type' => 'create_task', 'label' => 'Crear tarea', 'group' => 'Acciones'],
            ['type' => 'add_note', 'label' => 'Dejar nota', 'group' => 'Acciones'],
            ['type' => 'add_tag', 'label' => 'Agregar etiqueta', 'group' => 'Acciones'],
            ['type' => 'remove_tag', 'label' => 'Quitar etiqueta', 'group' => 'Acciones'],
            ['type' => 'change_stage', 'label' => 'Mover de etapa', 'group' => 'Acciones'],
            ['type' => 'assign_responsible', 'label' => 'Asignar responsable', 'group' => 'Acciones'],
            ['type' => 'notify_user', 'label' => 'Avisar al equipo', 'group' => 'Acciones'],
            ['type' => 'wait', 'label' => 'Esperar (minutos)', 'group' => 'Flujo'],
            ['type' => 'wait_until', 'label' => 'Esperar hasta una hora', 'group' => 'Flujo'],
            ['type' => 'branch', 'label' => 'Bifurcar segun condicion', 'group' => 'Flujo'],
            ['type' => 'end', 'label' => 'Terminar', 'group' => 'Flujo'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function treeFor(Workflow $workflow): array
    {
        $steps = $workflow->steps()->get()->groupBy(fn ($s) => $s->parent_id ?? 'root');

        $build = function (string $key) use (&$build, $steps): array {
            return ($steps[$key] ?? collect())
                ->sortBy('position')
                ->map(fn (WorkflowStep $s) => [
                    'id' => $s->id,
                    'step_type' => $s->step_type,
                    'branch_key' => $s->branch_key,
                    'config' => $s->config ?? [],
                    'children' => $build($s->id),
                ])
                ->values()
                ->all();
        };

        return $build('root');
    }

    /** @return array<string, mixed> */
    private function optionsFor(string $accountId): array
    {
        return [
            'stage' => Pipeline::forAccount($accountId)->with('stages')->get()
                ->flatMap(fn ($p) => $p->stages->map(fn ($s) => [
                    'value' => $s->id, 'label' => "{$p->name} · {$s->name}", 'color' => $s->color,
                ]))->values(),
            'pipeline' => Pipeline::forAccount($accountId)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($p) => ['value' => $p->id, 'label' => $p->name]),
            'tag' => Tag::forAccount($accountId)->orderBy('name')->get(['id', 'name', 'color'])
                ->map(fn ($t) => ['value' => $t->id, 'label' => $t->name, 'color' => $t->color]),
            'company' => Company::forAccount($accountId)->orderBy('name')->limit(500)->get(['id', 'name'])
                ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name]),
            'user' => User::where('account_id', $accountId)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['value' => $u->id, 'label' => $u->name]),
            'status' => [
                ['value' => 'open', 'label' => 'Abierto'],
                ['value' => 'won', 'label' => 'Ganado'],
                ['value' => 'lost', 'label' => 'Perdido'],
            ],
            'source' => collect([
                'whatsapp' => 'WhatsApp', 'booking' => 'Formulario de reserva',
                'lead_ad' => 'Meta Lead Ad', 'web_form' => 'Formulario web',
                'manual' => 'Manual', 'api' => 'API externa', 'email' => 'Correo',
            ])->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'band' => [
                ['value' => 'caliente', 'label' => 'Caliente'],
                ['value' => 'tibio', 'label' => 'Tibio'],
                ['value' => 'frio', 'label' => 'Frío'],
            ],
        ];
    }
}
