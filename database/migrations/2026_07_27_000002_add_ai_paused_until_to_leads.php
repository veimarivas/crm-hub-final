<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espejo de la pausa de la IA del wacrm.
 *
 * Cuando la IA agota su tope de respuestas queda en pausa unas horas y luego
 * retoma sola. Acá se guarda esa hora para mostrar el mismo aviso en el chat
 * del lead: sin esto, en Komo la IA simplemente dejaba de contestar y nadie
 * entendía por qué.
 *
 * La verdad vive en el wacrm; esto es solo reflejo, como `ai_pending`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('ai_paused_until')->nullable()->after('ai_pending');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('ai_paused_until');
        });
    }
};
