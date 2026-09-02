<?php

namespace App\Services\WhatsApp;

use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Services\Channels\ChannelRules;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Ventana de servicio de WhatsApp: cuánto queda para escribirle al contacto
 * SIN que Meta cobre.
 *
 * Las dos reglas de Meta, que se comportan DISTINTO:
 *
 *  - **24 h de servicio — se reinicia con cada mensaje.** Cada entrante del
 *    cliente abre (o renueva) 24 h de texto libre gratis contadas desde ese
 *    mensaje. Vencida, solo se puede escribir con plantilla aprobada, y eso
 *    se factura.
 *  - **72 h de free entry point — NO se reinicia.** Corren desde el clic en
 *    el anuncio Click-to-WhatsApp y punto: que el cliente siga escribiendo no
 *    las estira. Dentro de esas 72 h todo es gratis, incluidas las plantillas.
 *    Solo un clic NUEVO en un anuncio abre otras 72 h.
 *
 * Corren en paralelo, así que vale **la que venza más tarde**. El caso que
 * hay que tener claro: si el cliente toca el anuncio y escribe recién en la
 * hora 71, al vencer las 72 h la conversación NO se corta — quedan las 24 h
 * estándar desde su último mensaje, o sea hasta la hora 95.
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

        // F0 — el canal de cada lead, del último entrante que lo declare.
        // Ordenado ascendente a propósito: `pluck` va pisando la clave, así que
        // gana el más reciente. Los eventos anteriores a F0 no traen canal y
        // caen al default, que es lo que eran.
        $channels = LeadEvent::whereIn('lead_id', $ids)
            ->where('event_type', 'message_in')
            ->whereNotNull('payload->channel')
            ->orderBy('created_at')
            ->selectRaw("lead_id, JSON_UNQUOTE(JSON_EXTRACT(payload, '$.channel')) as channel")
            ->pluck('channel', 'lead_id');

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
                $channels[$lead->id] ?? ChannelRules::DEFAULT,
            );
        }

        return $out;
    }

    /**
     * Ventana por CONTACTO: la de su lead con actividad más reciente. La usan
     * los listados de contactos, donde no hay un lead a mano.
     *
     * @param  Collection<int, Contact>  $contacts
     * @return array<string, array<string, mixed>>
     */
    public function forContacts(Collection $contacts): array
    {
        $leads = Lead::whereIn('contact_id', $contacts->pluck('id'))
            ->get(['id', 'contact_id', 'source_ref', 'created_at']);

        $windows = $this->forLeads($leads);

        $out = [];

        foreach ($contacts as $contact) {
            // El lead con el entrante más reciente es la conversación viva.
            $best = $leads
                ->where('contact_id', $contact->id)
                ->map(fn (Lead $l) => $windows[$l->id])
                ->filter(fn ($w) => $w['last_inbound_at'] !== null)
                ->sortByDesc('last_inbound_at')
                ->first();

            $out[$contact->id] = $best ?? $this->build(null, null);
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
    public function build(?CarbonInterface $lastInboundAt, ?CarbonInterface $adReferralAt, string $channel = ChannelRules::DEFAULT): array
    {
        // F0 — el corte de canal va acá y en ningún otro lado: los cuatro
        // métodos públicos terminan en `build()`, así que una sola línea cubre
        // la ficha, los listados y los contactos. El default mantiene
        // compatibles a todos los llamadores que no saben de canales.
        if (! ChannelRules::hasServiceWindow($channel)) {
            return $this->alwaysOpen($channel, $lastInboundAt);
        }

        $standardExpiry = $lastInboundAt?->copy()->addHours(self::STANDARD_HOURS);

        // Las 72 h del anuncio son de Click-to-WhatsApp y de nada más. En
        // Messenger e Instagram rigen las 24 h y punto: extenderlas diría
        // «todavía es gratis» cuando ya no lo es.
        $adExpiry = ChannelRules::hasAdReferralWindow($channel)
            ? $adReferralAt?->copy()->addHours(self::AD_REFERRAL_HOURS)
            : null;

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

    /**
     * Canal sin plazo: siempre se puede escribir y no cuesta.
     *
     * **⚠️ Devuelve TODAS las claves del contrato, no un array corto.** La UI
     * ya consume `window_hours`, `remaining_seconds` y `source`; un array
     * incompleto rompe el badge con un error que no señala esta línea.
     * `window_hours = null` es la señal de «sin límite» y la pantalla la lee
     * para no dibujar una cuenta regresiva que no existe.
     *
     * @return array<string, mixed>
     */
    private function alwaysOpen(string $channel, ?CarbonInterface $lastInboundAt): array
    {
        return [
            'source' => $channel,
            'window_hours' => null,
            'expires_at' => null,
            'remaining_seconds' => 0,
            'is_open' => true,
            'is_expiring' => false,
            'last_inbound_at' => $lastInboundAt?->toIso8601String(),
            'ad_referral_at' => null,
        ];
    }
}
