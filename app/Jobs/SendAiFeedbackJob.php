<?php

namespace App\Jobs;

use App\Models\AiFeedback;
use App\Services\Wacrm\Client;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Lleva el feedback al wacrm, donde vive la IA.
 *
 * Reintentos con espera creciente porque el wacrm puede estar caído justo
 * cuando el agente corrige algo: la corrección **ya está guardada acá**, así
 * que el job puede fallar sin que se pierda nada. El endpoint del otro lado es
 * idempotente, de modo que reintentar no duplica ni reabre nada de más.
 */
class SendAiFeedbackJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function __construct(public readonly string $feedbackId) {}

    public function handle(): void
    {
        $feedback = AiFeedback::with(['lead.contact', 'event', 'author'])->find($this->feedbackId);

        if (! $feedback || $feedback->synced_at) {
            return;
        }

        $integration = $feedback->lead?->account?->integration;

        if (! $integration?->is_active) {
            // Sin integración no hay a dónde mandarlo. Queda sin sincronizar y
            // el comando de reintento lo levanta cuando se reactive.
            Log::info('Feedback de IA sin integración activa', ['feedback_id' => $feedback->id]);

            return;
        }

        Client::for($integration)->sendAiFeedback([
            'rating' => $feedback->rating,
            // El id del evento es la referencia estable entre los dos sistemas.
            'external_ref' => $feedback->lead_event_id,
            'conversation_id' => $feedback->lead?->wacrm_conversation_id,
            'ai_text' => $feedback->event?->payload['text'] ?? null,
            'question' => $this->previousInbound($feedback),
            'correction' => $feedback->correction,
            'reporter' => $feedback->author?->name,
            'source' => 'komo',
        ]);

        $feedback->forceFill(['synced_at' => now()])->save();
    }

    /**
     * El mensaje del cliente inmediatamente anterior a la respuesta de la IA.
     *
     * Sin la pregunta, la corrección no se puede juzgar: «el precio es 3.500»
     * no dice nada si no se sabe de qué se estaba hablando.
     */
    private function previousInbound(AiFeedback $feedback): ?string
    {
        $event = $feedback->event;

        if (! $event) {
            return null;
        }

        $previous = $feedback->lead?->events()
            ->where('event_type', 'message_in')
            ->where('created_at', '<=', $event->created_at)
            ->orderByDesc('created_at')
            ->first();

        return $previous?->payload['text'] ?? null;
    }
}
