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

/**
 * Aviso en vivo de mensajes entrantes (`/notifications/recent-inbound`).
 *
 * Se consulta desde toda la app, así que el scope por rol tiene que ser tan
 * estricto como el del Inbox: un agente solo se entera de los contactos que
 * tiene asignados.
 */
class LiveInboundTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private User $agente;

    private Pipeline $pipeline;

    private PipelineStage $stage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        $this->agente = User::create([
            'name' => 'Daniel', 'email' => 'daniel@test.com', 'password' => bcrypt('password'),
            'account_id' => $this->account->id, 'account_role' => User::ROLE_AGENT,
        ]);

        $this->pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas', 'is_default' => true]);
        $this->stage = PipelineStage::create(['pipeline_id' => $this->pipeline->id, 'name' => 'Nuevo', 'stage_type' => 'open', 'position' => 0]);
    }

    private function leadWithMessage(?User $responsible, string $name, string $text = 'Hola'): Lead
    {
        $contact = Contact::create([
            'account_id' => $this->account->id,
            'name' => $name,
            'phone' => '5917'.random_int(1000000, 9999999),
        ]);

        $lead = Lead::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stage->id,
            'contact_id' => $contact->id,
            'title' => "WhatsApp: {$name}",
            'source' => 'manual',
            'wacrm_conversation_id' => 'conv-'.random_int(1, 99999),
            'responsible_user_id' => $responsible?->id,
        ]);

        LeadEvent::create([
            'lead_id' => $lead->id,
            'account_id' => $this->account->id,
            'event_type' => 'message_in',
            'payload' => ['text' => $text, 'type' => 'text'],
        ]);

        return $lead;
    }

    private function poll(User $as, ?string $since = null): array
    {
        return $this->actingAs($as)
            ->getJson(route('notifications.recent-inbound', array_filter(['since' => $since])))
            ->assertOk()
            ->json();
    }

    public function test_devuelve_los_entrantes_nuevos_con_su_contacto(): void
    {
        $this->leadWithMessage($this->agente, 'Ana', 'Quiero información');

        $data = $this->poll($this->agente, now()->subMinutes(5)->toIso8601String());

        $this->assertCount(1, $data['messages']);
        $this->assertSame('Ana', $data['messages'][0]['contact']);
        $this->assertSame('Quiero información', $data['messages'][0]['preview']);
        $this->assertNotEmpty($data['messages'][0]['lead_id'], 'El toast abre la ficha del lead.');
    }

    public function test_el_agente_solo_se_entera_de_sus_asignados(): void
    {
        $this->leadWithMessage($this->owner, 'Ajena');
        $this->leadWithMessage(null, 'Sin asignar');
        $this->leadWithMessage($this->agente, 'Mia');

        $data = $this->poll($this->agente, now()->subMinutes(5)->toIso8601String());

        $this->assertSame(['Mia'], collect($data['messages'])->pluck('contact')->all());
    }

    public function test_el_admin_ve_todos(): void
    {
        $this->leadWithMessage($this->agente, 'Una');
        $this->leadWithMessage(null, 'Otra');

        $this->assertCount(2, $this->poll($this->owner, now()->subMinutes(5)->toIso8601String())['messages']);
    }

    public function test_los_salientes_no_disparan_aviso(): void
    {
        $lead = $this->leadWithMessage($this->agente, 'Ana');

        LeadEvent::create([
            'lead_id' => $lead->id,
            'account_id' => $this->account->id,
            'event_type' => 'message_out',
            'payload' => ['text' => 'Le respondo'],
        ]);

        $data = $this->poll($this->agente, now()->subMinutes(5)->toIso8601String());

        $this->assertCount(1, $data['messages']);
        $this->assertSame('Hola', $data['messages'][0]['preview']);
    }

    public function test_un_since_viejo_no_trae_una_avalancha(): void
    {
        $lead = $this->leadWithMessage($this->agente, 'Ana');
        LeadEvent::where('lead_id', $lead->id)->update(['created_at' => now()->subDays(3)]);

        $this->assertCount(0, $this->poll($this->agente, now()->subDays(5)->toIso8601String())['messages']);
    }

    public function test_los_adjuntos_se_describen_por_su_tipo(): void
    {
        $lead = $this->leadWithMessage($this->agente, 'Ana', '');
        LeadEvent::where('lead_id', $lead->id)->update(['payload' => json_encode(['type' => 'audio'])]);

        $data = $this->poll($this->agente, now()->subMinutes(5)->toIso8601String());

        $this->assertSame('🎙 Audio', $data['messages'][0]['preview']);
    }

    public function test_un_sticker_se_describe_por_su_tipo(): void
    {
        $lead = $this->leadWithMessage($this->agente, 'Ana', '');
        LeadEvent::where('lead_id', $lead->id)->update(['payload' => json_encode(['type' => 'sticker'])]);

        $data = $this->poll($this->agente, now()->subMinutes(5)->toIso8601String());

        $this->assertSame('🟪 Sticker', $data['messages'][0]['preview']);
    }
}
