<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\StageAutomation;
use App\Models\Task;
use App\Models\User;
use App\Services\DigitalPipeline\Recipes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Plantillas por etapa, edición en el sitio y vista previa.
 */
class StageAutomationBuilderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $account;

    private Pipeline $pipeline;

    private $stages;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Vendedor', 'email' => 'v@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->user->id]);
        $this->user->update(['account_id' => $this->account->id, 'account_role' => 'owner']);
        $this->user->refresh();

        $this->pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas', 'is_default' => true]);
        $this->stages = collect([
            ['name' => 'Nuevo', 'stage_type' => 'open'],
            ['name' => 'Ganado', 'stage_type' => 'won'],
        ])->map(fn ($s, $i) => PipelineStage::create(['pipeline_id' => $this->pipeline->id, 'position' => $i, ...$s]));

        $contact = Contact::create([
            'account_id' => $this->account->id,
            'name' => 'Ana',
            'phone' => '59170000001',
        ]);

        $this->lead = Lead::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stages[0]->id,
            'contact_id' => $contact->id,
            'responsible_user_id' => $this->user->id,
            'title' => 'Maestría en Gestión',
            'value' => 1200,
        ]);
    }

    private function activateWhatsapp(): void
    {
        Integration::create([
            'account_id' => $this->account->id,
            'wacrm_url' => 'https://wacrm.test',
            'wacrm_api_key' => 'k',
            'is_active' => true,
        ]);
    }

    private function simulate(array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->postJson(
            route('pipelines.automations.simulate', $this->pipeline->id),
            array_merge(['stage_id' => $this->stages[0]->id, 'lead_id' => $this->lead->id], $payload),
        );
    }

    public function test_el_index_trae_plantillas_leads_de_muestra_y_conteo_por_etapa(): void
    {
        $this->actingAs($this->user)
            ->get(route('pipelines.automations', $this->pipeline->id))
            ->assertInertia(fn ($page) => $page
                ->component('Pipelines/Automations')
                ->has('recipes', count(Recipes::all()))
                ->where('stages.0.leads_count', 1)
                ->where('stages.1.leads_count', 0)
                ->where('sampleLeads.0.title', 'Maestría en Gestión')
                ->where('whatsappEnabled', false));
    }

    public function test_aplicar_una_plantilla_crea_todas_sus_acciones(): void
    {
        $this->activateWhatsapp();

        $this->actingAs($this->user)
            ->post(route('pipelines.automations.recipe', $this->pipeline->id), [
                'stage_id' => $this->stages[1]->id,
                'recipe' => 'felicitar-ganado',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $created = StageAutomation::where('stage_id', $this->stages[1]->id)->get();

        $this->assertCount(2, $created);
        $this->assertSame(['send_whatsapp', 'add_note'], $created->pluck('action_type')->all());
        $this->assertTrue($created->every(fn ($a) => $a->is_active));
    }

    public function test_una_plantilla_inexistente_es_rechazada(): void
    {
        $this->actingAs($this->user)
            ->post(route('pipelines.automations.recipe', $this->pipeline->id), [
                'stage_id' => $this->stages[0]->id,
                'recipe' => 'no-existe',
            ])
            ->assertSessionHasErrors('recipe');

        $this->assertSame(0, StageAutomation::count());
    }

    public function test_no_se_puede_aplicar_una_plantilla_a_una_etapa_de_otro_pipeline(): void
    {
        $otro = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Otro']);
        $ajena = PipelineStage::create(['pipeline_id' => $otro->id, 'name' => 'X', 'position' => 0]);

        $this->actingAs($this->user)
            ->post(route('pipelines.automations.recipe', $this->pipeline->id), [
                'stage_id' => $ajena->id,
                'recipe' => 'bienvenida',
            ])
            ->assertStatus(422);
    }

    public function test_editar_conserva_el_contador_de_ejecuciones(): void
    {
        $automation = StageAutomation::create([
            'account_id' => $this->account->id,
            'stage_id' => $this->stages[0]->id,
            'action_type' => 'create_task',
            'config' => ['text' => 'Lamar a {name}', 'task_type' => 'call', 'due_in_hours' => 2],
            'execution_count' => 7,
        ]);

        $this->actingAs($this->user)
            ->patch(route('automations.update', $automation), [
                'action_type' => 'create_task',
                'config' => ['text' => 'Llamar a {name}', 'task_type' => 'call', 'due_in_hours' => 4, 'assigned_to' => ''],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $automation->refresh();
        $this->assertSame('Llamar a {name}', $automation->config['text']);
        $this->assertSame(4, $automation->config['due_in_hours']);
        // `assigned_to` vacío no se guarda: si no, pisaría el fallback al responsable del lead.
        $this->assertArrayNotHasKey('assigned_to', $automation->config);
        $this->assertSame(7, $automation->execution_count);
    }

    public function test_no_se_puede_editar_una_automatizacion_de_otra_cuenta(): void
    {
        $otroUser = User::create(['name' => 'Otro', 'email' => 'o@test.com', 'password' => bcrypt('x')]);
        $otraCuenta = Account::create(['name' => 'Otra', 'owner_user_id' => $otroUser->id]);
        $ajena = StageAutomation::create([
            'account_id' => $otraCuenta->id,
            'stage_id' => $this->stages[0]->id,
            'action_type' => 'add_note',
            'config' => ['text' => 'privado'],
        ]);

        $this->actingAs($this->user)
            ->patch(route('automations.update', $ajena), [
                'action_type' => 'add_note',
                'config' => ['text' => 'hackeado'],
            ])
            ->assertStatus(403);

        $this->assertSame('privado', $ajena->fresh()->config['text']);
    }

    public function test_la_vista_previa_interpola_y_no_ejecuta_nada(): void
    {
        Http::fake();
        $this->activateWhatsapp();

        StageAutomation::create([
            'account_id' => $this->account->id,
            'stage_id' => $this->stages[0]->id,
            'action_type' => 'send_whatsapp',
            'config' => ['text' => 'Hola {name}, sobre {title} por {value}'],
        ]);
        StageAutomation::create([
            'account_id' => $this->account->id,
            'stage_id' => $this->stages[0]->id,
            'action_type' => 'create_task',
            'config' => ['text' => 'Llamar a {name}', 'task_type' => 'call', 'due_in_hours' => 2],
        ]);

        $response = $this->simulate()->assertOk();

        $response->assertJsonPath('steps.0.status', 'ok');
        $response->assertJsonPath('steps.0.detail', 'Hola Ana, sobre Maestría en Gestión por 1200.00');
        $response->assertJsonPath('steps.1.status', 'ok');
        $response->assertJsonPath('steps.1.meta.task_type', 'call');
        // Sin `assigned_to`, la tarea cae en el responsable del lead.
        $response->assertJsonPath('steps.1.meta.assignee', 'Vendedor');

        $this->assertSame(0, Task::count());
        $this->assertSame(0, $this->lead->events()->count());
        Http::assertNothingSent();
    }

    public function test_la_vista_previa_avisa_que_whatsapp_esta_inactivo(): void
    {
        StageAutomation::create([
            'account_id' => $this->account->id,
            'stage_id' => $this->stages[0]->id,
            'action_type' => 'send_whatsapp',
            'config' => ['text' => 'Hola {name}'],
        ]);

        $this->simulate()->assertOk()
            ->assertJsonPath('steps.0.status', 'error')
            ->assertJsonPath('steps.0.note', 'La integración con WhatsApp está inactiva: el mensaje no saldría.');
    }

    public function test_la_vista_previa_avisa_de_un_lead_sin_telefono(): void
    {
        $this->activateWhatsapp();

        $sinContacto = Lead::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stages[0]->id,
            'title' => 'Lead sin contacto',
        ]);

        StageAutomation::create([
            'account_id' => $this->account->id,
            'stage_id' => $this->stages[0]->id,
            'action_type' => 'send_whatsapp',
            'config' => ['text' => 'Hola'],
        ]);

        $this->simulate(['lead_id' => $sinContacto->id])->assertOk()
            ->assertJsonPath('steps.0.status', 'error')
            ->assertJsonPath('steps.0.note', 'Este lead no tiene teléfono: el mensaje no saldría.');
    }

    public function test_la_vista_previa_marca_las_pausadas_sin_evaluarlas(): void
    {
        StageAutomation::create([
            'account_id' => $this->account->id,
            'stage_id' => $this->stages[0]->id,
            'action_type' => 'add_note',
            'config' => ['text' => 'nota'],
            'is_active' => false,
        ]);

        $this->simulate()->assertOk()->assertJsonPath('steps.0.status', 'paused');
    }

    public function test_la_vista_previa_rechaza_una_etapa_de_otro_pipeline(): void
    {
        $otro = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Otro']);
        $ajena = PipelineStage::create(['pipeline_id' => $otro->id, 'name' => 'X', 'position' => 0]);

        $this->simulate(['stage_id' => $ajena->id])->assertStatus(422);
    }

    public function test_la_vista_previa_ignora_leads_de_otra_cuenta(): void
    {
        $otroUser = User::create(['name' => 'Otro', 'email' => 'o@test.com', 'password' => bcrypt('x')]);
        $otraCuenta = Account::create(['name' => 'Otra', 'owner_user_id' => $otroUser->id]);
        $otroPipeline = Pipeline::create(['account_id' => $otraCuenta->id, 'name' => 'Suyo']);
        $otraEtapa = PipelineStage::create(['pipeline_id' => $otroPipeline->id, 'name' => 'Y', 'position' => 0]);
        $ajeno = Lead::create([
            'account_id' => $otraCuenta->id,
            'pipeline_id' => $otroPipeline->id,
            'stage_id' => $otraEtapa->id,
            'title' => 'Ajeno',
        ]);

        StageAutomation::create([
            'account_id' => $this->account->id,
            'stage_id' => $this->stages[0]->id,
            'action_type' => 'add_note',
            'config' => ['text' => 'Nota de {title}'],
        ]);

        // El lead ajeno se ignora: el texto queda sin interpolar.
        $this->simulate(['lead_id' => $ajeno->id])->assertOk()
            ->assertJsonPath('lead', null)
            ->assertJsonPath('steps.0.detail', 'Nota de {title}');
    }
}
