<?php

namespace App\Services\WhatsApp;

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
    public function estimate(int $messages, string $category = 'marketing'): array
    {
        $config = config('whatsapp.pricing');
        $rate = (float) ($config['rates'][$category] ?? $config['rates']['marketing']);
        $total = $messages * $rate;

        return [
            'messages' => $messages,
            'category' => $category,
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
