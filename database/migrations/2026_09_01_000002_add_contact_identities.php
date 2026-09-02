<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F0b/T0.4 — identidades de canal, espejo de las del wacrm.
 *
 * **Sin esto, el bloqueante 1 del plan no se puede arreglar.**
 * `EventProcessor::syncContact()` arranca con:
 *
 *     $normalized = Contact::normalizePhone($remote['phone'] ?? null);
 *     if (! $normalized) { return null; }
 *
 * Un mensaje de Telegram llega **sin teléfono**, así que hoy este proyecto lo
 * tira en silencio: no crea contacto, no crea lead, no registra evento. El
 * wacrm lo procesaría bien y acá desaparecería sin dejar rastro — la peor
 * forma de fallar, porque no hay error que investigar.
 *
 * `contacts.phone` **ya era nullable** de este lado, así que no hace falta
 * tocarla (a diferencia del wacrm, donde era NOT NULL).
 *
 * El backfill desde `phone_normalized` va en la propia migración por la misma
 * razón que allá: si el deploy corre migraciones y nadie corre un comando, el
 * primer mensaje de un contacto existente le crearía una identidad duplicada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('contact_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('external_id');
            $table->string('display_name')->nullable();
            $table->json('profile_data')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['account_id', 'channel', 'external_id']);
            $table->index(['contact_id', 'channel']);
        });

        DB::statement("INSERT INTO contact_identities
                (id, account_id, contact_id, channel, external_id, display_name, is_primary, created_at, updated_at)
            SELECT UUID(), account_id, id, 'whatsapp', phone_normalized, name, 1, NOW(), NOW()
            FROM contacts
            WHERE phone_normalized IS NOT NULL AND phone_normalized <> ''");
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_identities');
    }
};
