<?php

namespace App\Services\Wacrm;

use App\Models\Integration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Estado de la IA del wacrm para el indicador del header de Komo.
 *
 * Nunca lanza: si el wacrm no contesta, el header muestra el problema en vez
 * de tumbar todas las pantallas.
 *
 * El motivo del fallo importa, y antes se perdía: cualquier excepción se
 * pintaba como «Sin conexión», que manda a revisar la red cuando lo más común
 * es otra cosa —una API key sin el scope `conversations:read`, o un wacrm
 * viejo sin el endpoint—. Ahora se distinguen y además quedan en el log.
 */
class AiStatusProbe
{
    /** Un estado bueno se cachea 2 min: es un HTTP por render de página. */
    private const TTL_OK = 120;

    /**
     * Uno malo, 30 s: si el fallo fue pasajero, el indicador tiene que volver
     * a verde rápido en vez de mentir durante dos minutos.
     */
    private const TTL_FAIL = 30;

    /**
     * @return array<string, mixed>|null  null = esta cuenta no tiene wacrm
     *                                    cableado, no hay nada que mostrar.
     */
    public function forAccount(?string $accountId, bool $fresh = false): ?array
    {
        if (! $accountId) {
            return null;
        }

        $key = "ai_status:{$accountId}";

        if ($fresh) {
            Cache::forget($key);
        } elseif (Cache::has($key)) {
            return Cache::get($key);
        }

        $integration = Integration::forAccount($accountId)->first();

        if (! $integration || ! $integration->wacrm_url || ! $integration->wacrm_api_key) {
            Cache::put($key, null, self::TTL_OK);

            return null;
        }

        $status = $this->probe($integration);

        Cache::put($key, $status, ($status['available'] ?? false) ? self::TTL_OK : self::TTL_FAIL);

        return $status;
    }

    /**
     * Consulta sin caché. Devuelve siempre un array pintable.
     *
     * @return array<string, mixed>
     */
    public function probe(Integration $integration): array
    {
        try {
            return Client::for($integration)->aiStatus();
        } catch (WacrmApiException $e) {
            // Llegó al wacrm y volvió con un código: el arreglo depende de cuál.
            $reason = match (true) {
                in_array($e->status, [401, 403], true) => 'unauthorized',
                $e->status === 404 => 'not_supported',
                default => 'unreachable',
            };

            return $this->failure($integration, $reason, $e->getMessage(), $e->status);
        } catch (\Throwable $e) {
            // Ni siquiera llegó: DNS, TLS, timeout, wacrm apagado.
            return $this->failure($integration, 'unreachable', $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function failure(Integration $integration, string $reason, string $detail, ?int $status = null): array
    {
        Log::warning('No se pudo leer el estado de la IA del wacrm', [
            'reason' => $reason,
            'http_status' => $status,
            'wacrm_url' => $integration->wacrm_url,
            'detail' => $detail,
        ]);

        return [
            'configured' => true,
            'available' => false,
            'reason' => $reason,
            'http_status' => $status,
            'detail' => $detail,
        ];
    }
}
