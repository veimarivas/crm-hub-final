<?php

namespace App\Services\Workflows;

use App\Models\Lead;
use App\Models\Workflow;
use App\Models\WorkflowEnrollment;
use App\Models\WorkflowPendingExecution;
use App\Models\WorkflowStep;
use App\Services\Leads\SegmentQuery;

/**
 * El motor: inscribe leads, los hace avanzar por el árbol y los saca cuando
 * corresponde.
 *
 * Recorre pasos hasta toparse con una espera, el final del árbol o un tope de
 * `Guardrails`. Nunca corre indefinidamente y nunca ejecuta dos veces el mismo
 * paso de la misma corrida.
 */
class WorkflowEngine
{
    public function __construct(
        private readonly Guardrails $guardrails = new Guardrails,
        private readonly StepRunner $runner = new StepRunner,
    ) {}

    /**
     * Inscribe un lead, si los guardarraíles lo permiten.
     *
     * La re-inscripción **reusa la fila** en vez de crear otra: así el índice
     * único `(workflow_id, lead_id)` vale siempre y la garantía vive en la
     * base, no en el código. El historial de pasos queda en
     * `workflow_step_runs`, discriminado por `enroll_count`.
     */
    public function enroll(Workflow $workflow, Lead $lead, string $reason = 'filtro'): ?WorkflowEnrollment
    {
        $existing = WorkflowEnrollment::where('workflow_id', $workflow->id)
            ->where('lead_id', $lead->id)
            ->first();

        if (! $this->guardrails->canEnroll($workflow, $lead, $existing)['ok']) {
            return null;
        }

        $enrollment = $existing ?? new WorkflowEnrollment([
            'account_id' => $workflow->account_id,
            'workflow_id' => $workflow->id,
            'lead_id' => $lead->id,
        ]);

        $enrollment->forceFill([
            'account_id' => $workflow->account_id,
            'workflow_id' => $workflow->id,
            'lead_id' => $lead->id,
            'status' => WorkflowEnrollment::ACTIVE,
            'enroll_reason' => $reason,
            'enroll_count' => ($existing?->enroll_count ?? 0) + 1,
            'steps_run' => 0,
            'enrolled_at' => now(),
            'finished_at' => null,
            'current_step_id' => null,
        ])->save();

        $first = $workflow->steps()->whereNull('parent_id')->orderBy('position')->first();

        if (! $first) {
            $enrollment->finish(WorkflowEnrollment::COMPLETED, 'El workflow no tiene pasos.');

            return $enrollment;
        }

        $this->advance($enrollment, $first);

        return $enrollment->refresh();
    }

    /**
     * Corre pasos desde `$step` hasta que haya que esperar o no quede nada.
     *
     * Iterativo y no recursivo: una cadena larga no puede desbordar la pila, y
     * el contador de pasos es lo que corta un ciclo.
     */
    public function advance(WorkflowEnrollment $enrollment, ?WorkflowStep $step): void
    {
        $workflow = $enrollment->workflow;

        while ($step) {
            if (! $this->guardrails->workflowCanRun($workflow)) {
                return; // pausado: se retoma cuando se reactive
            }

            // La meta se revisa ANTES de cada paso, no solo en el barrido: si
            // el lead se ganó mientras esperaba, no tiene que recibir el resto
            // de la secuencia.
            if ($this->goalMet($workflow, $enrollment)) {
                $enrollment->finish(WorkflowEnrollment::GOAL_MET, 'El lead cumplió la meta del workflow.');

                return;
            }

            $allowed = $this->guardrails->canRunStep($step, $enrollment);

            if (! $allowed['ok']) {
                $this->trace($enrollment, $step, 'skipped', $allowed['reason']);
                $enrollment->finish(WorkflowEnrollment::FAILED, $allowed['reason']);

                return;
            }

            // Esperas: se agenda y se corta el recorrido acá.
            if (in_array($step->step_type, ['wait', 'wait_until'], true)) {
                $this->scheduleWait($enrollment, $step, $workflow);

                return;
            }

            if ($step->step_type === 'end') {
                $this->trace($enrollment, $step, 'ok', 'Fin del workflow.');
                $enrollment->finish(WorkflowEnrollment::COMPLETED);

                return;
            }

            $step = $step->step_type === 'branch'
                ? $this->runBranch($enrollment, $step)
                : $this->runAction($enrollment, $step);
        }

        $enrollment->finish(WorkflowEnrollment::COMPLETED);
    }

    /** Retoma una espera vencida. */
    public function resume(WorkflowPendingExecution $pending): void
    {
        $enrollment = $pending->enrollment;
        $step = $pending->step;
        $pending->delete();

        if (! $enrollment || ! $enrollment->isActive() || ! $step) {
            return;
        }

        // Al despertar se sigue por lo que viene DESPUÉS de la espera.
        $this->advance($enrollment, $this->nextAfter($step));
    }

    /**
     * Ejecuta una acción y devuelve el paso siguiente.
     *
     * Un fallo **no mata la inscripción**: queda registrado y se sigue. Que no
     * se pueda mandar un WhatsApp por falta de teléfono no es razón para no
     * crear después la tarea de seguimiento.
     */
    private function runAction(WorkflowEnrollment $enrollment, WorkflowStep $step): ?WorkflowStep
    {
        $key = $this->guardrails->idempotencyKey($enrollment, $step);

        // Idempotencia: si esta corrida ya ejecutó este paso, no se repite.
        if (\App\Models\WorkflowStepRun::where('idempotency_key', $key)->exists()) {
            return $this->nextAfter($step);
        }

        $lead = $enrollment->lead;
        $config = $step->config ?? [];

        try {
            $detail = match ($step->step_type) {
                'send_whatsapp' => $this->runner->sendWhatsapp($lead, $config),
                'create_task' => $this->runner->createTask($lead, $config),
                'add_note' => $this->runner->addNote($lead, $config),
                'add_tag' => $this->runner->addTag($lead, $config),
                'remove_tag' => $this->runner->removeTag($lead, $config),
                'change_stage' => $this->runner->changeStage($lead, $config),
                'assign_responsible' => $this->runner->assignResponsible($lead, $config),
                'notify_user' => $this->runner->notifyUser($lead, $config),
                default => throw new \RuntimeException("Paso desconocido: «{$step->step_type}»."),
            };

            $this->trace($enrollment, $step, 'ok', $detail, $key);
        } catch (\Throwable $e) {
            $this->trace($enrollment, $step, 'failed', $e->getMessage());
        }

        $enrollment->increment('steps_run');
        $enrollment->forceFill(['current_step_id' => $step->id])->save();

        return $this->nextAfter($step->refresh());
    }

    /**
     * Elige la rama: el primer hijo cuyo `branch_key` coincide con el valor
     * evaluado, o el hijo `else` si ninguno coincide.
     */
    private function runBranch(WorkflowEnrollment $enrollment, WorkflowStep $step): ?WorkflowStep
    {
        $config = $step->config ?? [];
        $children = $step->children;

        // La rama se evalúa con el MISMO motor de segmentos que la inscripción:
        // un criterio significa lo mismo en los dos lugares.
        $matches = SegmentQuery::for($enrollment->lead->responsible ?? $enrollment->workflow->account->owner)
            ->apply(
                Lead::whereKey($enrollment->lead_id),
                $config['filters'] ?? ['version' => 2, 'conditions' => []],
            )->exists();

        $key = $matches ? 'yes' : 'no';
        $branch = $children->firstWhere('branch_key', $key) ?? $children->firstWhere('branch_key', 'else');

        $this->trace($enrollment, $step, 'ok', "Rama tomada: {$key}.");
        $enrollment->increment('steps_run');

        return $branch ?? $this->nextAfter($step);
    }

    /** Agenda la espera y corta el recorrido. */
    private function scheduleWait(WorkflowEnrollment $enrollment, WorkflowStep $step, Workflow $workflow): void
    {
        $config = $step->config ?? [];

        $runAt = $step->step_type === 'wait'
            ? now()->addMinutes(max(1, (int) ($config['minutes'] ?? 60)))
            : $this->nextCalendarMoment($config);

        // La ventana del workflow manda sobre el reloj: si la espera vence a
        // las 3 AM, se corre a la próxima apertura.
        if (! $workflow->withinExecutionWindow($runAt)) {
            $runAt = $workflow->nextWindowOpening($runAt);
        }

        WorkflowPendingExecution::updateOrCreate(
            ['enrollment_id' => $enrollment->id],
            ['step_id' => $step->id, 'run_at' => $runAt],
        );

        $enrollment->increment('steps_run');
        $enrollment->forceFill(['current_step_id' => $step->id])->save();

        $this->trace($enrollment, $step, 'ok', 'Espera hasta '.$runAt->toDateTimeString().'.');
    }

    /**
     * `wait_until`: hasta una hora del día, opcionalmente de un día de semana.
     */
    private function nextCalendarMoment(array $config): \Carbon\CarbonInterface
    {
        [$hour, $minute] = array_pad(array_map('intval', explode(':', $config['time'] ?? '09:00')), 2, 0);

        $target = now()->copy()->setTime($hour, $minute);

        if ($target->lessThanOrEqualTo(now())) {
            $target = $target->addDay();
        }

        if (! empty($config['weekday'])) {
            while ($target->dayOfWeekIso !== (int) $config['weekday']) {
                $target = $target->addDay();
            }
        }

        return $target;
    }

    /**
     * Siguiente paso del mismo nivel; si no hay, sube al padre y busca su
     * hermano. Así una rama que termina continúa con el tronco.
     */
    private function nextAfter(WorkflowStep $step): ?WorkflowStep
    {
        $sibling = WorkflowStep::where('workflow_id', $step->workflow_id)
            ->where('parent_id', $step->parent_id)
            ->where('branch_key', $step->branch_key)
            ->where('position', '>', $step->position)
            ->orderBy('position')
            ->first();

        if ($sibling) {
            return $sibling;
        }

        $parent = $step->parent_id ? WorkflowStep::find($step->parent_id) : null;

        return $parent ? $this->nextAfter($parent) : null;
    }

    /** ¿El lead cumple la meta del workflow? */
    private function goalMet(Workflow $workflow, WorkflowEnrollment $enrollment): bool
    {
        $goal = $workflow->goal_filters;

        if (empty($goal['conditions'] ?? [])) {
            return false;
        }

        return SegmentQuery::for($workflow->account->owner)
            ->apply(Lead::whereKey($enrollment->lead_id), $goal)
            ->exists();
    }

    private function trace(
        WorkflowEnrollment $enrollment,
        ?WorkflowStep $step,
        string $status,
        ?string $detail = null,
        ?string $key = null,
    ): void {
        $enrollment->stepRuns()->create([
            'step_id' => $step?->id,
            'status' => $status,
            'detail' => $detail ? mb_substr($detail, 0, 500) : null,
            'idempotency_key' => $key,
        ]);
    }
}
