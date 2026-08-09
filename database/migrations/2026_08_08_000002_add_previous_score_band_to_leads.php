<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Banda anterior del lead, para poder decir «se enfrió».
     *
     * Sin esto el score solo describe el presente y no avisa de nada: un lead
     * que cae de «caliente» a «frío» es la señal más accionable del copiloto —
     * algo que estaba por cerrarse se está perdiendo— y es invisible si solo se
     * guarda el estado actual.
     *
     * Solo se pisa cuando la banda **cambia**: si se sobreescribiera en cada
     * pasada nocturna, a las 24 horas siempre diría lo mismo que la actual y la
     * comparación nunca detectaría nada.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('score_band_previous', 12)->nullable()->after('score_band');
            $table->timestamp('score_band_changed_at')->nullable()->after('score_band_previous');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['score_band_previous', 'score_band_changed_at']);
        });
    }
};
