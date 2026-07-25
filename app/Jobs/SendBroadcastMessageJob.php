<?php

namespace App\Jobs;

use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Services\Wacrm\Client;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Support\Facades\Log;

/**
 * Envia el mensaje de un broadcast a un unico destinatario.
 * Un job por recipient permite paralelismo con throttle a nivel de la cola.
 * Meta cobra por conversacion iniciada fuera de ventana 24h — usar templates
 * aprobados desde el wacrm si aplica; este job envia texto simple asumiendo
 * que el contacto ya esta en ventana o el numero destino es ok.
 */
class SendBroadcastMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(public readonly string $recipientId)
    {
    }

    public function handle(): void
    {
        $recipient = BroadcastRecipient::find($this->recipientId);
        if (! $recipient || $recipient->status !== 'pending') {
            return;
        }

        $broadcast = Broadcast::with('account.integration')->find($recipient->broadcast_id);
        if (! $broadcast) {
            return;
        }

        $integration = $broadcast->account?->integration;
        if (! $integration) {
            $recipient->update(['status' => 'failed', 'error' => 'sin_integracion']);
            $broadcast->increment('failed_count');

            return;
        }

        try {
            Client::for($integration)->sendMessage($recipient->phone_normalized, $broadcast->message);

            $recipient->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
            $broadcast->increment('sent_count');

            // Si es el ultimo, marcar el broadcast como completado
            $this->maybeComplete($broadcast);
        } catch (\Throwable $e) {
            $recipient->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
            $broadcast->increment('failed_count');

            Log::warning('broadcast.send.failed', [
                'recipient_id' => $recipient->id,
                'broadcast_id' => $broadcast->id,
                'error' => $e->getMessage(),
            ]);

            $this->maybeComplete($broadcast);
        }
    }

    private function maybeComplete(Broadcast $broadcast): void
    {
        $broadcast->refresh();
        if (($broadcast->sent_count + $broadcast->failed_count) >= $broadcast->total_recipients) {
            $broadcast->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        }
    }
}
