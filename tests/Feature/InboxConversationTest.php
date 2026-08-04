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
 * El Inbox ahora abre el hilo en la misma pantalla (tres columnas, como el
 * wacrm) en vez de mandar a la ficha del lead y volver.
 *
 * Lo que hay que fijar es el alcance: el panel del chat es una vía nueva para
 * leer conversaciones, y tiene que cortar igual que el listado — el agente ve
 * y contesta solo lo suyo.
 */
class InboxConversationTest extends TestCase
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

    private function lead(string $name, ?User $responsable, string $texto = 'Hola'): Lead
    {
        $contact = Contact::create([
            'account_id' => $this->account->id,
            'name' => $name,
            'phone' => '5917'.random_int(1000000, 9999999),
            'phone_normalized' => '5917'.random_int(1000000, 9999999),
        ]);

        $lead = Lead::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stage->id,
            'contact_id' => $contact->id,
            'title' => $name,
            'source' => 'whatsapp',
            'responsible_user_id' => $responsable?->id,
            'wacrm_conversation_id' => 'conv-'.random_int(1, 99999),
        ]);

        LeadEvent::create([
            'lead_id' => $lead->id,
            'account_id' => $this->account->id,
            'event_type' => 'message_in',
            'payload' => ['text' => $texto],
        ]);

        return $lead;
    }

    private function props(User $as, array $query = []): array
    {
        return $this->actingAs($as)
            ->get(route('inbox', $query))
            ->assertOk()
            ->viewData('page')['props'];
    }

    public function test_abre_la_primera_conversacion_sin_que_haya_que_elegirla(): void
    {
        $this->lead('Ana', $this->agente, 'Quiero información');

        $conv = $this->props($this->agente)['conversation'];

        $this->assertNotNull($conv, 'Entrar y encontrar el panel vacío obliga a un clic que no aporta.');
        $this->assertSame('Ana', $conv['lead']['contact']['name']);
        $this->assertSame('Quiero información', $conv['events'][0]['payload']['text']);
    }

    public function test_el_hilo_pedido_es_el_que_se_abre(): void
    {
        $this->lead('Ana', $this->agente);
        $otro = $this->lead('Beto', $this->agente);

        $conv = $this->props($this->agente, ['lead' => $otro->id])['conversation'];

        $this->assertSame('Beto', $conv['lead']['contact']['name']);
    }

    public function test_el_agente_no_puede_abrir_el_hilo_de_un_lead_ajeno(): void
    {
        $ajeno = $this->lead('Del admin', $this->owner, 'Conversación privada');
        $this->lead('Mio', $this->agente);

        $conv = $this->props($this->agente, ['lead' => $ajeno->id])['conversation'];

        $this->assertNull($conv, 'Ni un mensaje de una conversación que no es suya.');
    }

    public function test_el_admin_abre_cualquiera(): void
    {
        $delAgente = $this->lead('Del agente', $this->agente, 'Hola equipo');

        $conv = $this->props($this->owner, ['lead' => $delAgente->id])['conversation'];

        $this->assertSame('Hola equipo', $conv['events'][0]['payload']['text']);
    }

    public function test_el_hilo_trae_la_ventana_de_servicio(): void
    {
        $lead = $this->lead('Ana', $this->agente);

        $conv = $this->props($this->agente, ['lead' => $lead->id])['conversation'];

        $this->assertTrue($conv['service_window']['is_open']);
        $this->assertSame(24, $conv['service_window']['window_hours']);
    }

    public function test_un_id_inexistente_no_tumba_la_bandeja(): void
    {
        $this->lead('Ana', $this->agente);

        $props = $this->props($this->agente, ['lead' => '00000000-0000-4000-8000-000000000000']);

        $this->assertNull($props['conversation']);
        $this->assertCount(1, $props['items']);
    }
}
