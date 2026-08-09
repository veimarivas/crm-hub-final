<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Layout del dashboard, **por usuario** (T2 de mejoras2.md).
     *
     * Por usuario y no por cuenta a propósito: si un admin acomoda su tablero
     * no puede moverle el de nadie más. Un layout «de cuenta» como plantilla
     * inicial se puede sumar después sin romper esto.
     *
     * Sin filas para un usuario = layout por defecto según su rol. Eso evita
     * tener que sembrar filas al crear cada usuario y hace que agregar un
     * widget nuevo al registro lo muestre solo a quien nunca tocó su tablero,
     * sin migración de datos.
     */
    public function up(): void
    {
        Schema::create('dashboard_widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('widget_key', 40);
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('size', 6)->default('md'); // sm | md | lg | full
            $table->json('config')->nullable();
            $table->boolean('is_visible')->default(true);
            $table->timestamps();

            // Un usuario no puede tener el mismo widget dos veces.
            $table->unique(['user_id', 'widget_key']);
            $table->index(['user_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_widgets');
    }
};
