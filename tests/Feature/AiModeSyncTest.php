<?php

namespace Tests\Feature;

use App\Models\Account;
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
 * El toggle IA del lead sigue al del wacrm.
 *
 * Sin esto, el wacrm apagaba la IA (por ejemplo cuando un agente contesta a
 * mano, que es su regla) y acá la ficha seguía mostrando «✨ IA activa»: el
 * toggle decía encendido y no contestaba nadie.
 */
class AiModeSyncTest extends TestCase
{
    use RefreshDatabase;

    private Integration $integration;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::create(['name' => 'Admin', 'email' => 'a@test.com', 'password' => bcrypt('x')]);
        $account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $account->id, 'account_role' => User::ROLE_OWNER]);

        $this->integration = Integration::create([
            'account_id' => $account->id,
            'wacrm_url' => 'https://wacrm.test',
            'wacrm_api_key' => 'k',
        ]);

        $pipeline = Pipeline::create(['account_id' => $account->id, 'name' => 'Ventas', 'is_default' => true]);
        $stage = PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Nuevo', 'stage_type' => 'open', 'position' => 0]);

        $contact = Contact::create(['account_id' => $account->id, 'name' => 'Ana', 'phone' => '59171234567']);

        $this->lead = Lead::create([
            'account_id' => $account->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'contact_id' => $contact->id,
            'title' => 'Ana',
            'source' => 'whatsapp',
            'wacrm_conversation_id' => 'conv-1',
            'ai_enabled' => true,
        ]);
    }

    private function procesar(array $data): void
    {
        app(EventProcessor::class)->process($this->integration, 'ai.mode_changed', $data);
        $this->lead->refresh();
    }

    public function test_el_apagado_del_wacrm_apaga_el_toggle_del_lead(): void
    {
        $this->procesar([
            'conversation_id' => 'conv-1',
            'ai_enabled' => false,
            'reason' => 'Se apagó sola cuando Daniel respondió manualmente',
        ]);

        $this->assertFalse($this->lead->ai_enabled);
    }

    public function test_el_encendido_tambien_se_espeja(): void
    {
        $this->lead->update(['ai_enabled' => false]);

        $this->procesar(['conversation_id' => 'conv-1', 'ai_enabled' => true]);

        $this->assertTrue($this->lead->ai_enabled);
    }

    public function test_una_conversacion_desconocida_no_rompe_nada(): void
    {
        $this->procesar(['conversation_id' => 'conv-inexistente', 'ai_enabled' => false]);

        $this->assertTrue($this->lead->ai_enabled, 'El lead de otra conversación no se toca.');
    }
}
