<?php

namespace App\Services\Workflows;

use App\Models\Lead;
use App\Models\Workflow;
use App\Models\WorkflowEnrollment;
use App\Services\Leads\SegmentQuery;

/**
 * El barredor: lo que hace que la inscripción sea **dinámica**.
 *
 * Cada pasada evalúa el filtro de inscripción de los workflows activos e
 * inscribe a quien empezó a cumplirlo. Un lead creado hace tres meses que hoy
 * entra en «Negociación sin tarea» entra hoy, sin que nadie lo toque. Eso es lo
 * que `stage_automations` no puede hacer: reacciona a un evento, no a un estado.
 *
 * También cierra el ciclo por el otro lado: saca a los que cumplieron la meta y
 * —si está configurado— a los que dejaron de cumplir el criterio.
 */
class EnrollmentSweeper
{
    public function __construct(
        private readonly WorkflowEngine $engine = new WorkflowEngine,
        private readonly Guardrails $guardrails = new Guardrails,
    ) {}

    /**
     * @return array{enrolled: int, goals: int, unenrolled: int}
     */
    public function sweep(Workflow $workflow): array
    {
        if (! $this->guardrails->workflowCanRun($workflow)) {
            return ['enrolled' => 0, 'goals' => 0, 'unenrolled' => 0];
        }

        $result = [
            'goals' => $this->closeGoals($workflow),
            'unenrolled' => $this->dropLostCriteria($workflow),
            'enrolled' => 0,
        ];

        if ($workflow->enrollment_type === Workflow::ENROLLMENT_FILTER) {
            $result['enrolled'] = $this->enrollMatching($workflow);
        }

        $workflow->forceFill(['last_swept_at' => now()])->save();

        return $result;
    }

    /**
     * Inscribe a los que cumplen el filtro y todavía no están.
     *
     * **Por lotes.** Si alguien activa un workflow cuyo filtro matchea 4.000
     * leads, la primera pasada no puede disparar 4.000 secuencias: se toman
     * `MAX_ENROLLMENTS_PER_SWEEP` y el resto entra en las siguientes pasadas.
     * Un envío masivo accidental no se arregla pidiendo disculpas.
     */
    private function enrollMatching(Workflow $workflow): int
    {
        $owner = $workflow->account->owner;

        // Los que ya están activos quedan fuera del candidato; los cerrados se
        // dejan pasar para que `Guardrails::canEnroll` decida si la
        // re-inscripción corresponde (y aplique el enfriamiento).
        $activos = WorkflowEnrollment::where('workflow_id', $workflow->id)
            ->where('status', WorkflowEnrollment::ACTIVE)
            ->pluck('lead_id');

        $candidates = SegmentQuery::for($owner)
            ->apply(
                Lead::forAccount($workflow->account_id)->whereNotIn('id', $activos),
                $workflow->enrollment_filters ?? ['version' => 2, 'conditions' => []],
                openOnly: true,
            )
            ->limit(Guardrails::MAX_ENROLLMENTS_PER_SWEEP)
            ->get();

        $enrolled = 0;

        foreach ($candidates as $lead) {
            if ($this->engine->enroll($workflow, $lead, 'criterios')) {
                $enrolled++;
            }
        }

        return $enrolled;
    }

    /**
     * Saca a los que cumplieron la meta.
     *
     * Sin esto, un cliente que ya compró sigue recibiendo «¿seguís
     * interesado?» hasta que la secuencia termine — que es la forma más rápida
     * de que un equipo apague las automatizaciones para siempre.
     */
    private function closeGoals(Workflow $workflow): int
    {
        if (empty($workflow->goal_filters['conditions'] ?? [])) {
            return 0;
        }

        return $this->finishMatching(
            $workflow,
            $workflow->goal_filters,
            WorkflowEnrollment::GOAL_MET,
            'Cumplió la meta del workflow.',
        );
    }

    /** Saca a los que dejaron de cumplir el criterio de inscripción. */
    private function dropLostCriteria(Workflow $workflow): int
    {
        if (! $workflow->unenroll_when_criteria_lost
            || $workflow->enrollment_type !== Workflow::ENROLLMENT_FILTER) {
            return 0;
        }

        $owner = $workflow->account->owner;

        $activos = WorkflowEnrollment::where('workflow_id', $workflow->id)
            ->where('status', WorkflowEnrollment::ACTIVE)
            ->get();

        if ($activos->isEmpty()) {
            return 0;
        }

        $siguenCumpliendo = SegmentQuery::for($owner)
            ->apply(
                Lead::forAccount($workflow->account_id)->whereIn('id', $activos->pluck('lead_id')),
                $workflow->enrollment_filters ?? ['version' => 2, 'conditions' => []],
                openOnly: true,
            )
            ->pluck('id')
            ->flip();

        $count = 0;

        foreach ($activos as $enrollment) {
            if (! isset($siguenCumpliendo[$enrollment->lead_id])) {
                $enrollment->finish(WorkflowEnrollment::UNENROLLED, 'Dejó de cumplir el criterio de inscripción.');
                $count++;
            }
        }

        return $count;
    }

    /** @param array<string, mixed> $definition */
    private function finishMatching(Workflow $workflow, array $definition, string $status, string $detail): int
    {
        $activos = WorkflowEnrollment::where('workflow_id', $workflow->id)
            ->where('status', WorkflowEnrollment::ACTIVE)
            ->get();

        if ($activos->isEmpty()) {
            return 0;
        }

        $matching = SegmentQuery::for($workflow->account->owner)
            ->apply(
                Lead::forAccount($workflow->account_id)->whereIn('id', $activos->pluck('lead_id')),
                $definition,
            )
            ->pluck('id')
            ->flip();

        $count = 0;

        foreach ($activos as $enrollment) {
            if (isset($matching[$enrollment->lead_id])) {
                $enrollment->finish($status, $detail);
                $count++;
            }
        }

        return $count;
    }
}
