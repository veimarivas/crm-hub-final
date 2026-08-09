<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Score del copiloto (T1 de mejoras2.md).
     *
     * `score_factors` guarda el desglose JUNTO al score y no se recalcula al
     * mostrarlo: un score sin el «por qué» no se acciona ni se audita, y
     * recalcular el motivo aparte abre la puerta a que el número y su
     * explicación se contradigan.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedTinyInteger('score')->nullable()->after('status');
            $table->string('score_band', 12)->nullable()->after('score');
            $table->json('score_factors')->nullable()->after('score_band');
            $table->timestamp('scored_at')->nullable()->after('score_factors');

            $table->index(['account_id', 'score']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'score']);
            $table->dropColumn(['score', 'score_band', 'score_factors', 'scored_at']);
        });
    }
};
