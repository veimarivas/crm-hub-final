<?php

namespace App\Services\DigitalPipeline;

use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\StageAutomation;
use App\Models\User;

/**
 * Muestra qué pasaría si un lead entrara a una etapa, SIN hacerlo:
 * no manda WhatsApp, no crea tareas ni notas, no registra eventos.
 *
 * Existe porque los fallos del Runner son invisibles: `execute()` los
 * atrapa y solo deja un `Log::warning`. Con la integración apagada o un
 * lead sin teléfono, la automatización "no hace nada" y en pantalla
 * seguía figurando como activa.
 */
class Simulator
{
    /** @return array<string, mixed> */
    public function preview(PipelineStage $stage, ?Lead $lead): array
    {
        $automations = StageAutomation::forAccount($stage->pipeline->account_id)
            ->where('stage_id', $stage->id)
            ->orderBy('created_at')
            ->get();

        return [
            'stage' => ['id' => $stage->id, 'name' => $stage->name],
            'lead' => $lead ? [
                'id' => $lead->id,
                'title' => $lead->title,
                'contact' => $lead->contact?->name,
                'phone' => $lead->contact?->phone,
            ] : null,
            'steps' => $automations->map(fn (StageAutomation $a) => $this->describe($a, $lead))->all(),
        ];
    }

    private function describe(StageAutomation $automation, ?Lead $lead): array
    {
        $config = $automation->config ?? [];
        $text = $this->interpolate($config['text'] ?? '', $lead);

        [$status, $note] = $this->check($automation, $lead);

        $detail = match ($automation->action_type) {
            'send_whatsapp' => $text,
            'create_task' => $text,
            'add_note' => $text,
            default => $text,
        };

        return [
            'id' => $automation->id,
            'action_type' => $automation->action_type,
            'is_active' => $automation->is_active,
            'status' => $status,
            'note' => $note,
            'detail' => $detail,
            'meta' => $automation->action_type === 'create_task' ? [
                'task_type' => $config['task_type'] ?? 'follow_up',
                'due_at' => now()->addHours(max(1, (int) ($config['due_in_hours'] ?? 24)))->toIso8601String(),
                'assignee' => $this->assignee($config, $lead),
            ] : null,
        ];
    }

    /**
     * Los mismos motivos por los que `Runner::execute()` lanzaría, pero
     * dichos antes de que pase y en el idioma del usuario.
     */
    private function check(StageAutomation $automation, ?Lead $lead): array
    {
        if (! $automation->is_active) {
            return ['paused', 'Está pausada: hoy no se ejecuta.'];
        }

        if (trim((string) ($automation->config['text'] ?? '')) === '') {
            return ['error', 'No tiene texto configurado.'];
        }

        if ($automation->action_type === 'send_whatsapp') {
            if (! $automation->account?->integration?->is_active) {
                return ['error', 'La integración con WhatsApp está inactiva: el mensaje no saldría.'];
            }

            if ($lead && ! $lead->contact?->phone) {
                return ['error', 'Este lead no tiene teléfono: el mensaje no saldría.'];
            }

            return ['ok', $lead
                ? 'Se enviaría por WhatsApp a '.$lead->contact->phone.'.'
                : 'Se enviaría por WhatsApp al contacto del lead.'];
        }

        if ($automation->action_type === 'create_task') {
            return ['ok', 'Se crearía la tarea con este vencimiento.'];
        }

        return ['ok', 'Se guardaría como nota en el lead.'];
    }

    private function assignee(array $config, ?Lead $lead): ?string
    {
        $userId = $config['assigned_to'] ?? $lead?->responsible_user_id;

        if (! $userId) {
            return null;
        }

        return User::find($userId)?->name;
    }

    /** Mismos tokens que `Runner::interpolate`. */
    private function interpolate(string $text, ?Lead $lead): string
    {
        if (! $lead) {
            return $text;
        }

        return strtr($text, [
            '{name}' => $lead->contact?->name ?? '',
            '{title}' => $lead->title,
            '{value}' => (string) $lead->value,
            '{stage}' => $lead->stage?->name ?? '',
        ]);
    }
}
