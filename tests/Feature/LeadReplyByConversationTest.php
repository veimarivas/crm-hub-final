<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * F0 — el chat del lead responde EN la conversación, no al teléfono.
 *
 * Era el **bloqueante 2** del plan omnicanal: todo lo que salía de acá
 * direccionaba por teléfono, así que a un lead de Telegram no se le podía
 * contestar — no tiene número. El camino por teléfono se conserva para los
 * leads viejos que nunca guardaron su `wacrm_conversation_id`.
 */
class LeadReplyByConversationTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private PipelineStage $stage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        $pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas', 'is_default' => true]);
        $this->stage = PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Nuevo', 'stage_type' => 'open', 'position' => 0]);

        Integration::create([
            'account_id' => $this->account->id,
            'wacrm_url' => 'https://wacrm.test',
            'wacrm_api_key' => 'k',
            'is_active' => true,
        ]);

        Http::fake(['*/api/v1/messages' => Http::response(['id' => 'm1'], 201)]);
    }

    private function lead(?string $conversationId, ?string $phone): Lead
    {
        $contact = Contact::create([
            'account_id' => $this->account->id,
            'name' => 'Ana',
            'phone' => $phone,
        ]);

        return Lead::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $this->stage->pipeline_id,
            'stage_id' => $this->stage->id,
            'contact_id' => $contact->id,
            'title' => 'Ana',
            'source' => 'telegram',
            'wacrm_conversation_id' => $conversationId,
        ]);
    }

    public function test_un_lead_sin_telefono_ya_se_puede_contestar(): void
    {
        // El caso que antes era imposible: sin número, el botón de responder
        // fallaba con «El lead no tiene un contacto con teléfono».
        $lead = $this->lead('conv-tg-1', phone: null);

        $this->actingAs($this->owner)
            ->post(route('leads.whatsapp', $lead), ['text' => 'hola'])
            ->assertSessionHasNoErrors();

        Http::assertSent(fn ($request) => $request->data() === [
            'conversation_id' => 'conv-tg-1',
            'text' => 'hola',
        ]);
    }

    public function test_con_conversacion_gana_la_conversacion_aunque_haya_telefono(): void
    {
        $lead = $this->lead('conv-1', phone: '584125550001');

        $this->actingAs($this->owner)
            ->post(route('leads.whatsapp', $lead), ['text' => 'hola'])
            ->assertSessionHasNoErrors();

        // Direccionar por conversación es más preciso: el teléfono puede
        // corresponder a más de un hilo cuando hay varios canales.
        Http::assertSent(fn ($request) => array_key_exists('conversation_id', $request->data()));
    }

    public function test_un_lead_viejo_sin_conversacion_sigue_yendo_por_telefono(): void
    {
        $lead = $this->lead(null, phone: '584125550001');

        $this->actingAs($this->owner)
            ->post(route('leads.whatsapp', $lead), ['text' => 'hola'])
            ->assertSessionHasNoErrors();

        Http::assertSent(fn ($request) => $request->data() === [
            'to' => '584125550001',
            'text' => 'hola',
        ]);
    }

    public function test_sin_conversacion_ni_telefono_lo_dice(): void
    {
        $lead = $this->lead(null, phone: null);

        $this->actingAs($this->owner)
            ->from(route('leads.show', $lead))
            ->post(route('leads.whatsapp', $lead), ['text' => 'hola'])
            ->assertSessionHasErrors('text');

        Http::assertNothingSent();
    }
}
