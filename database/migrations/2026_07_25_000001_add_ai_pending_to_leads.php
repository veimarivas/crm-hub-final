<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flag efímero: TRUE mientras la IA del wacrm está generando respuesta para
 * este lead. Se actualiza vía webhook `ai.pending_changed` desde wacrm.
 * El chat del lead pinta una burbuja "IA pensando..." cuando true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->boolean('ai_pending')->default(false)->after('ai_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('ai_pending');
        });
    }
};
