<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback del equipo sobre lo que contestó la IA (T5 de mejoras2.md).
 *
 * Se guarda **acá** aunque el destino final sea el wacrm: si el envío se
 * hiciera directo y el wacrm estuviera caído, el agente se tomaría el trabajo
 * de escribir la corrección y se perdería. Guardar primero y despachar después
 * es lo que hace que nada se pierda.
 *
 * `synced_at` distingue lo que ya llegó al wacrm de lo que sigue en cola.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_feedback', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('lead_event_id')->constrained('lead_events')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('rating', 4); // up | down
            $table->text('correction')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            // Un voto por mensaje y por usuario: cambiar de opinión actualiza
            // la fila en vez de acumular votos.
            $table->unique(['lead_event_id', 'user_id']);
            $table->index(['account_id', 'synced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_feedback');
    }
};
