<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Alcance del Inbox por rol.
 *
 * El agente ve EXCLUSIVAMENTE lo que se le asigno: ni los leads sin
 * responsable ni los de sus companeros. Con el round-robin repartiendo
 * automaticamente, un lead sin asignar es trabajo que el admin todavia no
 * distribuyo, no una bandeja comun de la que cualquiera puede tomar.
 */
class InboxScopeTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private User $agent;

    private User $otroAgente;

    private PipelineStage $stage;

    private Pipeline $pipeline;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Administrador', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        $this->agent = $this->makeAgent('daniel@test.com', 'Daniel');
        $this->otroAgente = $this->makeAgent('silvia@test.com', 'Silvia');

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

    private function makeLead(?User $responsible, string $name): Lead
    {
        $contact = Contact::create(['account_id' => $this->account->id, 'name' => $name, 'phone' => '5917'.random_int(1000000, 9999999)]);

        return Lead::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stage->id,
            'contact_id' => $contact->id,
            'title' => "WhatsApp: {$name}",
            'source' => 'manual', // manual: el round-robin no lo reasigna
            'wacrm_conversation_id' => 'conv-'.$name,
            'responsible_user_id' => $responsible?->id,
        ]);
    }

    /** @return array<int, string> titulos visibles en el Inbox */
    private function inboxTitles(User $as, string $filter = 'all'): array
    {
        $response = $this->actingAs($as)->get(route('inbox', ['filter' => $filter]));
        $response->assertOk();

        return collect($response->viewData('page')['props']['items'])->pluck('title')->all();
    }

    public function test_el_agente_no_ve_leads_sin_responsable(): void
    {
        $this->makeLead($this->agent, 'Mio');
        $this->makeLead(null, 'Huerfano');

        $titles = $this->inboxTitles($this->agent);

        $this->assertContains('WhatsApp: Mio', $titles);
        $this->assertNotContains('WhatsApp: Huerfano', $titles);
    }

    public function test_el_agente_no_ve_los_leads_de_otro_agente(): void
    {
        $this->makeLead($this->agent, 'Mio');
        $this->makeLead($this->otroAgente, 'Ajeno');

        $this->assertSame(['WhatsApp: Mio'], $this->inboxTitles($this->agent));
    }

    public function test_el_filtro_sin_responder_tampoco_muestra_lo_ajeno(): void
    {
        $this->makeLead($this->otroAgente, 'Ajeno');
        $this->makeLead(null, 'Huerfano');

        $this->assertSame([], $this->inboxTitles($this->agent, 'unresponded'));
    }

    public function test_un_filtro_de_admin_en_la_url_cae_a_la_bandeja_propia(): void
    {
        $this->makeLead($this->agent, 'Mio');
        $this->makeLead(null, 'Huerfano');

        $response = $this->actingAs($this->agent)->get(route('inbox', ['filter' => 'unassigned']));

        // No un vacio confuso: se le devuelve su propia bandeja.
        $this->assertSame('mine', $response->viewData('page')['props']['filter']);
        $this->assertSame(['WhatsApp: Mio'], collect($response->viewData('page')['props']['items'])->pluck('title')->all());
    }

    public function test_el_admin_sigue_viendo_todo_incluido_lo_sin_asignar(): void
    {
        $this->makeLead($this->agent, 'Mio');
        $this->makeLead($this->otroAgente, 'Ajeno');
        $this->makeLead(null, 'Huerfano');

        $titles = $this->inboxTitles($this->owner);

        $this->assertCount(3, $titles);
        $this->assertContains('WhatsApp: Huerfano', $titles);
    }
}
