<?php

namespace App\Services\Wacrm;

use App\Models\Contact;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Pipeline;

/**
 * Convierte los eventos del wacrm en actividad del CRM de leads:
 *
 *   contact.created  → crea/actualiza el contacto espejo aquí.
 *   message.received → busca el contacto por teléfono; si no tiene
 *                      ningún lead ABIERTO, crea uno (source whatsapp,
 *                      primera etapa del pipeline por defecto); el
 *                      mensaje aterriza en el timeline del lead.
 *
 * Esta es la regla de oro de Kommo: cada conversación nueva de un
 * canal se vuelve un lead que el equipo tiene que trabajar.
 */
class EventProcessor
{
    public function process(Integration $integration, string $event, array $data): void
    {
        match ($event) {
            'contact.created' => $this->syncContact($integration, $data['contact'] ?? []),
            'message.received' => $this->handleInboundMessage($integration, $data),
            'message.sent' => $this->handleOutboundMessage($integration, $data),
            'message.transcribed' => $this->handleTranscribed($integration, $data),
            'ai.pending_changed' => $this->handleAiPending($integration, $data),
            default => null, // eventos que no nos interesan se ignoran
        };
    }

    /**
     * La IA del wacrm empezó/terminó de pensar una respuesta para este lead.
     * Actualiza el flag ai_pending del lead → la UI muestra/oculta la burbuja
     * "IA pensando..." (polling de 2s ya está tomando este cambio).
     */
    private function handleAiPending(Integration $integration, array $data): void
    {
        $convId = $data['conversation_id'] ?? null;
        $pending = (bool) ($data['pending'] ?? false);
        if (! $convId) return;

        Lead::forAccount($integration->account_id)
            ->where('wacrm_conversation_id', $convId)
            ->update(['ai_pending' => $pending]);
    }

    /**
     * Un audio del wacrm ya fue transcrito por Whisper. Busca el evento
     * previo (message_in o message_out) del lead por wamid y agrega el texto
     * transcrito a su payload. Así en el chat del Komo el audio deja de
     * mostrarse como "[sin texto]" y muestra la transcripción real.
     */
    private function handleTranscribed(Integration $integration, array $data): void
    {
        $wamid = $data['message']['wamid'] ?? null;
        $transcript = $data['message']['transcript'] ?? null;
        $convId = $data['conversation_id'] ?? null;

        if (! $wamid || ! $transcript || ! $convId) {
            return;
        }

        $lead = Lead::forAccount($integration->account_id)
            ->where('wacrm_conversation_id', $convId)
            ->latest()
            ->first();

        if (! $lead) {
            return;
        }

        // Buscar el evento (in o out) por wamid en el payload y actualizar el text
        $lead->events()
            ->whereIn('event_type', ['message_in', 'message_out'])
            ->whereJsonContains('payload->wamid', $wamid)
            ->get()
            ->each(function ($event) use ($transcript) {
                $payload = $event->payload ?? [];
                $payload['text'] = $transcript;
                $payload['transcript'] = $transcript;
                $event->update(['payload' => $payload]);
            });
    }

    private function syncContact(Integration $integration, array $remote): ?Contact
    {
        $normalized = Contact::normalizePhone($remote['phone'] ?? null);

        if (! $normalized) {
            return null;
        }

        $contact = Contact::forAccount($integration->account_id)
            ->where('phone_normalized', $normalized)
            ->first();

        if ($contact) {
            // Completa datos que falten sin pisar lo editado aquí.
            $contact->update(array_filter([
                'name' => $contact->name === $contact->phone ? ($remote['name'] ?? null) : null,
                'email' => $contact->email ? null : ($remote['email'] ?? null),
                'wacrm_contact_id' => $remote['id'] ?? null,
            ]));

            return $contact;
        }

        return Contact::create([
            'account_id' => $integration->account_id,
            'name' => $remote['name'] ?: ($remote['phone'] ?? 'Sin nombre'),
            'phone' => $remote['phone'] ?? null,
            'email' => $remote['email'] ?? null,
            'wacrm_contact_id' => $remote['id'] ?? null,
        ]);
    }

    private function handleInboundMessage(Integration $integration, array $data): void
    {
        $contact = $this->syncContact($integration, $data['contact'] ?? []);

        if (! $contact) {
            return;
        }

        $lead = Lead::forAccount($integration->account_id)
            ->where('contact_id', $contact->id)
            ->where('status', Lead::STATUS_OPEN)
            ->latest()
            ->first();

        // Atribución de anuncios: el wacrm reenvía el `referral` de Meta
        // cuando el mensaje llegó desde un anuncio Click-to-WhatsApp.
        $referral = $data['message']['referral'] ?? null;

        // Sin lead abierto → conversación nueva = lead nuevo (regla Kommo).
        if (! $lead) {
            $pipeline = Pipeline::forAccount($integration->account_id)
                ->where('is_default', true)
                ->first()
                ?? Pipeline::forAccount($integration->account_id)->first();

            $firstStage = $pipeline?->stages()->where('stage_type', 'open')->orderBy('position')->first();

            if (! $pipeline || ! $firstStage) {
                return; // cuenta sin pipeline configurado
            }

            $lead = Lead::create([
                'account_id' => $integration->account_id,
                'pipeline_id' => $pipeline->id,
                'stage_id' => $firstStage->id,
                'contact_id' => $contact->id,
                'title' => 'WhatsApp: '.$contact->name,
                'source' => 'whatsapp',
                'source_ref' => $referral['source_id'] ?? null,
                'source_url' => $referral['source_url'] ?? null,
                'wacrm_conversation_id' => $data['conversation_id'] ?? null,
            ]);

            $lead->recordEvent('created', null, array_filter([
                'source' => 'whatsapp',
                'ad_id' => $referral['source_id'] ?? null,
            ]));

            // Aviso al owner: entró un lead nuevo por WhatsApp.
            \App\Models\AppNotification::notify(
                $integration->account_id,
                $integration->account->owner_user_id,
                'lead_created_whatsapp',
                'Nuevo lead de WhatsApp',
                "{$contact->name} escribió por WhatsApp",
                $lead->id,
            );
        }

        // Mantén el vínculo con la conversación si aún no lo tenía.
        if (! $lead->wacrm_conversation_id && ($data['conversation_id'] ?? null)) {
            $lead->update(['wacrm_conversation_id' => $data['conversation_id']]);
        }

        // La atribución original se preserva: solo se escribe si el
        // lead abierto aún no tiene anuncio de origen.
        if (! $lead->source_ref && ($referral['source_id'] ?? null)) {
            $lead->update([
                'source_ref' => $referral['source_id'],
                'source_url' => $referral['source_url'] ?? null,
            ]);
        }

        $lead->recordEvent('message_in', null, [
            'text' => mb_substr($data['message']['text'] ?? '', 0, 500),
            'type' => $data['message']['type'] ?? 'text',
            'wamid' => $data['message']['wamid'] ?? null,
            'media_id' => $data['message']['media_id'] ?? null,
        ]);
    }

    /**
     * Mensaje saliente del wacrm (agente humano o IA/bot): se registra
     * como message_out en el lead abierto de ese contacto. Si no hay lead
     * abierto se ignora — un mensaje saliente sin conversación previa es
     * un caso raro (probablemente un broadcast a un contacto sin lead).
     * Idempotente por wamid: si el evento se reenvía no se duplica.
     */
    private function handleOutboundMessage(Integration $integration, array $data): void
    {
        $contact = $this->syncContact($integration, $data['contact'] ?? []);

        if (! $contact) {
            return;
        }

        $lead = Lead::forAccount($integration->account_id)
            ->where('contact_id', $contact->id)
            ->where('status', Lead::STATUS_OPEN)
            ->latest()
            ->first();

        if (! $lead) {
            return;
        }

        $wamid = $data['message']['wamid'] ?? null;

        // Idempotencia: si ya registré este wamid, no duplico.
        if ($wamid && $lead->events()->where('event_type', 'message_out')->whereJsonContains('payload->wamid', $wamid)->exists()) {
            return;
        }

        $lead->recordEvent('message_out', null, [
            'text' => mb_substr($data['message']['text'] ?? '', 0, 500),
            'type' => $data['message']['type'] ?? 'text',
            'wamid' => $wamid,
            'media_id' => $data['message']['media_id'] ?? null,
            'sender' => $data['message']['sender_type'] ?? 'agent', // 'agent' | 'bot'
            'sender_name' => $data['message']['sender_name'] ?? null,
            'sender_role' => $data['message']['sender_role'] ?? null,
        ]);
    }
}
