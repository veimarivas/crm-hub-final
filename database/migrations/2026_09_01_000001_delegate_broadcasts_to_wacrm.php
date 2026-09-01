<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D1b — Komo deja de enviar broadcasts y delega en el wacrm.
 *
 * `wacrm_broadcast_id` es lo que convierte a la tabla local en un registro de
 * **qué se pidió** en vez de un registro de qué se envió: el estado real vive
 * del otro lado, que es quien habla con Meta.
 *
 * `report` guarda el informe de audiencia que devuelve el wacrm (pedidos,
 * fuera de ventana, sin conversación). Va acá y no se recalcula: dentro de una
 * semana la ventana de esos contactos es otra, y «se mandó a 40 de 300» tiene
 * que seguir contestándose con los números de aquel día.
 *
 * Los broadcasts anteriores quedan con `wacrm_broadcast_id = null` y la
 * pantalla los sigue mostrando con sus contadores locales: son historia, y
 * reescribirla sería mentir sobre lo que pasó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->uuid('wacrm_broadcast_id')->nullable()->after('status');
            $table->json('report')->nullable()->after('wacrm_broadcast_id');
        });
    }

    public function down(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->dropColumn(['wacrm_broadcast_id', 'report']);
        });
    }
};
