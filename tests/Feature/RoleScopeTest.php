<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Qué ve y qué puede tocar un agente frente a un admin.
 *
 * Los tres casos que cubre nacieron del mismo problema: la UI ocultaba la
 * opción pero el servidor no la cortaba, así que bastaba una URL a mano.
 */
class RoleScopeTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private User $agente;

    private User $otro;

    private Pipeline $pipeline;

    private PipelineStage $stage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Administrador', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        $this->agente = $this->makeAgent('daniel@test.com', 'Daniel');
        $this->otro = $this->makeAgent('silvia@test.com', 'Silvia');

        $this->pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas', 'is_default' => true]);
        $this->stage = PipelineStage::create(['pipeline_id' => $this->pipeline->id, 'name' => 'Nuevo', 'stage_type' => 'open', 'position' => 0]);
    }

    private function makeAgent(string $email, string $name): User
    {
        return User::create([
            'name' => $name, 'email' => $email, 'password' => bcrypt('password'),
            'account_id' => $this->account->id, 'account_role' => User::ROLE_AGENT,
        ]);
    }

    private function makeTask(User $assignee, string $text): Task
    {
        return Task::create([
            'account_id' => $this->account->id,
            'assigned_to' => $assignee->id,
            'created_by' => $this->owner->id,
            'task_type' => 'call',
            'text' => $text,
            'due_at' => now()->addDay(),
        ]);
    }

    private function makeLead(?User $responsible): Lead
    {
        $contact = Contact::create(['account_id' => $this->account->id, 'name' => 'Ana', 'phone' => '59170000000']);

        return Lead::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stage->id,
            'contact_id' => $contact->id,
            'title' => 'Lead de prueba',
            'source' => 'manual',
            'responsible_user_id' => $responsible?->id,
        ]);
    }

    // ---- Tareas ----

    public function test_el_agente_solo_ve_sus_tareas_aunque_pida_las_del_equipo(): void
    {
        $this->makeTask($this->agente, 'Mia');
        $this->makeTask($this->otro, 'Ajena');

        // ?mine=0 es el toggle del admin; para el agente no debe aplicar.
        $props = $this->actingAs($this->agente)
            ->get(route('tasks.index', ['view' => 'list', 'mine' => 0]))
            ->viewData('page')['props'];

        $this->assertSame(['Mia'], collect($props['tasks']['data'])->pluck('text')->all());
        $this->assertFalse($props['isAdmin']);
    }

    public function test_el_admin_si_puede_ver_las_tareas_del_equipo(): void
    {
        $this->makeTask($this->agente, 'Mia');
        $this->makeTask($this->otro, 'Ajena');

        $props = $this->actingAs($this->owner)
            ->get(route('tasks.index', ['view' => 'list', 'mine' => 0]))
            ->viewData('page')['props'];

        $this->assertCount(2, $props['tasks']['data']);
        $this->assertTrue($props['isAdmin']);
    }

    public function test_el_agente_no_puede_operar_sobre_la_tarea_de_otro(): void
    {
        $ajena = $this->makeTask($this->otro, 'Ajena');

        $this->actingAs($this->agente)->post(route('tasks.complete', $ajena))->assertForbidden();
        $this->actingAs($this->agente)->post(route('tasks.snooze', $ajena), ['minutes' => 60])->assertForbidden();
        $this->actingAs($this->agente)->delete(route('tasks.destroy', $ajena))->assertForbidden();

        $this->assertNull($ajena->fresh()->completed_at);
        $this->assertNotNull($ajena->fresh());
    }

    public function test_el_agente_si_completa_la_suya(): void
    {
        $mia = $this->makeTask($this->agente, 'Mia');

        $this->actingAs($this->agente)->post(route('tasks.complete', $mia))->assertRedirect();

        $this->assertNotNull($mia->fresh()->completed_at);
    }

    // ---- Empresas ----

    public function test_empresas_es_solo_de_administracion(): void
    {
        $company = Company::create(['account_id' => $this->account->id, 'name' => 'ACME']);

        $this->actingAs($this->agente)->get(route('companies.index'))->assertForbidden();
        $this->actingAs($this->agente)->delete(route('companies.destroy', $company))->assertForbidden();

        $this->actingAs($this->owner)->get(route('companies.index'))->assertOk();
    }

    // ---- Zona peligrosa del lead ----

    public function test_el_responsable_no_puede_borrar_su_lead_ni_el_historial(): void
    {
        $lead = $this->makeLead($this->agente);

        // Es SU lead — puede verlo y trabajarlo, pero no hacerlo desaparecer.
        $this->actingAs($this->agente)->get(route('leads.show', $lead))->assertOk();
        $this->actingAs($this->agente)->delete(route('leads.destroy', $lead))->assertForbidden();

        $this->assertNotNull($lead->fresh());
    }

    public function test_el_admin_si_puede_borrar_el_lead(): void
    {
        $lead = $this->makeLead($this->agente);

        $this->actingAs($this->owner)->delete(route('leads.destroy', $lead))->assertRedirect();

        $this->assertNull($lead->fresh());
    }
}
