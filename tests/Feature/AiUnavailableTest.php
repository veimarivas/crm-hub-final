<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AppNotification;
use App\Models\Contact;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Wacrm\EventProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El wacrm avisa que la IA no va a contestar (falló o agotó su tope).
 *
 * Al cliente no se le manda nada — el wacrm dejó de hacerlo a propósito —
 * así que esta notificación es el ÚNICO aviso de que la conversación quedó
 * esperando a un humano. Si se pierde, el contacto queda sin respuesta.
 */
class AiUnavailableTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private User $agente;

    private Integration $integration;

    private Pipeline $pipeline;

    private PipelineStage $stage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Administrador', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        $this->agente = User::create([
            'name' => 'Daniel', 'email' => 'daniel@test.com', 'password' => bcrypt('password'),
            'account_id' => $this->account->id, 'account_role' => User::ROLE_AGENT,
        ]);

        $this->integration = Integration::create([
            'account_id' => $this->account->id,
            'wacrm_url' => 'http://wacrm.test',
            'wacrm_api_key' => 'k',
            'webhook_secret' => 'whsec_s',
            'is_active' => true,
        ]);

        $this->pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas', 'is_default' => true]);
        $this->stage = PipelineStage::create(['pipeline_id' => $this->pipeline->id, 'name' => 'Nuevo', 'stage_type' => 'open', 'position' => 0]);
    }

    private function makeLead(?User $responsible): Lead
    {
        $contact = Contact::create(['account_id' => $this->account->id, 'name' => 'Ana', 'phone' => '59170000000']);

        return Lead::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stage->id,
            'contact_id' => $contact->id,
            'title' => 'WhatsApp: Ana',
            'source' => 'manual',
            'wacrm_conversation_id' => 'conv-1',
            'responsible_user_id' => $responsible?->id,
        ]);
    }

    private function process(array $data): void
    {
        app(EventProcessor::class)->process($this->integration, 'ai.unavailable', $data);
    }

    public function test_avisa_al_responsable_del_lead(): void
    {
        $lead = $this->makeLead($this->agente);

        $this->process([
            'conversation_id' => 'conv-1',
            'reason' => 'failed',
            'title' => 'La IA no pudo responder',
            'body' => 'Contestale vos.',
        ]);

        $notification = AppNotification::where('user_id', $this->agente->id)->sole();

        $this->assertSame('ai_unavailable', $notification->type);
        $this->assertSame($lead->id, $notification->lead_id);
        $this->assertSame('La IA no pudo responder', $notification->title);
    }

    public function test_sin_responsable_el_aviso_cae_en_el_owner(): void
    {
        $this->makeLead(null);

        $this->process(['conversation_id' => 'conv-1', 'reason' => 'failed', 'title' => 'La IA no pudo responder']);

        $this->assertSame(1, AppNotification::where('user_id', $this->owner->id)->count());
    }

    public function test_el_tope_agotado_usa_su_propio_tipo(): void
    {
        $this->makeLead($this->agente);

        $this->process([
            'conversation_id' => 'conv-1',
            'reason' => 'limit_reached',
            'title' => 'La IA llegó a su tope en esta conversación',
        ]);

        $this->assertSame('ai_limit_reached', AppNotification::where('user_id', $this->agente->id)->sole()->type);
    }

    public function test_una_conversacion_que_no_es_de_ningun_lead_se_ignora(): void
    {
        $this->makeLead($this->agente);

        $this->process(['conversation_id' => 'otra-conv', 'reason' => 'failed', 'title' => 'x']);

        $this->assertSame(0, AppNotification::count());
    }
}
