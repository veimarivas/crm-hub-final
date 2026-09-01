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

    /**
     * Reporta al wacrm que una respuesta de la IA estuvo bien o mal.
     *
     * El endpoint del otro lado es idempotente por `external_ref`, así que
     * reintentar no duplica. Allá **no entra al conocimiento**: queda en una
     * cola que un humano revisa.
     *
     * @param  array<string, mixed>  $payload
     */
    public function sendAiFeedback(array $payload): array
    {
        return $this->unwrap($this->request()->post('/ai/feedback', $payload));
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

    /**
     * Crea un broadcast EN EL WACRM y devuelve el broadcast con su informe de
     * audiencia. Requiere scope `broadcasts:write`.
     *
     * Komo resuelve **a quién** (con `SegmentQuery`, que el wacrm no puede
     * reproducir porque no conoce leads ni etapas) y el wacrm resuelve **cómo
     * se manda**: plantillas, ventana de servicio, rate limit y métricas. Es la
     * división que hace que exista un solo motor de envíos.
     *
     * El timeout es largo a propósito: con adjunto, el cuerpo lleva el archivo
     * en base64 y una audiencia de miles de teléfonos.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createBroadcast(array $payload): array
    {
        return $this->unwrap($this->request()->timeout(120)->post('/broadcasts', $payload));
    }

    /**
     * Estado real de un broadcast delegado: contadores, motivos de fallo e
     * informe de audiencia. Requiere scope `broadcasts:read`.
     */
    public function broadcast(string $id): array
    {
        return $this->unwrap($this->request()->get("/broadcasts/{$id}"));
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
        // 8s y no 5: del otro lado la comprobación de que Ollama responde tiene
        // su propio timeout de 3s, y con el TLS y el arranque de Laravel encima
        // los 5s se rozaban cada vez que expiraba el caché de allá — el header
        // decía «Sin conexión» por medio segundo de más.
        return $this->unwrap($this->request()->timeout(8)->get('/ai/status'));
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
     *
     * **D5 — `$stageId` es lo que hace fiable la correspondencia.** Hasta acá
     * la etapa se buscaba del otro lado solo por NOMBRE: dos etapas homónimas
     * en pipelines distintos podían aterrizar el movimiento en la columna
     * equivocada, y renombrar una etapa rompía el espejo hasta el próximo
     * sync. El uuid viaja como `external_id` allá desde `pipelines/sync`, así
     * que ya existe la correspondencia — solo faltaba usarla. El nombre se
     * sigue mandando: un wacrm sin desplegar tiene que seguir funcionando.
     */
    public function setConversationStage(string $conversationId, string $stageName, ?string $status = null, ?string $stageId = null): array
    {
        return $this->unwrap($this->request()->patch("/conversations/{$conversationId}/stage", array_filter([
            'stage_name' => $stageName,
            'stage_external_id' => $stageId,
            'status' => $status,
        ])));
    }

    /**
     * Replica en el wacrm la estructura completa de pipelines/etapas de la
     * cuenta (Komo es la fuente de verdad de las columnas de /pipelines).
     * Requiere scope conversations:write.
     */
    public function syncPipelines(array $pipelines): array
    {
        return $this->unwrap($this->request()->post('/pipelines/sync', [
            'pipelines' => $pipelines,
        ]));
    }

    /**
     * Replica en el wacrm el catálogo de etiquetas y campos personalizados
     * (Komo es la fuente de verdad de la taxonomía, igual que de los
     * pipelines). Requiere scope `conversations:write`.
     *
     * `dryRun` devuelve el informe sin tocar nada del otro lado: es lo que
     * hace segura la primera pasada, donde el sync puede enlazar o borrar
     * cosas que ya existían allá.
     *
     * @param  array<int, array<string, mixed>>  $tags
     * @param  array<int, array<string, mixed>>  $customFields
     */
    public function syncTaxonomy(array $tags, array $customFields, bool $dryRun = false): array
    {
        return $this->unwrap($this->request()->post('/taxonomy/sync', [
            'tags' => $tags,
            'custom_fields' => $customFields,
            'dry_run' => $dryRun,
        ]));
    }

    private function unwrap($response): array
    {
        if ($response->failed()) {
            $error = $response->json('message') ?? "HTTP {$response->status()}";

            throw new WacrmApiException($response->status(), "wacrm API: {$error}");
        }

        return $response->json() ?? [];
    }
}
