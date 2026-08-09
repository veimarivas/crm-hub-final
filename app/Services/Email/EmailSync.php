<?php

namespace App\Services\Email;

use App\Models\Contact;
use App\Models\EmailAccount;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\Pipeline;
use Illuminate\Support\Facades\DB;

/**
 * Trae los correos nuevos de una casilla y los mete en el timeline del lead.
 *
 * ## El correo es un mensaje más
 *
 * Los eventos se graban como **`message_in` / `message_out`**, los mismos que
 * usa WhatsApp, con `payload.channel = 'email'`. Es una decisión con
 * consecuencias que conviene tener claras:
 *
 *  - **A favor:** supervisión, copiloto y segmentos funcionan sobre el correo
 *    sin tocar una línea. «Esperando respuesta hace 3 h» pasa a ser cierto
 *    también para un mail, que es justo lo que se quiere en una institución
 *    que atiende por los dos canales.
 *  - **El costo:** `ResponseMetrics` —el GEMELO del wacrm— empieza a medir
 *    también el correo. **No se modifica ni una línea de esa clase**: cambia el
 *    dato que entra, no la definición. Pero los tiempos de respuesta a partir
 *    de ahora no son comparables con los de antes, porque el correo se
 *    contesta en horas y el WhatsApp en minutos. `payload.channel` permite
 *    separarlos el día que haga falta.
 */
class EmailSync
{
    public function __construct(
        private readonly GoogleOAuth $oauth = new GoogleOAuth,
    ) {}

    /**
     * @return array{imported: int, skipped: int}
     */
    public function sync(EmailAccount $mailbox): array
    {
        $client = new GmailClient($mailbox, $this->oauth);
        $history = $client->newMessageIds();

        // Primera pasada (o punto de historia caducado): no se importa nada
        // hacia atrás, solo se fija el punto de partida. Importar la casilla
        // entera de golpe llenaría el timeline de meses de correo viejo.
        if (! $mailbox->last_history_id || $history['expired']) {
            $mailbox->forceFill([
                'last_history_id' => $history['historyId'],
                'last_synced_at' => now(),
                'last_error' => $history['expired'] ? 'El punto de sincronización caducó: se retomó desde ahora.' : null,
            ])->save();

            return ['imported' => 0, 'skipped' => 0];
        }

        $imported = 0;
        $skipped = 0;

        foreach ($history['ids'] as $id) {
            $message = $client->message($id);

            if (! $message || trim($message['text']) === '') {
                $skipped++;

                continue;
            }

            $this->record($mailbox, $message) ? $imported++ : $skipped++;
        }

        $mailbox->forceFill([
            'last_history_id' => $history['historyId'],
            'last_synced_at' => now(),
            'last_error' => null,
        ])->save();

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /** Graba un correo como evento del lead. Devuelve false si ya estaba. */
    private function record(EmailAccount $mailbox, array $message): bool
    {
        // Idempotencia por id de Gmail: sincronizar dos veces el mismo rango
        // —que pasa cuando una pasada falla a la mitad— no puede duplicar el
        // hilo del lead.
        $already = LeadEvent::forAccount($mailbox->account_id)
            ->whereIn('event_type', ['message_in', 'message_out'])
            ->where('payload->gmail_id', $message['id'])
            ->exists();

        if ($already) {
            return false;
        }

        // La contraparte es quien no es la casilla.
        $counterpart = $message['direction'] === 'in' ? $message['from'] : $message['to'];

        if (! $counterpart) {
            return false;
        }

        return DB::transaction(function () use ($mailbox, $message, $counterpart) {
            $lead = $this->leadFor($mailbox, $counterpart);

            $lead->recordEvent(
                $message['direction'] === 'in' ? 'message_in' : 'message_out',
                // Un correo saliente escrito desde Gmail no lo mandó nadie
                // «desde el CRM», pero sí una persona: sin `sender`, el resto
                // del sistema lo trataría como respuesta de la IA.
                $message['direction'] === 'out' ? $mailbox->owner : null,
                [
                    'text' => mb_substr($message['text'], 0, 5000),
                    'channel' => 'email',
                    'sender' => $message['direction'] === 'out' ? 'agent' : 'contact',
                    'subject' => $message['subject'],
                    'from' => $message['from'],
                    'to' => $message['to'],
                    'gmail_id' => $message['id'],
                    'thread_id' => $message['thread_id'],
                    'message_id' => $message['message_id'],
                ],
            );

            return true;
        });
    }

    /**
     * Lead al que pertenece el correo: el abierto más reciente del contacto, o
     * uno nuevo.
     *
     * Se reusa el lead abierto en vez de crear uno por correo porque una
     * conversación por mail son muchos mensajes sobre el mismo asunto; abrir un
     * lead por cada uno convertiría el pipeline en una bandeja de entrada.
     */
    private function leadFor(EmailAccount $mailbox, string $email): Lead
    {
        $contact = Contact::forAccount($mailbox->account_id)
            ->where('email', $email)
            ->first()
            ?? Contact::create([
                'account_id' => $mailbox->account_id,
                'name' => mb_strstr($email, '@', true) ?: $email,
                'email' => $email,
            ]);

        $lead = Lead::forAccount($mailbox->account_id)
            ->where('contact_id', $contact->id)
            ->where('status', 'open')
            ->latest()
            ->first();

        if ($lead) {
            return $lead;
        }

        $pipeline = Pipeline::forAccount($mailbox->account_id)
            ->orderByDesc('is_default')
            ->firstOrFail();

        return Lead::create([
            'account_id' => $mailbox->account_id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages()->where('stage_type', 'open')->orderBy('position')->firstOrFail()->id,
            'contact_id' => $contact->id,
            'title' => $contact->name,
            // La fuente que pidió el usuario: un lead nacido de un correo se
            // distingue de uno de WhatsApp en reportes y segmentos.
            'source' => 'email',
            'responsible_user_id' => $mailbox->user_id,
        ]);
    }
}
