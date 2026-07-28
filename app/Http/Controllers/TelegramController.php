<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Telegram\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Vinculación de la cuenta de un usuario con su Telegram.
 *
 * El flujo evita pedirle a nadie que averigüe su "chat id":
 *   1. El usuario toca «Conectar Telegram» y se genera un token de un solo uso.
 *   2. Se le abre el bot con `?start=<token>`.
 *   3. Telegram le manda `/start <token>` al bot, que llega a este webhook.
 *   4. El token se canjea por el chat_id y se borra.
 *
 * El token es lo que impide que alguien que conozca el bot se ate a la
 * cuenta de otro: sin él, el webhook no vincula nada.
 */
class TelegramController extends Controller
{
    /** Genera (o reusa) el token y devuelve el enlace al bot. */
    public function link(Request $request): RedirectResponse
    {
        $user = $request->user();
        $username = config('services.telegram.bot_username');

        if (! config('services.telegram.bot_token') || ! $username) {
            return back()->withErrors(['telegram' => 'El bot de Telegram no está configurado en el servidor.']);
        }

        if (! $user->telegram_link_token) {
            $user->update(['telegram_link_token' => Str::random(32)]);
        }

        return back()->with('telegram_link', "https://t.me/{$username}?start={$user->telegram_link_token}");
    }

    public function unlink(Request $request): RedirectResponse
    {
        $request->user()->update([
            'telegram_chat_id' => null,
            'telegram_link_token' => null,
            'telegram_linked_at' => null,
        ]);

        return back()->with('success', 'Telegram desvinculado.');
    }

    /**
     * Webhook del bot. La URL lleva un secreto: Telegram es el único que la
     * conoce, y sin él no se procesa nada.
     *
     * Solo interesa `/start <token>`; cualquier otro mensaje se responde con
     * una ayuda breve para que el usuario no quede hablándole al vacío.
     */
    public function webhook(Request $request, string $secret): JsonResponse
    {
        abort_unless(
            $secret !== '' && hash_equals((string) config('services.telegram.webhook_secret'), $secret),
            404,
        );

        $message = $request->input('message', []);
        $chatId = (string) ($message['chat']['id'] ?? '');
        $text = trim((string) ($message['text'] ?? ''));

        if (! $chatId) {
            return response()->json(['ok' => true]);
        }

        $client = Client::fromConfig();

        if (! preg_match('/^\/start\s+(\S+)$/', $text, $m)) {
            $client?->sendMessage($chatId, 'Para recibir avisos, entrá al CRM → tu perfil → <b>Conectar Telegram</b>.');

            return response()->json(['ok' => true]);
        }

        $user = User::where('telegram_link_token', $m[1])->first();

        if (! $user) {
            $client?->sendMessage($chatId, 'Ese enlace no es válido o ya se usó. Generá uno nuevo desde tu perfil.');

            return response()->json(['ok' => true]);
        }

        $user->update([
            'telegram_chat_id' => $chatId,
            'telegram_link_token' => null, // un solo uso
            'telegram_linked_at' => now(),
        ]);

        $client?->sendMessage(
            $chatId,
            "✅ Listo, {$user->name}. Te voy a avisar acá cuando te escriba un contacto asignado."
        );

        return response()->json(['ok' => true]);
    }
}
