<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo de cada usuario con Telegram, para avisarle fuera del sistema
 * cuando le escribe un contacto asignado.
 *
 * `telegram_link_token` es de un solo uso: se genera al pedir el enlace y se
 * borra al vincular. Es lo que evita que alguien que adivine el bot pueda
 * atarse a la cuenta de otro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_chat_id', 40)->nullable()->after('phone');
            $table->string('telegram_link_token', 64)->nullable()->unique()->after('telegram_chat_id');
            $table->timestamp('telegram_linked_at')->nullable()->after('telegram_link_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['telegram_chat_id', 'telegram_link_token', 'telegram_linked_at']);
        });
    }
};
