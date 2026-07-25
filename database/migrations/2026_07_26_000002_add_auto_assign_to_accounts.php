<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Round-robin: si true, los leads que entran sin responsable via WhatsApp,
            // formulario o lead_ad se asignan al agente con menos leads abiertos.
            $table->boolean('auto_assign_leads')->default(false)->after('default_currency');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('auto_assign_leads');
        });
    }
};
