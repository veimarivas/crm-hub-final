<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Models\User;
use App\Services\Telegram\Client;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Avisa por Telegram al responsable que le asignaron un contacto.
 *
 * Es distinto del aviso de mensaje entrante: acá todavía no escribió nadie,
 * pero el responsable tiene que saber que le cayó trabajo — sobre todo con
 * el reparto automático, donde nadie le dice nada.
 *
 * `assignedBy` null = lo asignó el round-robin. Distinguirlo importa: no es
 * lo mismo que te lo derive el admin (hay una intención detrás) que que te
 * haya tocado por reparto.
 */
class NotifyAssignmentOnTelegramJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly string $leadId,
        public readonly string $userId,
        public readonly ?string $assignedByUserId = null,
    ) {}

    public function handle(): void
    {
        $client = Client::fromConfig();

        if (! $client) {
            return;
        }

        $destinatario = User::find($this->userId);

        if (! $destinatario?->telegram_chat_id) {
            return;
        }

        // Nadie necesita un aviso de que se asignó algo a sí mismo.
        if ($this->assignedByUserId === $this->userId) {
            return;
        }

        $lead = Lead::with(['contact:id,name,phone', 'stage:id,name'])->find($this->leadId);

        if (! $lead) {
            return;
        }

        $enviado = $client->sendMessage(
            $destinatario->telegram_chat_id,
            $this->body($lead),
            [
                'inline_keyboard' => [[
                    ['text' => '📂 Ver el lead', 'url' => route('leads.show', $lead->id)],
                ]],
            ],
        );

        if (! $enviado) {
            $destinatario->update(['telegram_chat_id' => null, 'telegram_linked_at' => null]);
        }
    }

    private function body(Lead $lead): string
    {
        $contacto = Client::escape($lead->contact?->name ?: $lead->contact?->phone ?: $lead->title);

        $origen = $this->assignedByUserId
            ? 'Te lo asignó <b>'.Client::escape(User::find($this->assignedByUserId)?->name ?? 'el administrador').'</b>'
            : 'Te tocó por <b>reparto automático</b>';

        $lineas = [
            '🆕 <b>Nuevo contacto asignado</b>',
            '',
            "👤 {$contacto}",
        ];

        if ($lead->contact?->phone) {
            $lineas[] = '📞 '.Client::escape($lead->contact->phone);
        }

        if ($lead->stage?->name) {
            $lineas[] = '📊 Etapa: '.Client::escape($lead->stage->name);
        }

        $lineas[] = '';
        $lineas[] = $origen.'.';

        return implode("\n", $lineas);
    }
}
