<?php

namespace App\Services\Workflows;

use App\Models\Lead;
use App\Models\Workflow;
use App\Models\WorkflowEnrollment;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;

/**
 * Los topes que impiden que esto le escriba de más a clientes reales.
 *
 * Van en clase propia y **se escribieron antes que el motor**, no después:
 * hay dos formas de que un sistema así se dispare solo, y las dos terminan en
 * mensajes de WhatsApp a personas y en factura de Meta.
 *
 *  1. **El bucle.** Un paso `change_stage` con disparador `stage_changed` se
 *     llama a sí mismo.
 *  2. **El barredor.** Un filtro de inscripción sin re-inscripción bien
 *     configurada reinscribe al mismo lead en cada pasada — cada 10 minutos.
 *
 * Ningún tope de acá se puede desactivar desde la configuración de un
 * workflow. Son del sistema, no del usuario.
 */
class Guardrails
{
    /** Pasos que puede recorrer una inscripción antes de darse por fallida. */
    public const MAX_STEPS_PER_ENROLLMENT = 50;

    /** Inscripciones que puede crear una sola pasada del barredor, por workflow. */
    public const MAX_ENROLLMENTS_PER_SWEEP = 200;

    /** Acciones salientes por lead y por día, sumando TODOS los workflows. */
    public const MAX_OUTBOUND_PER_LEAD_PER_DAY = 3;

    /** Enfriamiento mínimo exigido si se habilita re-inscripción. */
    public const MIN_REENROLLMENT_COOLDOWN_MINUTES = 60;

    /**
     * ¿Puede este workflow ejecutar algo ahora?
     *
     * El kill switch de la cuenta gana sobre todo lo demás: es el botón que
     * para todo sin deploy cuando algo se descontroló.
     */
    public function workflowCanRun(Workflow $workflow): bool
    {
        return $workflow->is_active && ! $workflow->account?->workflows_paused_at;
    }

    /**
     * ¿Se puede inscribir este lead?
     *
     * @return array{ok: bool, reason?: string}
     */
    public function canEnroll(Workflow $workflow, Lead $lead, ?WorkflowEnrollment $existing): array
    {
        if (! $this->workflowCanRun($workflow)) {
            return ['ok' => false, 'reason' => 'El workflow está inactivo o la cuenta tiene los workflows en pausa.'];
        }

        if (! $existing) {
            return ['ok' => true];
        }

        if ($existing->isActive()) {
            return ['ok' => false, 'reason' => 'El lead ya está en este workflow.'];
        }

        if (! $workflow->allow_reenrollment) {
            // El caso que más importa: sin esto, el barredor reinscribe al
            // mismo lead cada pasada y el cliente recibe el mismo mensaje una
            // y otra vez.
            return ['ok' => false, 'reason' => 'El workflow no admite re-inscripción.'];
        }

        $cooldown = max(
            self::MIN_REENROLLMENT_COOLDOWN_MINUTES,
            (int) ($workflow->reenrollment_cooldown_minutes ?? 0),
        );

        if ($existing->finished_at && $existing->finished_at->diffInMinutes(now(), true) < $cooldown) {
            return ['ok' => false, 'reason' => "Todavía dentro del enfriamiento de {$cooldown} min."];
        }

        return ['ok' => true];
    }

    /**
     * ¿Puede correr este paso para este lead?
     *
     * El tope de salientes es **por lead y por día across workflows**: tres
     * workflows con un mensaje cada uno son tres mensajes para el cliente, y a
     * él no le importa cuántas automatizaciones tenga la empresa.
     *
     * @return array{ok: bool, reason?: string}
     */
    public function canRunStep(WorkflowStep $step, WorkflowEnrollment $enrollment): array
    {
        if ($enrollment->steps_run >= self::MAX_STEPS_PER_ENROLLMENT) {
            return ['ok' => false, 'reason' => 'Se alcanzó el tope de pasos: puede haber un ciclo en el workflow.'];
        }

        if (! $step->isOutbound()) {
            return ['ok' => true];
        }

        $today = WorkflowStepRun::whereHas('enrollment', fn ($q) => $q->where('lead_id', $enrollment->lead_id))
            ->where('status', 'ok')
            ->where('created_at', '>=', now()->startOfDay())
            ->whereHas('step', fn ($q) => $q->whereIn('step_type', WorkflowStep::OUTBOUND))
            ->count();

        if ($today >= self::MAX_OUTBOUND_PER_LEAD_PER_DAY) {
            return ['ok' => false, 'reason' => 'Tope diario de mensajes automáticos para este lead.'];
        }

        return ['ok' => true];
    }

    /**
     * Clave de idempotencia de un paso dentro de una inscripción.
     *
     * Incluye `enroll_count` para que una re-inscripción legítima **sí** pueda
     * volver a mandar el mensaje, pero un reintento de la misma corrida no.
     */
    public function idempotencyKey(WorkflowEnrollment $enrollment, WorkflowStep $step): string
    {
        return "{$enrollment->id}:{$enrollment->enroll_count}:{$step->id}";
    }

    /**
     * Problemas que impiden activar un workflow. Lista vacía = se puede activar.
     *
     * Un workflow nace inactivo y no se activa sin pasar por acá: es más barato
     * frenar una configuración incompleta que explicar después por qué le
     * llegaron seis mensajes a un cliente.
     *
     * @return array<int, string>
     */
    public function activationProblems(Workflow $workflow): array
    {
        $problems = [];

        if ($workflow->steps()->count() === 0) {
            $problems[] = 'El workflow no tiene ningún paso.';
        }

        if ($workflow->enrollment_type === Workflow::ENROLLMENT_FILTER && empty($workflow->enrollment_filters['conditions'] ?? [])) {
            // Un filtro vacío matchea a TODOS los leads de la cuenta.
            $problems[] = 'La inscripción por criterios está vacía: alcanzaría a todos los leads.';
        }

        if ($workflow->enrollment_type === Workflow::ENROLLMENT_EVENT && ! $workflow->trigger_type) {
            $problems[] = 'Falta elegir el evento que inscribe.';
        }

        if ($workflow->allow_reenrollment
            && (int) $workflow->reenrollment_cooldown_minutes < self::MIN_REENROLLMENT_COOLDOWN_MINUTES) {
            $problems[] = 'Con re-inscripción habilitada hace falta un enfriamiento de al menos '
                .self::MIN_REENROLLMENT_COOLDOWN_MINUTES.' minutos.';
        }

        return $problems;
    }
}
