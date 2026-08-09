<?php

namespace Tests\Feature;

use App\Jobs\SendAiFeedbackJob;
use App\Models\Account;
use App\Models\AiFeedback;
use App\Models\Contact;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * T5 — ciclo de mejora de la IA (lado Komo).
 *
 * Lo que protege este archivo: que la corrección **se guarde acá primero** y se
 * despache después. Si el envío fuera sincrónico y el wacrm estuviera caído, el
 * agente se tomaría el trabajo de escribir la corrección y se perdería.
 */
class AiFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $agent;

    private Account $account;

    private Lead $lead;

    private LeadEvent $aiMessage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Owner', 'email' => 'o@test.com', 'password' => bcrypt('secret')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => 'owner']);
        $this->owner->refresh();

        $this->agent = User::create([
            'name' => 'Agente', 'email' => 'a@test.com', 'password' => bcrypt('secret'),
            'account_id' => $this->account->id, 'account_role' => 'agent',
        ]);

        $pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas', 'is_default' => true]);
        $stage = PipelineStage::create([
            'pipeline_id' => $pipeline->id, 'name' => 'Nuevo', 'stage_type' => 'open',
            'position' => 0, 'color' => '#0ea5e9',
        ]);

        $contact = Contact::create([
            'account_id' => $this->account->id, 'name' => 'Ana', 'phone' => '+59171234567',
        ]);

        $this->lead = Lead::create([
            'account_id' => $this->account->id, 'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id,
            'contact_id' => $contact->id, 'title' => 'Ana', 'value' => 100, 'source' => 'whatsapp',
            'responsible_user_id' => $this->owner->id, 'wacrm_conversation_id' => \Illuminate\Support\Str::uuid(),
        ]);

        $this->lead->events()->create([
            'account_id' => $this->account->id, 'event_type' => 'message_in',
            'payload' => ['text' => 'Cuanto cuesta el diplomado?'],
        ]);

        $this->aiMessage = $this->lead->events()->create([
            'account_id' => $this->account->id, 'event_type' => 'message_out',
            'payload' => ['text' => 'El curso cuesta 500 Bs.', 'sender' => 'bot'],
        ]);
    }

    private function report(array $payload = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->post(route('leads.ai-feedback', $this->lead), [
            'lead_event_id' => $this->aiMessage->id,
            'rating' => 'down',
            'correction' => 'El diplomado cuesta 3.500 Bs en 5 cuotas.',
            ...$payload,
        ]);
    }

    // ---- Captura ----

    public function test_se_guarda_localmente_y_se_despacha_en_cola(): void
    {
        Queue::fake();

        $this->report()->assertRedirect();

        $feedback = AiFeedback::first();
        $this->assertSame('down', $feedback->rating);
        $this->assertNull($feedback->synced_at);

        // Guardar primero y despachar despues es lo que hace que la correccion
        // no se pierda si el wacrm esta caido.
        Queue::assertPushed(SendAiFeedbackJob::class);
    }

    public function test_un_voto_por_mensaje_y_por_usuario(): void
    {
        Queue::fake();

        $this->report();
        $this->report(['rating' => 'up', 'correction' => null]);

        $this->assertSame(1, AiFeedback::count());
        $this->assertSame('up', AiFeedback::first()->rating);
    }

    public function test_cambiar_el_voto_lo_vuelve_a_poner_en_cola(): void
    {
        Queue::fake();

        $this->report();
        AiFeedback::first()->forceFill(['synced_at' => now()])->save();

        $this->report(['rating' => 'up', 'correction' => null]);

        // Allá se reabre la revisión, así que acá no puede quedar marcado como
        // ya enviado.
        $this->assertNull(AiFeedback::first()->synced_at);
    }

    public function test_dos_agentes_votan_por_separado(): void
    {
        Queue::fake();

        $this->report([], $this->owner);
        $this->report(['rating' => 'up', 'correction' => null], $this->agent);

        $this->assertSame(2, AiFeedback::count());
    }

    public function test_no_se_puede_corregir_un_mensaje_humano(): void
    {
        Queue::fake();

        $humano = $this->lead->events()->create([
            'account_id' => $this->account->id, 'event_type' => 'message_out',
            'payload' => ['text' => 'Hola, te paso el precio', 'sender' => 'agent'],
        ]);

        $this->report(['lead_event_id' => $humano->id])->assertStatus(422);
        $this->assertSame(0, AiFeedback::count());
    }

    public function test_no_se_puede_reportar_un_lead_de_otra_cuenta(): void
    {
        Queue::fake();

        $otro = User::create(['name' => 'Otro', 'email' => 'x@test.com', 'password' => bcrypt('secret')]);
        $otraCuenta = Account::create(['name' => 'Otra', 'owner_user_id' => $otro->id]);
        $otro->update(['account_id' => $otraCuenta->id, 'account_role' => 'owner']);

        $this->actingAs($otro->refresh())->post(route('leads.ai-feedback', $this->lead), [
            'lead_event_id' => $this->aiMessage->id, 'rating' => 'down',
        ])->assertForbidden();
    }

    public function test_el_voto_propio_viaja_con_la_ficha(): void
    {
        Queue::fake();
        $this->report();

        $props = $this->actingAs($this->owner)->get(route('leads.show', $this->lead))
            ->viewData('page')['props'];

        $this->assertSame('down', $props['aiFeedback'][$this->aiMessage->id]['rating']);
    }

    public function test_el_voto_de_otro_agente_no_se_muestra_como_propio(): void
    {
        Queue::fake();
        $this->report([], $this->agent);

        $props = $this->actingAs($this->owner)->get(route('leads.show', $this->lead))
            ->viewData('page')['props'];

        $this->assertCount(0, $props['aiFeedback']);
    }

    // ---- Envío al wacrm ----

    public function test_el_job_manda_la_correccion_con_la_pregunta_que_la_origino(): void
    {
        Integration::create([
            'account_id' => $this->account->id,
            'wacrm_url' => 'https://wacrm.test',
            'wacrm_api_key' => 'wacrm_live_x',
            'is_active' => true,
        ]);

        Http::fake(['*/api/v1/ai/feedback' => Http::response(['id' => 'x', 'status' => 'pending'], 201)]);

        $this->actingAs($this->owner)->post(route('leads.ai-feedback', $this->lead), [
            'lead_event_id' => $this->aiMessage->id,
            'rating' => 'down',
            'correction' => 'El diplomado cuesta 3.500 Bs.',
        ]);

        Http::assertSent(function ($request) {
            $body = $request->data();

            // Sin la pregunta, la correccion no se puede juzgar: «el precio es
            // 3.500» no dice nada si no se sabe de que se hablaba.
            return $body['external_ref'] === $this->aiMessage->id
                && $body['question'] === 'Cuanto cuesta el diplomado?'
                && $body['ai_text'] === 'El curso cuesta 500 Bs.'
                && $body['reporter'] === 'Owner';
        });

        $this->assertNotNull(AiFeedback::first()->synced_at);
    }

    public function test_sin_integracion_activa_el_feedback_queda_sin_sincronizar(): void
    {
        Http::fake();

        $this->report();

        // No se pierde: queda guardado y sin marcar, listo para reintentar.
        $this->assertSame(1, AiFeedback::count());
        $this->assertNull(AiFeedback::first()->synced_at);
        Http::assertNothingSent();
    }

    public function test_el_job_no_reenvia_lo_ya_sincronizado(): void
    {
        Integration::create([
            'account_id' => $this->account->id,
            'wacrm_url' => 'https://wacrm.test',
            'wacrm_api_key' => 'wacrm_live_x',
            'is_active' => true,
        ]);
        Http::fake();

        $feedback = AiFeedback::create([
            'account_id' => $this->account->id,
            'lead_id' => $this->lead->id,
            'lead_event_id' => $this->aiMessage->id,
            'user_id' => $this->owner->id,
            'rating' => 'down',
            'synced_at' => now(),
        ]);

        (new SendAiFeedbackJob($feedback->id))->handle();

        Http::assertNothingSent();
    }
}
