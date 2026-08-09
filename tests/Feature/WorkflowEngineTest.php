<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowEnrollment;
use App\Models\WorkflowPendingExecution;
use App\Models\WorkflowStep;
use App\Services\Workflows\EnrollmentSweeper;
use App\Services\Workflows\Guardrails;
use App\Services\Workflows\WorkflowEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T3 — motor de workflows con inscripcion dinamica.
 *
 * El orden de este archivo es deliberado: **primero los guardarrailes**. Son
 * los que impiden que esto le mande WhatsApp de mas a clientes reales, y se
 * escribieron antes que las funciones. Si alguno de esos tests se cae, no se
 * despliega aunque todo lo demas este verde.
 */
class WorkflowEngineTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Account $account;

    private Pipeline $pipeline;

    private $stages;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-10 10:00:00'); // lunes

        $this->owner = User::create(['name' => 'Owner', 'email' => 'o@test.com', 'password' => bcrypt('secret')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => 'owner']);
        $this->owner->refresh();

        $this->pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas', 'is_default' => true]);
        $this->stages = collect([
            ['name' => 'Nuevo', 'stage_type' => 'open'],
            ['name' => 'Negociacion', 'stage_type' => 'open'],
            ['name' => 'Ganado', 'stage_type' => 'won'],
        ])->map(fn ($s, $i) => PipelineStage::create([
            'pipeline_id' => $this->pipeline->id, 'position' => $i, 'color' => '#0ea5e9', ...$s,
        ]));
    }

    private int $phoneSeq = 0;

    private function makeLead(array $o = []): Lead
    {
        // Secuencial y no aleatorio: los tests del barredor crean cientos de
        // leads y `contacts` tiene único por (cuenta, teléfono) — con random
        // el test falla de a ratos por colisión, que es la peor clase de test.
        $contact = Contact::create([
            'account_id' => $this->account->id, 'name' => 'Contacto',
            'phone' => '+591700'.str_pad((string) ++$this->phoneSeq, 5, '0', STR_PAD_LEFT),
        ]);

        return Lead::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $o['stage_id'] ?? $this->stages[0]->id,
            'contact_id' => $contact->id,
            'title' => $o['title'] ?? 'Lead',
            'value' => 100,
            'source' => 'whatsapp',
            'status' => $o['status'] ?? 'open',
            'responsible_user_id' => $this->owner->id,
        ]);
    }

    /** @param array<string, mixed> $attrs */
    private function makeWorkflow(array $attrs = []): Workflow
    {
        return Workflow::create([
            'account_id' => $this->account->id,
            'created_by' => $this->owner->id,
            'name' => 'Seguimiento',
            'enrollment_type' => Workflow::ENROLLMENT_FILTER,
            'enrollment_filters' => ['version' => 2, 'conditions' => [
                ['field' => 'stage_id', 'op' => 'in', 'value' => [$this->stages[1]->id]],
            ]],
            'is_active' => true,
            ...$attrs,
        ]);
    }

    private function step(Workflow $w, string $type, array $config = [], int $position = 0, ?WorkflowStep $parent = null, ?string $branch = null): WorkflowStep
    {
        return WorkflowStep::create([
            'workflow_id' => $w->id,
            'parent_id' => $parent?->id,
            'branch_key' => $branch,
            'position' => $position,
            'step_type' => $type,
            'config' => $config,
        ]);
    }

    private function engine(): WorkflowEngine
    {
        return app(WorkflowEngine::class);
    }

    // ======================================================================
    // GUARDARRAILES — se rompe alguno, no se despliega
    // ======================================================================

    public function test_un_lead_no_puede_inscribirse_dos_veces(): void
    {
        $w = $this->makeWorkflow();
        $this->step($w, 'wait', ['minutes' => 60]);
        $lead = $this->makeLead();

        $this->assertNotNull($this->engine()->enroll($w, $lead));
        // Segunda inscripcion mientras sigue activo: rechazada.
        $this->assertNull($this->engine()->enroll($w, $lead));

        $this->assertSame(1, WorkflowEnrollment::where('workflow_id', $w->id)->count());
    }

    public function test_sin_reinscripcion_habilitada_el_barredor_no_reinscribe(): void
    {
        // El escenario mas peligroso del sistema: el barredor corre cada 10
        // min y el lead sigue cumpliendo el criterio.
        $w = $this->makeWorkflow();
        $this->step($w, 'add_note', ['text' => 'Hola']);
        $lead = $this->makeLead(['stage_id' => $this->stages[1]->id]);

        $sweeper = app(EnrollmentSweeper::class);
        $primera = $sweeper->sweep($w);
        $segunda = $sweeper->sweep($w);
        $tercera = $sweeper->sweep($w);

        $this->assertSame(1, $primera['enrolled']);
        $this->assertSame(0, $segunda['enrolled'], 'El barredor reinscribio: el cliente recibiria el mensaje otra vez.');
        $this->assertSame(0, $tercera['enrolled']);
    }

    public function test_la_reinscripcion_respeta_el_enfriamiento(): void
    {
        $w = $this->makeWorkflow([
            'allow_reenrollment' => true,
            'reenrollment_cooldown_minutes' => 120,
        ]);
        $this->step($w, 'end');
        $lead = $this->makeLead(['stage_id' => $this->stages[1]->id]);

        $this->engine()->enroll($w, $lead); // termina al instante (end)

        // Antes del enfriamiento: no.
        Carbon::setTestNow('2026-08-10 11:00:00');
        $this->assertNull($this->engine()->enroll($w, $lead));

        // Despues: si.
        Carbon::setTestNow('2026-08-10 13:00:00');
        $this->assertNotNull($this->engine()->enroll($w, $lead));
        $this->assertSame(2, WorkflowEnrollment::first()->enroll_count);
    }

    public function test_el_enfriamiento_tiene_un_minimo_del_sistema(): void
    {
        // Aunque se configure 1 minuto, rige el minimo: es un tope del sistema,
        // no una preferencia del usuario.
        $w = $this->makeWorkflow(['allow_reenrollment' => true, 'reenrollment_cooldown_minutes' => 1]);
        $this->step($w, 'end');
        $lead = $this->makeLead(['stage_id' => $this->stages[1]->id]);

        $this->engine()->enroll($w, $lead);
        Carbon::setTestNow('2026-08-10 10:05:00');

        $this->assertNull($this->engine()->enroll($w, $lead));
    }

    public function test_un_ciclo_se_corta_por_el_tope_de_pasos(): void
    {
        // change_stage dispara stage_changed, que en un sistema sin tope
        // volveria a entrar. Acá el contador corta y la corrida queda fallida
        // con el motivo, no colgada.
        $w = $this->makeWorkflow();
        $lead = $this->makeLead();

        $enrollment = WorkflowEnrollment::create([
            'account_id' => $this->account->id, 'workflow_id' => $w->id, 'lead_id' => $lead->id,
            'status' => WorkflowEnrollment::ACTIVE, 'enrolled_at' => now(),
            'steps_run' => Guardrails::MAX_STEPS_PER_ENROLLMENT,
        ]);

        $step = $this->step($w, 'add_note', ['text' => 'x']);
        $this->engine()->advance($enrollment, $step);

        $enrollment->refresh();
        $this->assertSame(WorkflowEnrollment::FAILED, $enrollment->status);
        // En toda la traza y no en la ultima fila: con el reloj congelado,
        // `latest('created_at')` no desempata entre filas del mismo instante.
        $this->assertTrue(
            $enrollment->stepRuns->contains(fn ($run) => str_contains((string) $run->detail, 'ciclo')),
        );
    }

    public function test_el_tope_diario_de_salientes_es_por_lead_y_cruza_workflows(): void
    {
        $lead = $this->makeLead(['stage_id' => $this->stages[1]->id]);

        // Tres workflows distintos, un mensaje cada uno: al cliente no le
        // importa cuantas automatizaciones tenga la empresa.
        foreach (range(1, 4) as $i) {
            $w = $this->makeWorkflow(['name' => "WF {$i}"]);
            $this->step($w, 'send_whatsapp', ['text' => 'Hola']);
            $this->engine()->enroll($w, $lead);
        }

        $enviados = \App\Models\WorkflowStepRun::where('status', 'ok')
            ->whereHas('step', fn ($q) => $q->where('step_type', 'send_whatsapp'))
            ->count();

        $this->assertLessThanOrEqual(Guardrails::MAX_OUTBOUND_PER_LEAD_PER_DAY, $enviados);
    }

    public function test_el_kill_switch_de_la_cuenta_para_todo(): void
    {
        $this->account->forceFill(['workflows_paused_at' => now()])->save();

        $w = $this->makeWorkflow();
        $this->step($w, 'add_note', ['text' => 'Hola']);
        $lead = $this->makeLead(['stage_id' => $this->stages[1]->id]);

        $this->assertNull($this->engine()->enroll($w, $lead));
        $this->assertSame(0, app(EnrollmentSweeper::class)->sweep($w)['enrolled']);
    }

    public function test_el_barredor_inscribe_por_lotes(): void
    {
        $w = $this->makeWorkflow();
        $this->step($w, 'end');

        // Mas candidatos que el tope de una pasada.
        foreach (range(1, Guardrails::MAX_ENROLLMENTS_PER_SWEEP + 5) as $i) {
            $this->makeLead(['stage_id' => $this->stages[1]->id, 'title' => "Lead {$i}"]);
        }

        $enrolled = app(EnrollmentSweeper::class)->sweep($w)['enrolled'];

        $this->assertSame(Guardrails::MAX_ENROLLMENTS_PER_SWEEP, $enrolled,
            'Una sola pasada no puede disparar todas las secuencias de golpe.');
    }

    public function test_un_workflow_incompleto_no_se_puede_activar(): void
    {
        $vacio = $this->makeWorkflow(['is_active' => false, 'enrollment_filters' => ['version' => 2, 'conditions' => []]]);

        $problemas = app(Guardrails::class)->activationProblems($vacio);

        $this->assertContains('El workflow no tiene ningún paso.', $problemas);
        // Un filtro vacio alcanza a TODOS los leads de la cuenta.
        $this->assertTrue(collect($problemas)->contains(fn ($p) => str_contains($p, 'todos los leads')));
    }

    public function test_un_paso_no_se_ejecuta_dos_veces_en_la_misma_corrida(): void
    {
        $w = $this->makeWorkflow();
        $step = $this->step($w, 'add_note', ['text' => 'Nota unica']);
        $lead = $this->makeLead();

        $enrollment = $this->engine()->enroll($w, $lead);

        // Reintentar el mismo paso de la misma corrida no vuelve a escribir.
        $this->engine()->advance($enrollment->refresh(), $step);

        $this->assertSame(1, $lead->notes()->count());
    }

    // ======================================================================
    // INSCRIPCION DINAMICA
    // ======================================================================

    public function test_un_lead_viejo_que_empieza_a_cumplir_entra_solo(): void
    {
        $w = $this->makeWorkflow();
        $this->step($w, 'add_note', ['text' => 'Entro al workflow']);

        $lead = $this->makeLead(['title' => 'Viejo']);

        // Todavia no cumple: no entra.
        $this->assertSame(0, app(EnrollmentSweeper::class)->sweep($w)['enrolled']);

        // Cambia la realidad y ahora si. Esto es lo que stage_automations no
        // puede hacer: reacciona a un evento, no a un estado.
        $lead->moveToStage($this->stages[1]);

        $this->assertSame(1, app(EnrollmentSweeper::class)->sweep($w)['enrolled']);
        $this->assertSame(1, $lead->notes()->count());
    }

    public function test_el_lead_sale_al_cumplir_la_meta(): void
    {
        $w = $this->makeWorkflow([
            'goal_filters' => ['version' => 2, 'conditions' => [
                ['field' => 'status', 'op' => 'in', 'value' => ['won']],
            ]],
        ]);
        $this->step($w, 'wait', ['minutes' => 60]);

        $lead = $this->makeLead(['stage_id' => $this->stages[1]->id]);
        app(EnrollmentSweeper::class)->sweep($w);

        $this->assertSame(WorkflowEnrollment::ACTIVE, WorkflowEnrollment::first()->status);

        // El lead compra: deja de recibir la secuencia de "seguis interesado".
        $lead->moveToStage($this->stages[2]);
        app(EnrollmentSweeper::class)->sweep($w);

        $enrollment = WorkflowEnrollment::first();
        $this->assertSame(WorkflowEnrollment::GOAL_MET, $enrollment->status);
        $this->assertSame(0, WorkflowPendingExecution::count(), 'La espera pendiente tiene que limpiarse.');
    }

    public function test_sale_si_deja_de_cumplir_el_criterio_cuando_esta_configurado(): void
    {
        $w = $this->makeWorkflow(['unenroll_when_criteria_lost' => true]);
        $this->step($w, 'wait', ['minutes' => 60]);

        $lead = $this->makeLead(['stage_id' => $this->stages[1]->id]);
        app(EnrollmentSweeper::class)->sweep($w);

        $lead->moveToStage($this->stages[0]); // ya no esta en Negociacion
        app(EnrollmentSweeper::class)->sweep($w);

        $this->assertSame(WorkflowEnrollment::UNENROLLED, WorkflowEnrollment::first()->status);
    }

    // ======================================================================
    // RECORRIDO DEL ARBOL
    // ======================================================================

    public function test_ejecuta_los_pasos_en_orden_hasta_la_espera(): void
    {
        $w = $this->makeWorkflow();
        $tag = Tag::create(['account_id' => $this->account->id, 'name' => 'Seguimiento', 'color' => '#000']);

        $this->step($w, 'add_note', ['text' => 'Primero'], 0);
        $this->step($w, 'add_tag', ['tag_id' => $tag->id], 1);
        $this->step($w, 'wait', ['minutes' => 120], 2);
        $this->step($w, 'add_note', ['text' => 'Despues de esperar'], 3);

        $lead = $this->makeLead();
        $this->engine()->enroll($w, $lead);

        $this->assertSame(1, $lead->notes()->count(), 'El segundo texto va despues de la espera.');
        $this->assertTrue($lead->tags()->whereKey($tag->id)->exists());
        $this->assertSame(1, WorkflowPendingExecution::count());

        // Vence la espera.
        Carbon::setTestNow('2026-08-10 12:30:00');
        $this->engine()->resume(WorkflowPendingExecution::first());

        $this->assertSame(2, $lead->refresh()->notes()->count());
        $this->assertSame(WorkflowEnrollment::COMPLETED, WorkflowEnrollment::first()->status);
    }

    public function test_la_rama_elige_por_condicion_y_sigue_por_su_camino(): void
    {
        $w = $this->makeWorkflow();
        $branch = $this->step($w, 'branch', ['filters' => ['version' => 2, 'conditions' => [
            ['field' => 'value', 'op' => 'gte', 'value' => 500],
        ]]], 0);

        $this->step($w, 'add_note', ['text' => 'Lead grande'], 0, $branch, 'yes');
        $this->step($w, 'add_note', ['text' => 'Lead chico'], 0, $branch, 'no');

        $lead = $this->makeLead();
        $lead->forceFill(['value' => 100])->save();

        $this->engine()->enroll($w, $lead);

        $this->assertSame('Lead chico', $lead->notes()->first()->text);
    }

    public function test_un_paso_que_falla_no_mata_la_inscripcion(): void
    {
        $w = $this->makeWorkflow();
        // Sin integracion de WhatsApp activa, este paso falla.
        $this->step($w, 'send_whatsapp', ['text' => 'Hola'], 0);
        $this->step($w, 'create_task', ['text' => 'Llamar', 'due_in_hours' => 2], 1);

        $lead = $this->makeLead();
        $this->engine()->enroll($w, $lead);

        // Que no se pueda mandar el WhatsApp no es razon para no agendar el
        // seguimiento.
        $this->assertSame(1, Task::where('lead_id', $lead->id)->count());

        $fallo = \App\Models\WorkflowStepRun::where('status', 'failed')->first();
        $this->assertNotNull($fallo, 'El fallo tiene que quedar en la traza, no en un log que nadie lee.');
        $this->assertStringContainsString('inactiva', $fallo->detail);
    }

    public function test_la_espera_se_corre_a_la_ventana_de_ejecucion(): void
    {
        // Lunes a viernes de 9 a 19: una espera que vence 3 AM se corre.
        $w = $this->makeWorkflow(['execution_window' => ['days' => [1, 2, 3, 4, 5], 'from' => '09:00', 'to' => '19:00']]);
        $this->step($w, 'wait', ['minutes' => 60 * 17], 0); // vence 03:00 del martes
        $this->step($w, 'add_note', ['text' => 'Buen dia'], 1);

        $lead = $this->makeLead();
        $this->engine()->enroll($w, $lead);

        $runAt = WorkflowPendingExecution::first()->run_at;

        // Un seguimiento automatico que sale 3:40 AM se lee como spam de robot.
        $this->assertGreaterThanOrEqual(9, $runAt->hour);
        $this->assertLessThan(19, $runAt->hour);
    }

    public function test_la_traza_registra_cada_paso(): void
    {
        $w = $this->makeWorkflow();
        $this->step($w, 'add_note', ['text' => 'Hola'], 0);
        $this->step($w, 'end', [], 1);

        $lead = $this->makeLead();
        $enrollment = $this->engine()->enroll($w, $lead);

        // Hoy un fallo de automatizacion solo deja un Log::warning que nadie
        // lee; acá cada paso deja que paso y por que.
        $this->assertGreaterThanOrEqual(2, $enrollment->stepRuns()->count());
    }
}
