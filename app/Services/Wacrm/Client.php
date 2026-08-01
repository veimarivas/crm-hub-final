<?php

namespace App\Services\Wacrm;

use App\Models\Integration;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente de la API pública del wacrm (el CRM de WhatsApp).
 * Endpoints: /api/v1/contacts, /api/v1/conversations, /api/v1/messages.
 * La api_key vive cifrada en la integración de la cuenta.
 */
class Client
{
    public function __construct(private readonly Integration $integration) {}

    public static function for(Integration $integration): self
    {
        return new self($integration);
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->integration->wacrm_api_key)
            ->acceptJson()
            ->timeout(15)
            ->baseUrl($this->integration->baseUrl().'/api/v1');
    }

    /** Prueba la conexión y los scopes de la clave. */
    public function me(): array
    {
        return $this->unwrap($this->request()->get('/me'));
    }

    public function contacts(int $page = 1, ?string $search = null): array
    {
        return $this->unwrap($this->request()->get('/contacts', array_filter([
            'page' => $page,
            'q' => $search,
        ])));
    }

    public function conversations(int $page = 1): array
    {
        return $this->unwrap($this->request()->get('/conversations', ['page' => $page]));
    }

    public function conversationMessages(string $conversationId, int $page = 1): array
    {
        return $this->unwrap($this->request()->get("/conversations/{$conversationId}/messages", ['page' => $page]));
    }

    /** Envía un WhatsApp al teléfono indicado (crea contacto/conversación allá si no existen). */
    public function sendMessage(string $phone, string $text): array
    {
        return $this->unwrap($this->request()->post('/messages', ['to' => $phone, 'text' => $text]));
    }

    /** Envía un archivo (imagen/audio/video/documento) por WhatsApp. */
    public function sendMedia(string $phone, string $fileBase64, string $mimeType, ?string $filename = null, ?string $caption = null): array
    {
        return $this->unwrap($this->request()->timeout(60)->post('/messages/media', array_filter([
            'to' => $phone,
            'file_base64' => $fileBase64,
            'mime_type' => $mimeType,
            'filename' => $filename,
            'caption' => $caption,
        ])));
    }

    /** Obtiene las plantillas rápidas compartidas del equipo (para el composer). */
    public function quickReplies(): array
    {
        return $this->unwrap($this->request()->get('/quick-replies'));
    }

    /**
     * Descarga el binario de un media (audio, imagen, etc.) por su Meta media_id.
     * Devuelve [contentType, bytes] — Komo lo re-sirve desde su propio dominio
     * para evitar problemas de CORS/cookies cross-origin.
     */
    public function downloadMedia(string $mediaId): array
    {
        $response = $this->request()->timeout(30)->get("/media/{$mediaId}");
        if ($response->failed()) {
            throw new RuntimeException("wacrm media: HTTP {$response->status()}");
        }

        return [$response->header('Content-Type') ?: 'application/octet-stream', $response->body()];
    }

    /**
     * Provisión idempotente de un usuario en el wacrm por email. Si ya
     * existe en la cuenta remota actualiza el rol. Devuelve el user.
     * Requiere scope team:write en la API key.
     */
    public function provisionUser(string $email, string $name, ?string $password = null, string $role = 'agent'): array
    {
        return $this->unwrap($this->request()->post('/team/provision', array_filter([
            'email' => $email,
            'name' => $name,
            'password' => $password,
            'role' => $role,
        ])));
    }

    /**
     * Reasigna una conversación al agente cuyo email se pasa (o desasigna
     * pasando null). Requiere scope conversations:write.
     */
    public function assignConversation(string $conversationId, ?string $email): array
    {
        return $this->unwrap($this->request()->patch("/conversations/{$conversationId}/assign", [
            'email' => $email,
        ]));
    }

    /**
     * Estado de la IA en el wacrm: si está activa, si el proveedor responde
     * y si estamos dentro del horario. Timeout corto porque se consulta desde
     * el render de una página (cacheado del lado de Komo).
     */
    public function aiStatus(): array
    {
        return $this->unwrap($this->request()->timeout(5)->get('/ai/status'));
    }

    /** Modo IA/Humano de la conversación en el wacrm (true = IA activa). */
    public function setAiMode(string $conversationId, bool $aiEnabled): array
    {
        return $this->unwrap($this->request()->patch("/conversations/{$conversationId}/ai-mode", [
            'ai_enabled' => $aiEnabled,
        ]));
    }

    /**
     * Mueve el deal de la conversación a la etapa del Komo (fuente de verdad
     * del pipeline). Requiere scope conversations:write.
     */
    public function setConversationStage(string $conversationId, string $stageName, ?string $status = null): array
    {
        return $this->unwrap($this->request()->patch("/conversations/{$conversationId}/stage", array_filter([
            'stage_name' => $stageName,
            'status' => $status,
        ])));
    }

    private function unwrap($response): array
    {
        if ($response->failed()) {
            $error = $response->json('message') ?? "HTTP {$response->status()}";

            throw new RuntimeException("wacrm API: {$error}");
        }

        return $response->json() ?? [];
    }
}
