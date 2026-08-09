<?php

namespace App\Services\Email;

use App\Models\EmailAccount;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OAuth de Google Workspace para conectar una casilla corporativa.
 *
 * Sin librería de terceros: son dos endpoints HTTP y el flujo estándar de
 * código de autorización. Agregar una dependencia para esto ata el proyecto a
 * su ciclo de versiones sin ahorrar nada real.
 */
class GoogleOAuth
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    /**
     * Permisos pedidos.
     *
     * `gmail.modify` y no `gmail.readonly` porque hace falta marcar como leído
     * lo que ya se procesó; `gmail.send` para responder desde el CRM. No se
     * pide acceso total a la casilla: `https://mail.google.com/` incluye borrar
     * y no hay motivo para tenerlo.
     */
    public const SCOPES = [
        'https://www.googleapis.com/auth/gmail.modify',
        'https://www.googleapis.com/auth/gmail.send',
        'openid',
        'email',
    ];

    /** URL a la que se manda al usuario para autorizar. */
    public function authorizationUrl(string $state): string
    {
        $this->assertConfigured();

        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect'),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            // `offline` + `consent` es lo que garantiza que Google devuelva un
            // refresh token: sin ellos, la segunda vez que alguien autoriza la
            // misma casilla no llega y la sincronización muere en una hora.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            // Sugiere la cuenta del dominio institucional.
            'hd' => config('services.google.workspace_domain') ?: null,
            'state' => $state,
        ]);
    }

    /**
     * Cambia el código de autorización por tokens y averigua de qué casilla se
     * trata.
     *
     * @return array{email: string, access_token: string, refresh_token: ?string, expires_in: int}
     */
    public function exchangeCode(string $code): array
    {
        $this->assertConfigured();

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.redirect'),
            'grant_type' => 'authorization_code',
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Google rechazó la autorización: '.$response->json('error_description', 'error desconocido'));
        }

        $tokens = $response->json();

        $userinfo = Http::withToken($tokens['access_token'])->get(self::USERINFO_URL);

        if ($userinfo->failed() || ! $userinfo->json('email')) {
            throw new RuntimeException('No se pudo leer la dirección de la casilla autorizada.');
        }

        return [
            'email' => $userinfo->json('email'),
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? null,
            'expires_in' => (int) ($tokens['expires_in'] ?? 3600),
        ];
    }

    /**
     * Devuelve un access token válido, renovándolo si hace falta.
     *
     * Google **no reenvía el refresh token** al renovar, así que solo se pisa
     * el de acceso: sobrescribir el refresh con `null` desconectaría la casilla
     * en la primera renovación.
     */
    public function freshAccessToken(EmailAccount $mailbox): string
    {
        if (! $mailbox->tokenExpired() && $mailbox->access_token) {
            return $mailbox->access_token;
        }

        if (! $mailbox->refresh_token) {
            throw new RuntimeException('La casilla no tiene refresh token: hay que volver a conectarla.');
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'refresh_token' => $mailbox->refresh_token,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            throw new RuntimeException('No se pudo renovar el acceso a la casilla: '.$response->json('error_description', 'error desconocido'));
        }

        $mailbox->forceFill([
            'access_token' => $response->json('access_token'),
            'token_expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600)),
        ])->save();

        return $mailbox->access_token;
    }

    /** ¿La casilla pertenece al dominio de la institución? */
    public function belongsToWorkspace(string $email): bool
    {
        $domain = config('services.google.workspace_domain');

        return ! $domain || str_ends_with(mb_strtolower($email), '@'.mb_strtolower($domain));
    }

    private function assertConfigured(): void
    {
        if (! config('services.google.client_id') || ! config('services.google.client_secret')) {
            throw new RuntimeException('Faltan las credenciales de Google en el .env (GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET).');
        }
    }
}
