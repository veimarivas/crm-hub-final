<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Casillas corporativas de Google Workspace conectadas por OAuth (T6).
 *
 * Los tokens van **cifrados en reposo** (cast `encrypted` en el modelo): un
 * refresh token de Workspace da acceso al correo de la institución, así que
 * un volcado de la base no puede dejarlo en texto plano.
 *
 * `last_history_id` es el estado de la sincronización incremental de Gmail: se
 * pide «qué cambió desde este punto» en vez de recorrer la casilla entera cada
 * vez. Es lo que hace que sincronizar cada pocos minutos sea barato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            // Dueño de la casilla: los correos que entren se atribuyen a él como
            // responsable, igual que pasa con las conversaciones de WhatsApp.
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();

            $table->string('email');
            $table->string('provider', 20)->default('google');
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();

            $table->string('last_history_id', 40)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Una casilla se conecta una sola vez por cuenta.
            $table->unique(['account_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_accounts');
    }
};
