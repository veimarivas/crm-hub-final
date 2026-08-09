<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Workflows con inscripción dinámica (T3 de mejoras2.md), al estilo HubSpot.
     *
     * La diferencia con `stage_automations` no son las ramas: es que acá se
     * declara **quién debe estar** en el workflow y el motor mete y saca leads
     * a medida que la realidad cambia, en vez de reaccionar a un evento suelto.
     */
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 150);
            $table->string('description', 500)->nullable();

            // `filter` = inscripción por criterios (dinámica, la barre el
            // scheduler). `event` = reacciona a algo puntual.
            $table->string('enrollment_type', 10)->default('filter');
            $table->json('enrollment_filters')->nullable();
            $table->string('trigger_type', 30)->nullable();
            $table->json('trigger_config')->nullable();

            // Sin re-inscripción por defecto: es la protección más importante
            // del sistema. Con el barredor corriendo cada 10 min, un workflow
            // reinscribible sin enfriamiento manda el mismo WhatsApp seis veces
            // por hora.
            $table->boolean('allow_reenrollment')->default(false);
            $table->unsignedInteger('reenrollment_cooldown_minutes')->nullable();

            // Meta: al cumplirse, el lead SALE del workflow. Sin esto, alguien
            // que ya compró sigue recibiendo «¿seguís interesado?».
            $table->json('goal_filters')->nullable();
            $table->boolean('unenroll_when_criteria_lost')->default(false);

            // {days:[1..7], from:'09:00', to:'19:00'} — lo que caiga afuera se
            // encola hasta la próxima ventana.
            $table->json('execution_window')->nullable();

            // Nace inactivo, siempre.
            $table->boolean('is_active')->default(false);
            $table->timestamp('last_swept_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'is_active']);
        });

        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('parent_id')->nullable()->constrained('workflow_steps')->cascadeOnDelete();

            // String y no booleano a propósito: HubSpot ramifica por valor
            // (etapa = A / B / C / resto), no solo sí/no. Un booleano acá
            // obligaría a rehacer la tabla en la primera rama de tres salidas.
            $table->string('branch_key', 40)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('step_type', 30);
            $table->json('config')->nullable();
            $table->timestamps();

            $table->index(['workflow_id', 'parent_id', 'position']);
        });

        Schema::create('workflow_enrollments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('lead_id')->constrained()->cascadeOnDelete();

            // active | completed | goal_met | unenrolled | failed
            $table->string('status', 12)->default('active');
            $table->foreignUuid('current_step_id')->nullable()->constrained('workflow_steps')->nullOnDelete();
            $table->string('enroll_reason', 60)->nullable();

            // La re-inscripción REUSA la fila en vez de crear otra. Así el
            // índice único de abajo vale siempre, sin índices parciales (que
            // MariaDB no tiene). El historial de pasos vive en
            // `workflow_step_runs`, discriminado por `enroll_count`.
            $table->unsignedInteger('enroll_count')->default(1);
            $table->unsignedInteger('steps_run')->default(0);
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // La garantía va en la base, no solo en el código: un lead no puede
            // estar dos veces en el mismo workflow.
            $table->unique(['workflow_id', 'lead_id']);
            $table->index(['account_id', 'status']);
        });

        Schema::create('workflow_step_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('enrollment_id')->constrained('workflow_enrollments')->cascadeOnDelete();
            $table->foreignUuid('step_id')->nullable()->constrained('workflow_steps')->nullOnDelete();
            $table->string('status', 10); // ok | skipped | failed
            $table->string('detail', 500)->nullable();

            // Idempotencia de los pasos que salen hacia afuera: reintentar la
            // corrida no vuelve a mandar el WhatsApp.
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->timestamp('created_at')->useCurrent();

            $table->index('enrollment_id');
        });

        Schema::create('workflow_pending_executions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('enrollment_id')->constrained('workflow_enrollments')->cascadeOnDelete();
            $table->foreignUuid('step_id')->nullable()->constrained('workflow_steps')->nullOnDelete();
            $table->timestamp('run_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index('run_at');
            // Una espera pendiente por inscripción: si se duplicara, el lead
            // avanzaría dos veces por el mismo tramo del árbol.
            $table->unique('enrollment_id');
        });

        // Kill switch por cuenta: parar todo sin deploy.
        Schema::table('accounts', function (Blueprint $table) {
            $table->timestamp('workflows_paused_at')->nullable()->after('default_currency');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', fn (Blueprint $table) => $table->dropColumn('workflows_paused_at'));
        Schema::dropIfExists('workflow_pending_executions');
        Schema::dropIfExists('workflow_step_runs');
        Schema::dropIfExists('workflow_enrollments');
        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('workflows');
    }
};
