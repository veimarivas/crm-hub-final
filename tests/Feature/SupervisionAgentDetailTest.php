<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupervisionAgentDetailTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private User $agent;

    private PipelineStage $stage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Administrador', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        $this->agent = User::create([
            'name' => 'Daniel', 'email' => 'daniel@test.com', 'password' => bcrypt('password'),
            'account_id' => $this->account->id, 'account_role' => User::ROLE_AGENT,
        ]);

        $pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas', 'is_default' => true]);
        $this->stage = PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Nuevo', 'stage_type' => 'open', 'position' => 0]);
    }

    private function agentLoggedIn(): User
    {
        $agent = User::create([
            'name' => 'Pepe', 'email' => 'pepe@test.com', 'password' => bcrypt('password'),
            'account_id' => $this->account->id, 'account_role' => User::ROLE_AGENT,
        ]);
        $this->actingAs($agent);

        return $agent;
    }

    private function makeLead(?User $responsible = null, string $name = 'Ana'): Lead
    {
        $contact = Contact::create(['account_id' => $this->account->id, 'name' => $name, 'phone' => '5917'.random_int(1000000, 9999999)]);

        return Lead::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $this->stage->pipeline_id,
            'stage_id' => $this->stage->id,
            'contact_id' => $contact->id,
            'title' => "WhatsApp: {$name}",
            'source' => 'whatsapp',
            'wacrm_conversation_id' => 'conv-'.$name,
            'responsible_user_id' => $responsible?->id,
        ]);
    }

    private function msg(Lead $lead, string $type, string $at, ?User $sender = null, bool $bot = false): void
    {
        LeadEvent::create([
            'lead_id' => $lead->id,
            'account_id' => $this->account->id,
            'user_id' => $sender?->id,
            'event_type' => $type,
            'payload' => $bot ? ['sender' => 'bot'] : ['sender' => 'agent'],
        ])->forceFill(['created_at' => $at])->save();
    }

    public function test_un_agente_no_entra_a_la_ficha(): void
    {
        $this->agentLoggedIn();

        $this->get(route('supervision.agent', $this->agent->id))->assertForbidden();
    }

    public function test_un_admin_de_otra_cuenta_recibe_403(): void
    {
        $other = User::create(['name' => 'Externo', 'email' => 'ext@test.com', 'password' => bcrypt('password')]);
        $otherAccount = Account::create(['name' => 'Otra', 'owner_user_id' => $other->id]);
        $other->update(['account_id' => $otherAccount->id, 'account_role' => User::ROLE_OWNER]);
        $this->actingAs($other);

        $this->get(route('supervision.agent', $this->agent->id))->assertForbidden();
    }

    public function test_carga_la_ficha_con_kpis_histograma_y_pendientes_del_responsable(): void
    {
        $lead = $this->makeLead($this->agent, 'Ana');
        $this->msg($lead, 'message_in', now()->subMinutes(90)->toDateTimeString());
        $this->msg($lead, 'message_out', now()->subMinutes(85)->toDateTimeString(), $this->agent);

        $this->actingAs($this->owner);

        $response = $this->get(route('supervision.agent', $this->agent->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Supervision/Agent')
                ->where('agent.name', 'Daniel')
                ->has('kpis')
                ->has('histogram')
                ->has('leads', 1)
                ->has('conversion')
                ->has('operatives')
                ->where('sla_minutes', 30));
    }
}