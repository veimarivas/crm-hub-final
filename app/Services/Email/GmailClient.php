<?php

namespace App\Services\Email;

use App\Models\EmailAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente mínimo de la API de Gmail: lo que hace falta para sincronizar y
 * responder, nada más.
 *
 * Se usa la API y no IMAP porque el servidor no tiene `ext-imap` y porque
 * `history.list` da **sincronización incremental**: «qué cambió desde este
 * punto» en vez de recorrer la casilla entera en cada pasada.
 */
class GmailClient
{
    private const BASE = 'https://gmail.googleapis.com/gmail/v1/users/me';

    public function __construct(
        private readonly EmailAccount $mailbox,
        private readonly GoogleOAuth $oauth = new GoogleOAuth,
    ) {}

    private function request(): PendingRequest
    {
        return Http::withToken($this->oauth->freshAccessToken($this->mailbox))
            ->acceptJson()
            ->timeout(20);
    }

    /**
     * Ids de mensajes nuevos desde `last_history_id`.
     *
     * Si Google responde 404, el punto de historia caducó (pasa cuando la
     * casilla estuvo sin sincronizar mucho tiempo): se avisa para que el
     * llamador rearme el punto de partida en vez de quedarse en un bucle de
     * error silencioso.
     *
     * @return array{ids: array<int, string>, historyId: ?string, expired: bool}
     */
    public function newMessageIds(): array
    {
        if (! $this->mailbox->last_history_id) {
            return ['ids' => [], 'historyId' => $this->currentHistoryId(), 'expired' => false];
        }

        $response = $this->request()->get(self::BASE.'/history', [
            'startHistoryId' => $this->mailbox->last_history_id,
            'historyTypes' => 'messageAdded',
            'maxResults' => 200,
        ]);

        if ($response->status() === 404) {
            return ['ids' => [], 'historyId' => $this->currentHistoryId(), 'expired' => true];
        }

        if ($response->failed()) {
            throw new RuntimeException('Gmail rechazó la consulta de historial: '.$response->status());
        }

        $ids = collect($response->json('history', []))
            ->flatMap(fn ($entry) => collect($entry['messagesAdded'] ?? [])->pluck('message.id'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'ids' => $ids,
            'historyId' => $response->json('historyId') ?: $this->mailbox->last_history_id,
            'expired' => false,
        ];
    }

    /** Punto de partida actual de la casilla. */
    public function currentHistoryId(): ?string
    {
        $response = $this->request()->get(self::BASE.'/profile');

        return $response->successful() ? (string) $response->json('historyId') : null;
    }

    /**
     * Un mensaje, normalizado a lo que el CRM necesita.
     *
     * @return array{id: string, thread_id: string, direction: string, from: string, to: string, subject: string, text: string, message_id: ?string, sent_at: ?string}|null
     */
    public function message(string $id): ?array
    {
        $response = $this->request()->get(self::BASE."/messages/{$id}", ['format' => 'full']);

        if ($response->failed()) {
            return null;
        }

        $payload = $response->json('payload', []);
        $headers = collect($payload['headers'] ?? [])
            ->mapWithKeys(fn ($h) => [mb_strtolower($h['name']) => $h['value']]);

        $from = $this->address($headers->get('from', ''));
        $to = $this->address($headers->get('to', ''));

        // La dirección se decide comparando el remitente con la casilla: lo que
        // mandó el asesor desde Gmail también tiene que verse en el CRM, si no
        // el hilo queda a medias.
        $direction = mb_strtolower($from) === mb_strtolower($this->mailbox->email)
            ? 'out'
            : 'in';

        return [
            'id' => $id,
            'thread_id' => (string) $response->json('threadId'),
            'direction' => $direction,
            'from' => $from,
            'to' => $to,
            'subject' => $headers->get('subject', '(sin asunto)'),
            'text' => $this->plainText($payload) ?: (string) $response->json('snippet'),
            'message_id' => $headers->get('message-id'),
            'sent_at' => $headers->get('date'),
        ];
    }

    /** Envía un correo en el hilo indicado (o uno nuevo). */
    public function send(string $to, string $subject, string $body, ?string $threadId = null, ?string $inReplyTo = null): array
    {
        $headers = [
            'From: '.$this->mailbox->email,
            'To: '.$to,
            'Subject: '.$this->encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ];

        // Sin `In-Reply-To`/`References` el cliente del destinatario abre un
        // hilo nuevo y la conversación se parte en dos.
        if ($inReplyTo) {
            $headers[] = 'In-Reply-To: '.$inReplyTo;
            $headers[] = 'References: '.$inReplyTo;
        }

        $raw = implode("\r\n", $headers)."\r\n\r\n".$body;

        $response = $this->request()->post(self::BASE.'/messages/send', array_filter([
            'raw' => rtrim(strtr(base64_encode($raw), '+/', '-_'), '='),
            'threadId' => $threadId,
        ]));

        if ($response->failed()) {
            throw new RuntimeException('Gmail rechazó el envío: '.$response->json('error.message', (string) $response->status()));
        }

        return $response->json();
    }

    /** «Ana Pérez <ana@x.com>» → «ana@x.com». */
    private function address(string $raw): string
    {
        return preg_match('/<([^>]+)>/', $raw, $m) ? trim($m[1]) : trim($raw);
    }

    private function encodeHeader(string $value): string
    {
        return preg_match('/[^\x20-\x7E]/', $value)
            ? '=?UTF-8?B?'.base64_encode($value).'?='
            : $value;
    }

    /**
     * Cuerpo en texto plano.
     *
     * Recorre las partes en profundidad porque un correo real es un árbol
     * (`multipart/alternative` dentro de `multipart/mixed` cuando hay adjuntos)
     * y quedarse en el primer nivel devuelve vacío justo en los que importan.
     */
    private function plainText(array $part): string
    {
        if (($part['mimeType'] ?? '') === 'text/plain' && ! empty($part['body']['data'])) {
            return $this->decode($part['body']['data']);
        }

        foreach ($part['parts'] ?? [] as $child) {
            $text = $this->plainText($child);

            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function decode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
