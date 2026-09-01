<?php

namespace App\Services\WhatsApp;

use App\Services\Channels\ChannelRules;

/**
 * Cuánto costaría un envío, para decirlo ANTES de mandarlo.
 *
 * La pregunta que responde no es "cuánto salió" sino "cuánto va a salir si
 * aprieto enviar": un broadcast a 400 contactos fuera de ventana es una
 * factura, y hasta ahora la pantalla no decía nada.
 *
 * Las tarifas viven en `config/whatsapp.php` porque Meta las cambia cada
 * trimestre y no queremos buscarlas en el medio del código.
 */
class MessagingCost
{
    /**
     * Estimación para N mensajes de una categoría.
     *
     * @return array{
     *   messages: int,
     *   category: string,
     *   rate_usd: float,
     *   total_usd: float,
     *   total_bob: float,
     *   currency: string,
     *   country: string,
     *   source: string,
     * }
     */
    public function estimate(int $messages, string $category = 'marketing', string $channel = ChannelRules::DEFAULT): array
    {
        $config = config('whatsapp.pricing');

        // F0 — un envío por Telegram o webchat no cuesta nada, y una pantalla
        // que muestre «USD 12,40» para algo gratis es peor que no mostrar
        // nada: hace que se deje de mandar por miedo a una factura que no
        // existe. El default deja intactos los llamadores que no saben de
        // canales.
        $rate = ChannelRules::hasCost($channel)
            ? (float) ($config['rates'][$category] ?? $config['rates']['marketing'])
            : 0.0;

        $total = $messages * $rate;

        return [
            'messages' => $messages,
            'category' => $category,
            // Viaja para que la pantalla pueda decir POR QUÉ el total es cero
            // en vez de dejar un «USD 0,00» que se lee como un error.
            'channel' => $channel,
            'has_cost' => ChannelRules::hasCost($channel),
            'rate_usd' => $rate,
            'total_usd' => round($total, 4),
            'total_bob' => round($total * (float) $config['bob_per_usd'], 2),
            'currency' => $config['currency'],
            'country' => $config['country'],
            'source' => $config['source'],
        ];
    }

    /**
     * Las tarifas sueltas, para que la pantalla pueda explicarlas sin tener
     * que pedir una estimación de cero mensajes.
     *
     * @return array<string, mixed>
     */
    public function rates(): array
    {
        return config('whatsapp.pricing');
    }
}
