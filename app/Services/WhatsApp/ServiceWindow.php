<?php

namespace App\Services\WhatsApp;

use App\Models\Lead;
use App\Models\LeadEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Ventana de servicio de WhatsApp: cuánto queda para escribirle al contacto
 * SIN que Meta cobre.
 *
 * Las dos reglas de Meta:
 *  - **24 h de servicio**: cada mensaje entrante del cliente abre (o renueva)
 *    24 h de texto libre gratis. Vencida, solo se puede escribir con una
 *    plantilla aprobada — y eso se factura.
 *  - **72 h de free entry point**: si el cliente llegó tocando un anuncio
 *    Click-to-WhatsApp, esa conversación sale gratis durante 72 h.
 *
 * Corren en paralelo, así que vale **la que venza más tarde**: un clic en el
 * anuncio hace 60 h sigue cubriendo aunque las 24 h del último mensaje ya
 * hayan pasado, y al revés.
 *
 * Se calcula desde `lead_events` (el espejo local de la conversación): no se
 * consulta al wacrm ni a Meta, así que sirve en listados sin costo de red.
 * Es el mismo cálculo que `Services\WhatsApp\ServiceWindow` del wacrm —
 * si se cambia una regla hay que tocar las dos.
 */
class ServiceWindow
{
    public const STANDARD_HOURS = 24;

    public const AD_REFERRAL_HOURS = 72;

    /** Debajo de esto la UI avisa en ámbar. */
    public const WARNING_HOURS = 4;

    /**
     * Ventana de un lead suelto (ficha, contacto).
     *
     * @return array<string, mixed>
     */
    public function forLead(Lead $lead): array
    {
        return $this->forLeads(collect([$lead]))[$lead->id];
    }

    /**
     * Versión en lote para listados: dos queries para todos los leads en vez
     * de dos por cada uno.
     *
     * @param  Collection<int, Lead>  $leads
     * @return array<string, array<string, mixed>>
     */
    public function forLeads(Collection $leads): array
    {
        $ids = $leads->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $lastInbound = LeadEvent::whereIn('lead_id', $ids)
            ->where('event_type', 'message_in')
            ->selectRaw('lead_id, MAX(created_at) as last_at')
            ->groupBy('lead_id')
            ->pluck('last_at', 'lead_id');

        // Entrantes que venían de un anuncio. `whereJsonContains` sobre el
        // payload: los eventos anteriores a que se guardara `ad_referral`
        // simplemente no matchean y caen al fallback de abajo.
        $lastAd = LeadEvent::whereIn('lead_id', $ids)
            ->where('event_type', 'message_in')
            ->whereJsonContains('payload->ad_referral', true)
            ->selectRaw('lead_id, MAX(created_at) as last_at')
            ->groupBy('lead_id')
            ->pluck('last_at', 'lead_id');

        $out = [];

        foreach ($leads as $lead) {
            $adAt = isset($lastAd[$lead->id]) ? Carbon::parse($lastAd[$lead->id]) : null;

            // Fallback para leads viejos: el evento no trae la marca, pero el
            // lead sí guarda el anuncio de origen. Se usa su creación como
            // momento del clic — es cuando entró el primer mensaje.
            if (! $adAt && $lead->source_ref) {
                $adAt = $lead->created_at;
            }

            $out[$lead->id] = $this->build(
                isset($lastInbound[$lead->id]) ? Carbon::parse($lastInbound[$lead->id]) : null,
                $adAt,
            );
        }

        return $out;
    }

    /**
     * @return array{
     *   source: 'meta_ad'|'whatsapp'|'none',
     *   window_hours: int|null,
     *   expires_at: string|null,
     *   remaining_seconds: int,
     *   is_open: bool,
     *   is_expiring: bool,
     *   last_inbound_at: string|null,
     *   ad_referral_at: string|null,
     * }
     */
    public function build(?CarbonInterface $lastInboundAt, ?CarbonInterface $adReferralAt): array
    {
        $standardExpiry = $lastInboundAt?->copy()->addHours(self::STANDARD_HOURS);
        $adExpiry = $adReferralAt?->copy()->addHours(self::AD_REFERRAL_HOURS);

        $expiry = match (true) {
            $standardExpiry && $adExpiry => $standardExpiry->max($adExpiry),
            default => $standardExpiry ?? $adExpiry,
        };

        $remaining = $expiry ? (int) now()->diffInSeconds($expiry, false) : 0;
        $isOpen = $remaining > 0;

        return [
            // Si la ventana vigente la sostiene el anuncio se reporta como
            // tal: es lo que explica por qué son 72 h y no 24.
            'source' => match (true) {
                $adExpiry && $expiry?->equalTo($adExpiry) => 'meta_ad',
                $lastInboundAt !== null => 'whatsapp',
                default => 'none',
            },
            'window_hours' => match (true) {
                ! $expiry => null,
                $adExpiry && $expiry->equalTo($adExpiry) => self::AD_REFERRAL_HOURS,
                default => self::STANDARD_HOURS,
            },
            'expires_at' => $expiry?->toIso8601String(),
            'remaining_seconds' => max(0, $remaining),
            'is_open' => $isOpen,
            'is_expiring' => $isOpen && $remaining <= self::WARNING_HOURS * 3600,
            'last_inbound_at' => $lastInboundAt?->toIso8601String(),
            'ad_referral_at' => $adReferralAt?->toIso8601String(),
        ];
    }
}
