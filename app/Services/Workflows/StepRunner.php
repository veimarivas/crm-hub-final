<?php

namespace App\Services\Workflows;

use App\Models\AppNotification;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\Tag;
use App\Models\Task;
use App\Services\Wacrm\Client;
use App\Services\WhatsApp\ServiceWindow;

/**
 * Ejecuta una acción sobre un lead.
 *
 * Cada método devuelve el detalle de lo que hizo (queda en la traza) o lanza
 * si no pudo. Nada de `Log::warning` silencioso: el motor atrapa la excepción
 * y la escribe en `workflow_step_runs`, que es donde el usuario la ve.
 */
class StepRunner
{
    /** Tokens interpolables en los textos configurables. */
    public function interpolate(string $text, Lead $lead): string
    {
        return strtr($text, [
            '{name}' => $lead->contact?->name ?? '',
            '{title}' => $lead->title,
            '{value}' => (string) $lead->value,
            '{stage}' => $lead->stage?->name ?? '',
            '{responsible}' => $lead->responsible?->name ?? '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function sendWhatsapp(Lead $lead, array $config): string
    {
        $integration = $lead->account->integration;

        if (! $integration?->is_active) {
            throw new \RuntimeException('La integración con WhatsApp está inactiva.');
        }

        if (! $lead->contact?->phone) {
            throw new \RuntimeException('El lead no tiene contacto con teléfono.');
        }

        // Fuera de la ventana de servicio Meta cobra y exige plantilla
        // aprobada. Un workflow NO decide gastar por su cuenta: o se configuró
        // explícitamente qué hacer, o no se manda.
        $window = app(ServiceWindow::class)->forLead($lead);

        if (! ($window['is_open'] ?? false)) {
            $outside = $config['outside_window'] ?? 'skip';

            if ($outside === 'task') {
                $this->createTask($lead, [
                    'text' => 'No se envió el mensaje automático: el lead está fuera de la ventana de servicio.',
                    'task_type' => 'follow_up',
                    'due_in_hours' => 4,
                ]);

                return 'Fuera de ventana: se creó una tarea en vez de escribir.';
            }

            throw new \RuntimeException('El lead está fuera de la ventana de servicio.');
        }

        $text = $this->interpolate($config['text'] ?? '', $lead);

        if (trim($text) === '') {
            throw new \RuntimeException('El mensaje quedó vacío tras interpolar.');
        }

        Client::for($integration)->sendMessage(
            $lead->contact->phone_normalized ?? $lead->contact->phone,
            $text,
        );

        $lead->recordEvent('message_out', null, [
            'text' => mb_substr($text, 0, 500),
            'automation' => true,
            'workflow' => true,
        ]);

        return 'Mensaje enviado.';
    }

    /** @param array<string, mixed> $config */
    public function createTask(Lead $lead, array $config): string
    {
        $task = Task::create([
            'account_id' => $lead->account_id,
            'lead_id' => $lead->id,
            'contact_id' => $lead->contact_id,
            // `?? null` antes del `?:`: una clave ausente es un warning que
            // Laravel convierte en excepción, y el paso fallaría por no tener
            // configurado un campo que es opcional a propósito.
            'assigned_to' => ($config['assigned_to'] ?? null) ?: $lead->responsible_user_id,
            'task_type' => $config['task_type'] ?? 'follow_up',
            'text' => $this->interpolate($config['text'] ?? 'Dar seguimiento', $lead),
            'due_at' => now()->addHours(max(1, (int) ($config['due_in_hours'] ?? 24))),
        ]);

        $lead->recordEvent('task_created', null, [
            'text' => $task->text,
            'due_at' => $task->due_at->toIso8601String(),
            'automation' => true,
        ]);

        return "Tarea creada: «{$task->text}».";
    }

    /** @param array<string, mixed> $config */
    public function addNote(Lead $lead, array $config): string
    {
        $text = $this->interpolate($config['text'] ?? '', $lead);

        if (trim($text) === '') {
            throw new \RuntimeException('La nota quedó vacía.');
        }

        $lead->notes()->create(['account_id' => $lead->account_id, 'text' => $text]);
        $lead->recordEvent('note_added', null, ['text' => mb_substr($text, 0, 200), 'automation' => true]);

        return 'Nota agregada.';
    }

    /** @param array<string, mixed> $config */
    public function addTag(Lead $lead, array $config): string
    {
        $tag = Tag::forAccount($lead->account_id)->find($config['tag_id'] ?? null);

        if (! $tag) {
            throw new \RuntimeException('La etiqueta configurada ya no existe.');
        }

        $lead->tags()->syncWithoutDetaching([$tag->id]);

        return "Etiqueta «{$tag->name}» agregada.";
    }

    /** @param array<string, mixed> $config */
    public function removeTag(Lead $lead, array $config): string
    {
        $tag = Tag::forAccount($lead->account_id)->find($config['tag_id'] ?? null);

        if (! $tag) {
            throw new \RuntimeException('La etiqueta configurada ya no existe.');
        }

        $lead->tags()->detach($tag->id);

        return "Etiqueta «{$tag->name}» quitada.";
    }

    /** @param array<string, mixed> $config */
    public function changeStage(Lead $lead, array $config): string
    {
        $stage = PipelineStage::find($config['stage_id'] ?? null);

        if (! $stage || $stage->pipeline_id !== $lead->pipeline_id) {
            throw new \RuntimeException('La etapa configurada no pertenece al pipeline del lead.');
        }

        if ($stage->id === $lead->stage_id) {
            return 'El lead ya estaba en esa etapa.';
        }

        // `moveToStage` es EL punto de entrada de los cambios de etapa: dispara
        // el timeline, el espejo al wacrm y las automatizaciones de etapa. El
        // tope de pasos de `Guardrails` es lo que evita que ese reencadenado se
        // vuelva un bucle.
        $lead->moveToStage($stage);

        return "Movido a «{$stage->name}».";
    }

    /** @param array<string, mixed> $config */
    public function assignResponsible(Lead $lead, array $config): string
    {
        $userId = $config['user_id'] ?? null;

        if (! $userId || ! $lead->account->members()->whereKey($userId)->exists()) {
            throw new \RuntimeException('El responsable configurado no pertenece a la cuenta.');
        }

        $lead->forceFill(['responsible_user_id' => $userId])->save();
        $lead->recordEvent('assigned', null, ['automation' => true]);

        return 'Responsable asignado.';
    }

    /** @param array<string, mixed> $config */
    public function notifyUser(Lead $lead, array $config): string
    {
        $userId = ($config['user_id'] ?? null) ?: $lead->responsible_user_id;

        if (! $userId) {
            throw new \RuntimeException('No hay a quién avisar: el lead no tiene responsable.');
        }

        AppNotification::notify(
            $lead->account_id,
            $userId,
            'workflow',
            $this->interpolate($config['title'] ?? 'Aviso de workflow', $lead),
            $this->interpolate($config['body'] ?? '', $lead) ?: null,
            $lead->id,
        );

        return 'Aviso enviado al equipo.';
    }
}
