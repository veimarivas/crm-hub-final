<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Horario de atencion + auto-respuesta fuera de hora
            $table->boolean('business_hours_enabled')->default(false)->after('auto_assign_leads');
            $table->boolean('out_of_hours_reply_enabled')->default(false)->after('business_hours_enabled');
            $table->text('out_of_hours_message')->nullable()->after('out_of_hours_reply_enabled');
            $table->string('business_hours_timezone', 64)->default('America/La_Paz')->after('out_of_hours_message');
            // JSON con schedule semanal: {mon: {from: '09:00', to: '18:00'}, ..., sat: null}
            $table->json('business_hours_schedule')->nullable()->after('business_hours_timezone');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn([
                'business_hours_enabled',
                'out_of_hours_reply_enabled',
                'out_of_hours_message',
                'business_hours_timezone',
                'business_hours_schedule',
            ]);
        });
    }
};
