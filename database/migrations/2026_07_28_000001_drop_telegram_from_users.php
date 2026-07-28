<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retira el vínculo con Telegram de `users`.
 *
 * El módulo de avisos por Telegram se dio de baja: exigía que cada agente
 * tocara «Conectar Telegram» una vez, y eso no se puede evitar (un bot de
 * Telegram solo puede escribirle a quien lo inició primero). El aviso al
 * celular se resuelve por otro canal.
 *
 * Las columnas se dropean **solo si existen**: la migración que las creaba se
 * eliminó junto con la funcionalidad, así que en una base nueva
 * (`migrate:fresh`, tests, un despliegue limpio) nunca llegan a existir y esto
 * debe ser un no-op. En las bases que ya venían con la feature aplicada
 * —local y producción— sí hay que limpiarlas.
 */
return new class extends Migration
{
    private const COLUMNAS = ['telegram_chat_id', 'telegram_link_token', 'telegram_linked_at'];

    public function up(): void
    {
        $presentes = array_values(array_filter(
            self::COLUMNAS,
            fn (string $columna) => Schema::hasColumn('users', $columna),
        ));

        if ($presentes === []) {
            return;
        }

        Schema::table('users', function (Blueprint $table) use ($presentes) {
            $table->dropColumn($presentes);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'telegram_chat_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_chat_id', 40)->nullable()->after('phone');
            $table->string('telegram_link_token', 64)->nullable()->unique()->after('telegram_chat_id');
            $table->timestamp('telegram_linked_at')->nullable()->after('telegram_link_token');
        });
    }
};
