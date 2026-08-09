<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowEnrollment;
use App\Models\WorkflowPendingExecution;
use App\Models\WorkflowStep;
use App\Services\Workflows\WorkflowEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T3 — constructor visual, simulador y activacion.
 *
 * Lo mas importante de este archivo es
 * `test_editar_los_pasos_no_deja_inscripciones_colgadas`: guardar el arbol
 * borrando y recreando pasos dejaria a los leads que estan esperando apuntando
 * a un paso inexistente, y su secuencia se cortaria en silencio.
 */
class WorkflowBuilderTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $agent;

    private Account $account;

    private Pipeline $pipeline;

    private $stages;

    private int $phoneSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-10 10:00:00');

        $this->owner = User::create(['name' => 'Owner', 'email' => 'o@test.com', 'password' => bcrypt('secret')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => 'owner']);
        $this->owner->refresh();

        $this->agent = User::create([
            'name' => 'Agente', 'email' => 'a@test.com', 'password' => bcrypt('secret'),
            'account_id' => $this->account->id, 'account_role' => 'agent',
        ]);

        $this->pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas', 'is_default' => true]);
        $this->stages = collect([
            ['name' => 'Nuevo', 'stage_type' => 'open'],
            ['name' => 'Negociacion', 'stage_type' => 'open'],
        ])->map(fn ($s, $i) => PipelineStage::create([
            'pipeline_id' => $this->pipeline->id, 'position' => $i, 'color' => '#0ea5e9', ...$s,
        ]));
    }

    private function makeLead(): Lead
    {
        $contact = Contact::create([
            'account_id' => $this->account->id, 'name' => 'Contacto',
            'phone' => '+591700'.str_pad((string) ++$this->phoneSeq, 5, '0', STR_PAD_LEFT),
        ]);

        return Lead::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stages[0]->id,
            'contact_id' => $contact->id,
            'title' => 'Lead', 'value' => 100, 'source' => 'whatsapp',
            'responsible_user_id' => $this->owner->id,
        ]);
    }

    private function makeWorkflow(array $attrs = []): Workflow
    {
        return Workflow::create([
            'account_id' => $this->account->id,
            'created_by' => $this->owner->id,
            'name' => 'Seguimiento',
            'enrollment_type' => Workflow::ENROLLMENT_FILTER,
            'enrollment_filters' => ['version' => 2, 'conditions' => [
                ['field' => 'stage_id', 'op' => 'in', 'value' => [$this->stages[0]->id]],
            ]],
            'is_active' => false,
            ...$attrs,
        ]);
    }

    // ---- Acceso ----

    public function test_los_workflows_son_admin_only(): void
    {
        $this->actingAs($this->agent)->get(route('workflows.index'))->assertForbidden();
    }

    public function test_un_workflow_de_otra_cuenta_no_se_abre(): void
    {
        $otroOwner = User::create(['name' => 'Otro', 'email' => 'x@test.com', 'password' => bcrypt('secret')]);
        $otraCuenta = Account::create(['name' => 'Otra', 'owner_user_id' => $otroOwner->id]);
        $ajeno = Workflow::create([
            'account_id' => $otraCuenta->id, 'name' => 'Ajeno',
            'enrollment_type' => 'filter', 'is_active' => false,
        ]);

        $this->actingAs($this->owner)->get(route('workflows.edit', $ajeno))->assertForbidden();
    }

    // ---- Creacion y activacion ----

    public function test_un_workflow_nace_inactivo(): void
    {
        $this->actingAs($this->owner)->post(route('workflows.store'), ['name' => 'Nuevo'])->assertRedirect();

        $this->assertFalse(Workflow::first()->is_active);
    }

    public function test_no_se_puede_activar_un_workflow_sin_pasos(): void
    {
        $w = $this->makeWorkflow();

        $this->actingAs($this->owner)
            ->patch(route('workflows.toggle', $w))
            ->assertSessionHasErrors('is_active');

        $this->assertFalse($w->refresh()->is_active);
    }

    public function test_no_se_puede_activar_con_criterio_vacio(): void
    {
        // Un filtro vacio alcanza a TODOS los leads de la cuenta: es la forma
        // mas facil de mandarle un mensaje automatico a toda la base.
        $w = $this->makeWorkflow(['enrollment_filters' => ['version' => 2, 'conditions' => []]]);
        WorkflowStep::create(['workflow_id' => $w->id, 'position' => 0, 'step_type' => 'end', 'config' => []]);

        $this->actingAs($this->owner)
            ->patch(route('workflows.toggle', $w))
            ->assertSessionHasErrors('is_active');
    }

    public function test_se_activa_cuando_la_configuracion_esta_completa(): void
    {
        $w = $this->makeWorkflow();
        WorkflowStep::create(['workflow_id' => $w->id, 'position' => 0, 'step_type' => 'add_note', 'config' => ['text' => 'Hola']]);

        $this->actingAs($this->owner)->patch(route('workflows.toggle', $w))->assertRedirect();

        $this->assertTrue($w->refresh()->is_active);
    }

    public function test_la_reinscripcion_sin_enfriamiento_suficiente_bloquea_la_activacion(): void
    {
        $w = $this->makeWorkflow(['allow_reenrollment' => true, 'reenrollment_cooldown_minutes' => 5]);
        WorkflowStep::create(['workflow_id' => $w->id, 'position' => 0, 'step_type' => 'end', 'config' => []]);

        $this->actingAs($this->owner)
            ->patch(route('workflows.toggle', $w))
            ->assertSessionHasErrors('is_active');
    }

    public function test_el_kill_switch_de_la_cuenta_se_prende_y_apaga(): void
    {
        $this->actingAs($this->owner)->patch(route('workflows.pause'))->assertRedirect();
        $this->assertNotNull($this->account->refresh()->workflows_paused_at);

        $this->actingAs($this->owner)->patch(route('workflows.pause'))->assertRedirect();
        $this->assertNull($this->account->refresh()->workflows_paused_at);
    }

    // ---- Guardado del arbol ----

    public function test_guarda_el_arbol_con_ramas(): void
    {
        $w = $this->makeWorkflow();

        $this->actingAs($this->owner)->put(route('workflows.steps', $w), ['steps' => [
            ['step_type' => 'add_note', 'config' => ['text' => 'Primero']],
            ['step_type' => 'branch', 'config' => ['filters' => ['version' => 2, 'conditions' => []]], 'children' => [
                ['step_type' => 'add_note', 'branch_key' => 'yes', 'config' => ['text' => 'Si']],
                ['step_type' => 'add_note', 'branch_key' => 'no', 'config' => ['text' => 'No']],
            ]],
        ]])->assertRedirect();

        $this->assertSame(4, WorkflowStep::where('workflow_id', $w->id)->count());

        $branch = WorkflowStep::where('step_type', 'branch')->first();
        $this->assertSame(2, WorkflowStep::where('parent_id', $branch->id)->count());
        $this->assertSame('Si', WorkflowStep::where('branch_key', 'yes')->first()->config['text']);
    }

    public function test_un_paso_desconocido_se_rechaza(): void
    {
        $w = $this->makeWorkflow();

        $this->actingAs($this->owner)
            ->put(route('workflows.steps', $w), ['steps' => [['step_type' => 'lanzar_misil', 'config' => []]]])
            ->assertSessionHasErrors('steps');
    }

    public function test_editar_los_pasos_no_deja_inscripciones_colgadas(): void
    {
        $w = $this->makeWorkflow(['is_active' => true]);

        // Arbol: espera + nota. Un lead queda esperando.
        $this->actingAs($this->owner)->put(route('workflows.steps', $w), ['steps' => [
            ['step_type' => 'wait', 'config' => ['minutes' => 120]],
            ['step_type' => 'add_note', 'config' => ['text' => 'Despues']],
        ]]);

        $lead = $this->makeLead();
        app(WorkflowEngine::class)->enroll($w->refresh(), $lead);

        $enrollment = WorkflowEnrollment::first();
        $this->assertSame(WorkflowEnrollment::ACTIVE, $enrollment->status);
        $this->assertSame(1, WorkflowPendingExecution::count());

        $waitId = WorkflowStep::where('step_type', 'wait')->first()->id;
        $noteId = WorkflowStep::where('step_type', 'add_note')->first()->id;

        // Se reordena y se conserva la nota, mandando los ids existentes: los
        // pasos que siguen estando NO se recrean.
        $this->actingAs($this->owner)->put(route('workflows.steps', $w), ['steps' => [
            ['id' => $noteId, 'step_type' => 'add_note', 'config' => ['text' => 'Despues']],
            ['id' => $waitId, 'step_type' => 'wait', 'config' => ['minutes' => 120]],
        ]])->assertRedirect();

        $this->assertSame($waitId, WorkflowStep::where('step_type', 'wait')->first()->id,
            'Recrear los pasos dejaria la espera pendiente apuntando a un id que ya no existe.');
        $this->assertSame(WorkflowEnrollment::ACTIVE, $enrollment->refresh()->status);
    }

    public function test_borrar_el_paso_donde_alguien_esperaba_cierra_su_inscripcion_con_motivo(): void
    {
        $w = $this->makeWorkflow(['is_active' => true]);

        $this->actingAs($this->owner)->put(route('workflows.steps', $w), ['steps' => [
            ['step_type' => 'wait', 'config' => ['minutes' => 120]],
        ]]);

        $lead = $this->makeLead();
        app(WorkflowEngine::class)->enroll($w->refresh(), $lead);
        $enrollment = WorkflowEnrollment::first();

        // Se elimina ese paso.
        $this->actingAs($this->owner)->put(route('workflows.steps', $w), ['steps' => [
            ['step_type' => 'add_note', 'config' => ['text' => 'Otra cosa']],
        ]])->assertRedirect();

        $enrollment->refresh();
        $this->assertSame(WorkflowEnrollment::UNENROLLED, $enrollment->status);

        // Colgado en silencio seria peor: al menos queda dicho por que salio.
        // Se busca en toda la traza y no en la ultima fila: con el reloj
        // congelado en los tests, `latest('created_at')` no desempata.
        $this->assertTrue(
            $enrollment->stepRuns->contains(fn ($run) => str_contains((string) $run->detail, 'se eliminó')),
            'La inscripcion tiene que decir por que salio.',
        );
    }

    // ---- Simulador ----

    public function test_el_simulador_no_escribe_nada(): void
    {
        $w = $this->makeWorkflow();
        $lead = $this->makeLead();

        $response = $this->actingAs($this->owner)->postJson(route('workflows.simulate', $w), [
            'lead_id' => $lead->id,
            'steps' => [
                ['step_type' => 'add_note', 'config' => ['text' => 'Nota simulada']],
                ['step_type' => 'create_task', 'config' => ['text' => 'Tarea simulada']],
                ['step_type' => 'send_whatsapp', 'config' => ['text' => 'Hola']],
            ],
        ])->assertOk();

        $this->assertCount(3, $response->json('steps'));

        // Lo unico que importa del simulador: que no haga nada de verdad.
        $this->assertSame(0, $lead->notes()->count());
        $this->assertSame(0, $lead->tasks()->count());
        $this->assertSame(0, $lead->events()->where('event_type', 'message_out')->count());
    }

    public function test_el_simulador_dice_los_mismos_motivos_por_los_que_fallaria(): void
    {
        $w = $this->makeWorkflow();
        $lead = $this->makeLead();

        $steps = $this->actingAs($this->owner)->postJson(route('workflows.simulate', $w), [
            'lead_id' => $lead->id,
            'steps' => [
                ['step_type' => 'send_whatsapp', 'config' => ['text' => 'Hola']],
                ['step_type' => 'add_tag', 'config' => ['tag_id' => '00000000-0000-0000-0000-000000000000']],
            ],
        ])->json('steps');

        $this->assertSame('failed', $steps[0]['status']);
        $this->assertStringContainsString('inactiva', $steps[0]['detail']);
        $this->assertStringContainsString('ya no existe', $steps[1]['detail']);
    }

    public function test_el_simulador_marca_lo_que_queda_despues_de_una_espera(): void
    {
        $w = $this->makeWorkflow();
        $lead = $this->makeLead();

        $steps = $this->actingAs($this->owner)->postJson(route('workflows.simulate', $w), [
            'lead_id' => $lead->id,
            'steps' => [
                ['step_type' => 'wait', 'config' => ['minutes' => 120]],
                ['step_type' => 'add_note', 'config' => ['text' => 'Despues']],
            ],
        ])->json('steps');

        $this->assertSame('later', $steps[0]['status']);
        $this->assertTrue($steps[1]['later'], 'Lo posterior a una espera no pasa «ahora».');
    }

    public function test_el_simulador_rechaza_un_lead_de_otra_cuenta(): void
    {
        $w = $this->makeWorkflow();

        $this->actingAs($this->owner)->postJson(route('workflows.simulate', $w), [
            'lead_id' => '00000000-0000-0000-0000-000000000000',
            'steps' => [],
        ])->assertStatus(422);
    }

    // ---- Conteo de inscripcion ----

    public function test_el_conteo_avisa_que_el_primer_barrido_va_por_lotes(): void
    {
        $w = $this->makeWorkflow();
        $this->makeLead();

        $json = $this->actingAs($this->owner)->postJson(route('workflows.enrollment-count', $w), [
            'filters' => ['version' => 2, 'conditions' => []],
        ])->assertOk()->json();

        $this->assertSame(1, $json['matching']);
        $this->assertSame(1, $json['firstSweep']);
    }
}
