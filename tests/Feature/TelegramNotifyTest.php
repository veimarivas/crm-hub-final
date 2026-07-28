<?php

namespace Tests\Feature;

use App\Jobs\NotifyAssignmentOnTelegramJob;
use App\Jobs\NotifyInboundOnTelegramJob;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Aviso por Telegram al responsable cuando le escribe un contacto.
 *
 * Existe porque el equipo no vive con el CRM abierto. Lo que se fija acá:
 * a quién le llega, que no se convierta en spam, y que un enlace de
 * vinculación no sirva para atarse a la cuenta de otro.
 */
class TelegramNotifyTest extends TestCase
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

        config()->set('services.telegram.bot_token', '123:ABC');
        config()->set('services.telegram.bot_username', 'crm_bot');
        config()->set('services.telegram.webhook_secret', 'secreto-largo');

        $this->owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        $this->agente = User::create([
            'name' => 'Daniel', 'email' => 'daniel@test.com', 'password' => bcrypt('password'),
            'account_id' => $this->account->id, 'account_role' => User::ROLE_AGENT,
            'telegram_chat_id' => '55501',
        ]);

        $this->pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas', 'is_default' => true]);
        $this->stage = PipelineStage::create(['pipeline_id' => $this->pipeline->id, 'name' => 'Nuevo', 'stage_type' => 'open', 'position' => 0]);

    }

    /**
     * Los stubs de `Http::fake` se acumulan y gana el primero que matchea, así
     * que no se puede registrar uno en setUp y sobreescribirlo en el test:
     * cada test declara el suyo.
     */
    private function fakeTelegram(int $status = 200): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(
            $status === 200 ? ['ok' => true] : ['description' => 'bot was blocked'],
            $status,
        )]);
    }

    private function makeLead(?User $responsible): Lead
    {
        $contact = Contact::create([
            'account_id' => $this->account->id,
            'name' => 'Ana Pérez',
            'phone' => '5917'.random_int(1000000, 9999999),
        ]);

        return Lead::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stage->id,
            'contact_id' => $contact->id,
            'title' => 'WhatsApp: Ana',
            'source' => 'whatsapp',
            'responsible_user_id' => $responsible?->id,
        ]);
    }

    public function test_le_llega_al_responsable_con_el_detalle(): void
    {
        $this->fakeTelegram();
        $lead = $this->makeLead($this->agente);

        (new NotifyInboundOnTelegramJob($lead->id, 'Quiero información de la maestría'))->handle();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sendMessage')
                && $request['chat_id'] === '55501'
                && str_contains($request['text'], 'Ana Pérez')
                && str_contains($request['text'], 'Quiero información de la maestría');
        });
    }

    public function test_sin_responsable_le_llega_al_owner(): void
    {
        $this->fakeTelegram();
        $this->owner->update(['telegram_chat_id' => '99900']);
        $lead = $this->makeLead(null);

        (new NotifyInboundOnTelegramJob($lead->id, 'Hola'))->handle();

        Http::assertSent(fn ($r) => $r['chat_id'] === '99900');
    }

    public function test_no_manda_nada_si_el_responsable_no_vinculo_telegram(): void
    {
        $this->fakeTelegram();
        $this->agente->update(['telegram_chat_id' => null]);
        $lead = $this->makeLead($this->agente);

        (new NotifyInboundOnTelegramJob($lead->id, 'Hola'))->handle();

        Http::assertNothingSent();
    }

    public function test_no_repite_el_aviso_en_una_rafaga_de_mensajes(): void
    {
        $this->fakeTelegram();
        $lead = $this->makeLead($this->agente);

        // Un contacto suele mandar varios seguidos: sin freno serían tres
        // notificaciones en el teléfono y el bot terminaría silenciado.
        (new NotifyInboundOnTelegramJob($lead->id, 'Hola'))->handle();
        (new NotifyInboundOnTelegramJob($lead->id, 'Estás?'))->handle();
        (new NotifyInboundOnTelegramJob($lead->id, 'Necesito info'))->handle();

        Http::assertSentCount(1);
    }

    public function test_si_el_usuario_bloqueo_el_bot_se_desvincula(): void
    {
        $this->fakeTelegram(403);
        $lead = $this->makeLead($this->agente);

        (new NotifyInboundOnTelegramJob($lead->id, 'Hola'))->handle();

        // Si no, se reintentaría en cada mensaje para siempre.
        $this->assertNull($this->agente->fresh()->telegram_chat_id);
    }

    public function test_sin_bot_configurado_no_hace_nada(): void
    {
        $this->fakeTelegram();
        config()->set('services.telegram.bot_token', null);
        $lead = $this->makeLead($this->agente);

        (new NotifyInboundOnTelegramJob($lead->id, 'Hola'))->handle();

        Http::assertNothingSent();
    }

    // ---- Aviso al asignar ----

    public function test_el_reparto_automatico_avisa_que_le_toco(): void
    {
        $this->fakeTelegram();
        $lead = $this->makeLead($this->agente);

        (new NotifyAssignmentOnTelegramJob($lead->id, $this->agente->id))->handle();

        Http::assertSent(function ($request) {
            return str_contains($request['text'], 'Nuevo contacto asignado')
                && str_contains($request['text'], 'reparto autom')
                && str_contains($request['text'], 'Ana Pérez');
        });
    }

    public function test_la_asignacion_manual_dice_quien_la_hizo(): void
    {
        $this->fakeTelegram();
        $lead = $this->makeLead($this->agente);

        (new NotifyAssignmentOnTelegramJob($lead->id, $this->agente->id, $this->owner->id))->handle();

        // Saber que hay una intención detrás cambia la urgencia con que se
        // atiende: no es lo mismo que te lo derive el admin.
        Http::assertSent(fn ($r) => str_contains($r['text'], 'Te lo asignó')
            && str_contains($r['text'], 'Admin'));
    }

    public function test_no_avisa_al_que_se_asigna_a_si_mismo(): void
    {
        $this->fakeTelegram();
        $lead = $this->makeLead($this->agente);

        (new NotifyAssignmentOnTelegramJob($lead->id, $this->agente->id, $this->agente->id))->handle();

        Http::assertNothingSent();
    }

    // ---- Vinculación ----

    public function test_el_start_con_token_vincula_la_cuenta(): void
    {
        $this->fakeTelegram();
        $this->agente->update(['telegram_chat_id' => null, 'telegram_link_token' => 'tok-123']);

        $this->postJson('/webhooks/telegram/secreto-largo', [
            'message' => ['chat' => ['id' => 77712], 'text' => '/start tok-123'],
        ])->assertOk();

        $this->agente->refresh();

        $this->assertSame('77712', $this->agente->telegram_chat_id);
        // De un solo uso: si no, el enlace filtrado serviría para siempre.
        $this->assertNull($this->agente->telegram_link_token);
    }

    public function test_un_token_invalido_no_vincula_a_nadie(): void
    {
        $this->fakeTelegram();
        $this->postJson('/webhooks/telegram/secreto-largo', [
            'message' => ['chat' => ['id' => 77712], 'text' => '/start no-existe'],
        ])->assertOk();

        $this->assertSame(0, User::where('telegram_chat_id', '77712')->count());
    }

    public function test_el_webhook_exige_el_secreto_de_la_url(): void
    {
        $this->agente->update(['telegram_link_token' => 'tok-123']);

        $this->postJson('/webhooks/telegram/secreto-equivocado', [
            'message' => ['chat' => ['id' => 77712], 'text' => '/start tok-123'],
        ])->assertNotFound();

        $this->assertSame('55501', $this->agente->fresh()->telegram_chat_id);
    }

    public function test_el_usuario_genera_su_enlace_y_puede_desvincular(): void
    {
        $this->actingAs($this->agente)
            ->post(route('telegram.link'))
            ->assertRedirect()
            ->assertSessionHas('telegram_link', fn ($url) => str_contains($url, 't.me/crm_bot?start='));

        $this->assertNotNull($this->agente->fresh()->telegram_link_token);

        $this->actingAs($this->agente)->delete(route('telegram.unlink'))->assertRedirect();

        $this->assertNull($this->agente->fresh()->telegram_chat_id);
    }
}
