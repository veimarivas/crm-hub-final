<?php

namespace App\Services\Workflows;

use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\Tag;
use App\Services\Leads\SegmentQuery;
use App\Services\WhatsApp\ServiceWindow;

/**
 * Vista previa de qué pasaría si un lead entrara al workflow.
 *
 * **No escribe nada**: no manda WhatsApp, no crea tareas ni notas, no etiqueta,
 * no mueve etapas, no registra eventos. Recorre el árbol **que está en
 * pantalla** —no lo guardado— para que se pueda probar antes de guardar y
 * antes de activar.
 *
 * Es el guardarraíl que más trabajo ahorra: dice los mismos motivos por los que
 * el motor fallaría (integración inactiva, lead sin teléfono, etiqueta borrada,
 * etapa de otro pipeline) **antes** de que le llegue algo a un cliente.
 */
class WorkflowSimulator
{
    /** Tope de pasos a simular; el árbol en pantalla puede tener un ciclo. */
    private const MAX_STEPS = 30;

    /**
     * @param  array<int, array<string, mixed>>  $tree  pasos anidados con `children`
     * @return array<int, array<string, mixed>>
     */
    public function run(Lead $lead, array $tree): array
    {
        $out = [];
        $this->walk($lead, $tree, $out, false);

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<int, array<string, mixed>>  $out
     * @param  bool  $later  ya se cruzó una espera: lo que sigue no es «ahora»
     */
    private function walk(Lead $lead, array $steps, array &$out, bool $later): void
    {
        foreach ($steps as $step) {
            if (count($out) >= self::MAX_STEPS) {
                $out[] = ['type' => 'aviso', 'status' => 'skipped', 'detail' => 'Se cortó la simulación: demasiados pasos.'];

                return;
            }

            $type = $step['step_type'] ?? '';
            $config = $step['config'] ?? [];

            if ($type === 'branch') {
                $matches = $this->evaluateBranch($lead, $config);
                $key = $matches ? 'yes' : 'no';

                $out[] = [
                    'type' => $type,
                    'status' => 'ok',
                    'detail' => "Este lead tomaría la rama «{$key}».",
                ];

                foreach (($step['children'] ?? []) as $child) {
                    // La rama no tomada se muestra igual, marcada: sirve para
                    // ver que el otro camino existe y a dónde lleva.
                    $taken = ($child['branch_key'] ?? null) === $key;
                    $this->walk($lead, [$child], $out, $later || ! $taken);
                    if (! $taken) {
                        $out[count($out) - 1]['status'] = 'skipped';
                        $out[count($out) - 1]['detail'] = 'Rama no tomada por este lead.';
                    }
                }

                continue;
            }

            if (in_array($type, ['wait', 'wait_until'], true)) {
                $out[] = [
                    'type' => $type,
                    'status' => 'later',
                    'detail' => $type === 'wait'
                        ? 'Espera '.max(1, (int) ($config['minutes'] ?? 60)).' min.'
                        : 'Espera hasta las '.($config['time'] ?? '09:00').'.',
                ];
                $later = true;

                continue;
            }

            $out[] = ['type' => $type, 'later' => $later] + $this->preview($lead, $type, $config);

            if (isset($step['children'])) {
                $this->walk($lead, $step['children'], $out, $later);
            }
        }
    }

    /**
     * Qué haría este paso, o por qué no podría.
     *
     * @return array{status: string, detail: string}
     */
    private function preview(Lead $lead, string $type, array $config): array
    {
        $runner = new StepRunner;

        return match ($type) {
            'send_whatsapp' => $this->previewWhatsapp($lead, $config, $runner),
            'create_task' => [
                'status' => 'ok',
                'detail' => 'Crearía la tarea «'.$runner->interpolate($config['text'] ?? 'Dar seguimiento', $lead)
                    .'», con vencimiento en '.max(1, (int) ($config['due_in_hours'] ?? 24)).' h para '
                    .($lead->responsible?->name ?? 'nadie (el lead no tiene responsable)').'.',
            ],
            'add_note' => trim($runner->interpolate($config['text'] ?? '', $lead)) === ''
                ? ['status' => 'failed', 'detail' => 'La nota quedaría vacía.']
                : ['status' => 'ok', 'detail' => 'Dejaría una nota en el lead.'],
            'add_tag', 'remove_tag' => $this->previewTag($lead, $config, $type),
            'change_stage' => $this->previewStage($lead, $config),
            'assign_responsible' => empty($config['user_id'])
                ? ['status' => 'failed', 'detail' => 'No se eligió a quién asignar.']
                : ['status' => 'ok', 'detail' => 'Cambiaría el responsable del lead.'],
            'notify_user' => ($config['user_id'] ?? null) || $lead->responsible_user_id
                ? ['status' => 'ok', 'detail' => 'Avisaría por notificación interna.']
                : ['status' => 'failed', 'detail' => 'No hay a quién avisar: el lead no tiene responsable.'],
            'end' => ['status' => 'ok', 'detail' => 'Fin del workflow.'],
            default => ['status' => 'failed', 'detail' => "Paso desconocido: «{$type}»."],
        };
    }

    private function previewWhatsapp(Lead $lead, array $config, StepRunner $runner): array
    {
        if (! $lead->account->integration?->is_active) {
            return ['status' => 'failed', 'detail' => 'La integración con WhatsApp está inactiva: no se enviaría nada.'];
        }

        if (! $lead->contact?->phone) {
            return ['status' => 'failed', 'detail' => 'El lead no tiene contacto con teléfono.'];
        }

        if (trim($runner->interpolate($config['text'] ?? '', $lead)) === '') {
            return ['status' => 'failed', 'detail' => 'El mensaje quedaría vacío tras interpolar.'];
        }

        $window = app(ServiceWindow::class)->forLead($lead);

        if (! ($window['is_open'] ?? false)) {
            return ($config['outside_window'] ?? 'skip') === 'task'
                ? ['status' => 'skipped', 'detail' => 'Fuera de ventana: crearía una tarea en vez de escribir.']
                : ['status' => 'failed', 'detail' => 'Fuera de la ventana de servicio: no se enviaría (y no se paga plantilla).'];
        }

        return ['status' => 'ok', 'detail' => 'Enviaría el mensaje por WhatsApp.'];
    }

    private function previewTag(Lead $lead, array $config, string $type): array
    {
        $tag = Tag::forAccount($lead->account_id)->find($config['tag_id'] ?? null);

        if (! $tag) {
            return ['status' => 'failed', 'detail' => 'La etiqueta configurada ya no existe.'];
        }

        return [
            'status' => 'ok',
            'detail' => ($type === 'add_tag' ? 'Agregaría' : 'Quitaría')." la etiqueta «{$tag->name}».",
        ];
    }

    private function previewStage(Lead $lead, array $config): array
    {
        $stage = PipelineStage::find($config['stage_id'] ?? null);

        if (! $stage || $stage->pipeline_id !== $lead->pipeline_id) {
            return ['status' => 'failed', 'detail' => 'La etapa configurada no pertenece al pipeline del lead.'];
        }

        return ['status' => 'ok', 'detail' => "Movería el lead a «{$stage->name}»."];
    }

    private function evaluateBranch(Lead $lead, array $config): bool
    {
        try {
            return SegmentQuery::for($lead->account->owner)
                ->apply(Lead::whereKey($lead->id), $config['filters'] ?? ['version' => 2, 'conditions' => []])
                ->exists();
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
