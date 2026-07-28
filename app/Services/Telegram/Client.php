<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente mínimo de la Bot API de Telegram.
 *
 * Se usa para avisarle al responsable que le escribió un contacto, aunque no
 * tenga el CRM abierto — que es el caso normal fuera del horario de oficina.
 *
 * Se configura en `.env`:
 *   TELEGRAM_BOT_TOKEN=123456:ABC...
 *   TELEGRAM_BOT_USERNAME=mi_crm_bot
 * Sin token, todo el módulo se abstiene en silencio: la app funciona igual,
 * simplemente no manda avisos.
 */
class Client
{
    public function __construct(private readonly string $token) {}

    public static function fromConfig(): ?self
    {
        $token = config('services.telegram.bot_token');

        return $token ? new self($token) : null;
    }

    /**
     * Envía un mensaje. Devuelve false si Telegram lo rechaza — el llamador
     * decide si eso amerita desvincular al usuario (por ejemplo si bloqueó
     * el bot) o solo loguear.
     */
    public function sendMessage(string $chatId, string $text, ?array $replyMarkup = null): bool
    {
        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$this->token}/sendMessage", array_filter([
                'chat_id' => $chatId,
                'text' => $text,
                // HTML y no Markdown: los nombres de contacto traen guiones
                // bajos y asteriscos que rompen el parseo de Markdown.
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'reply_markup' => $replyMarkup ? json_encode($replyMarkup) : null,
            ]));

            if ($response->failed()) {
                Log::warning('Telegram sendMessage falló', [
                    'chat_id' => $chatId,
                    'error' => $response->json('description') ?? $response->status(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('Telegram sendMessage falló', ['chat_id' => $chatId, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** Escapa el texto del contacto: viene de afuera y puede traer HTML. */
    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
