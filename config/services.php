<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * Google Workspace: correo corporativo por OAuth.
     *
     * Se usa la **API de Gmail sobre HTTP** y no IMAP: el servidor no tiene
     * `ext-imap`, y con Workspace + OAuth la API es además mejor — trae
     * sincronización incremental por `historyId`, que evita recorrer la casilla
     * entera en cada pasada.
     *
     * Scopes necesarios en la consola de Google:
     *   gmail.readonly (o gmail.modify) + gmail.send + userinfo.email
     */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        // Dominio de la institución: restringe qué casillas se pueden conectar
        // para que nadie enganche su Gmail personal a la cuenta corporativa.
        'workspace_domain' => env('GOOGLE_WORKSPACE_DOMAIN'),
    ],

    // SSO ligero del ecosistema: secreto HMAC compartido con el Komo Hub
    // (el MISMO valor en el .env de las 4 apps). Lo consume /sso/consume.
    'hub' => [
        'sso_secret' => env('HUB_SSO_SECRET'),
        // Secreto maestro de provisión (POST /api/v1/provision). NUNCA al navegador.
        'provision_secret' => env('HUB_PROVISION_SECRET'),
    ],

];
