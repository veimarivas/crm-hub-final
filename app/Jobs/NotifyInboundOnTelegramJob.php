<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Models\User;
use App\Services\Telegram\Client;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Avisa por Telegram al responsable que le escribió un contacto.
 *
 * Existe porque el equipo no vive con el CRM abierto: el aviso en pantalla
 * solo sirve si estás adentro. Con esto el responsable se entera en el
 * teléfono y puede entrar a seguirlo mientras la IA gana tiempo.
 *
 * En cola: el webhook entrante del wacrm no debe esperar a un HTTP a
 * Telegram.
 */
class NotifyInboundOnTelegramJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * Un contacto suele mandar varios mensajes seguidos. Sin freno, cada uno
     * sería una notificación en el teléfono y el responsable terminaría
     * silenciando el bot — que es peor que no tenerlo.
     */
    private const THROTTLE_MINUTES = 5;

    public function __construct(
        public readonly string $leadId,
        public readonly string $preview,
    ) {}

    public function handle(): void
    {
        $client = Client::fromConfig();

        if (! $client) {
            return; // sin bot configurado, el módulo no existe
        }

        $lead = Lead::with('contact:id,name,phone')->find($this->leadId);

        if (! $lead) {
            return;
        }

        $destinatario = $this->recipient($lead);

        if (! $destinatario?->telegram_chat_id) {
            return; // nadie a quien avisar, o no vinculó su Telegram
        }

        // Un aviso por lead cada N minutos, por destinatario.
        $key = "telegram_notified:{$lead->id}:{$destinatario->id}";

        if (! Cache::add($key, true, now()->addMinutes(self::THROTTLE_MINUTES))) {
            return;
        }

        $enviado = $client->sendMessage(
            $destinatario->telegram_chat_id,
            $this->body($lead),
            [
                'inline_keyboard' => [[
                    ['text' => '💬 Abrir conversación', 'url' => route('leads.show', $lead->id)],
                ]],
            ],
        );

        // Si Telegram rechaza el envío lo más común es que el usuario haya
        // bloqueado el bot. Se desvincula para no reintentar en cada mensaje.
        if (! $enviado) {
            $destinatario->update(['telegram_chat_id' => null, 'telegram_linked_at' => null]);
        }
    }

    /** El responsable del lead; si no tiene, el owner de la cuenta. */
    private function recipient(Lead $lead): ?User
    {
        if ($lead->responsible_user_id) {
            return User::find($lead->responsible_user_id);
        }

        return User::find($lead->account?->owner_user_id);
    }

    private function body(Lead $lead): string
    {
        $contacto = Client::escape($lead->contact?->name ?: $lead->contact?->phone ?: $lead->title);
        $telefono = $lead->contact?->phone ? Client::escape($lead->contact->phone) : null;
        $etapa = $lead->stage?->name ? Client::escape($lead->stage->name) : null;

        $lineas = [
            "💬 <b>{$contacto}</b> te escribió",
            '',
            Client::escape($this->preview),
            '',
        ];

        if ($telefono) {
            $lineas[] = "📞 {$telefono}";
        }

        if ($etapa) {
            $lineas[] = "📊 Etapa: {$etapa}";
        }

        $lineas[] = '';
        $lineas[] = '<i>La IA puede estar respondiendo mientras tanto.</i>';

        return implode("\n", $lineas);
    }
}
