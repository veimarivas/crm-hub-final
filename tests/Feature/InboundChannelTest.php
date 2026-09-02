<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Channels\ChannelRules;
use App\Services\WhatsApp\ServiceWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F0b/T0.4 — Komo deja de descartar los eventos sin teléfono.
 *
 * **Era el bloqueante del E2E de Telegram, y fallaba de la peor manera
 * posible.** `EventProcessor::syncContact()` arrancaba con un
 * `if (! $normalized) return null;`, así que un mensaje sin teléfono se tiraba
 * **en silencio**: sin contacto, sin lead, sin evento. El wacrm lo procesaba
 * bien y acá desaparecía sin dejar rastro — no había error que investigar.
 */
class InboundChannelTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Integration $integration;

    private Pipeline $pipeline;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        $this->pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas', 'is_default' => true]);
        PipelineStage::create(['pipeline_id' => $this->pipeline->id, 'name' => 'Nuevo', 'stage_type' => 'open', 'position' => 0]);

        $this->integration = Integration::create([
            'account_id' => $this->account->id,
            'wacrm_url' => 'https://wacrm.test',
            'wacrm_api_key' => 'k',
            'webhook_secret' => 'whsec_s',
            'is_active' => true,
        ]);
    }

    /** Manda un `message.received` firmado, como lo haría el wacrm. */
    private function entrante(array $data)
    {
        $body = json_encode(['event' => 'message.received', 'data' => $data]);

        return $this->call('POST', "/webhooks/wacrm/{$this->account->id}", [], [], [], [
            'HTTP_X-Webhook-Signature' => 'sha256='.hash_hmac('sha256', $body, 'whsec_s'),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    private function telegram(array $extra = []): array
    {
        return array_merge([
            'channel' => 'telegram',
            'conversation_id' => 'conv-tg-1',
            'contact' => [
                'id' => 'wacrm-c1',
                'name' => 'Ana',
                'channel_external_id' => '99887766',
                // Sin teléfono: es el caso que antes se descartaba.
            ],
            'message' => ['text' => 'hola por telegram', 'type' => 'text'],
        ], $extra);
    }

    public function test_un_mensaje_sin_telefono_crea_contacto_y_lead(): void
    {
        $this->entrante($this->telegram())->assertOk();

        $contact = Contact::firstOrFail();
        $this->assertSame('Ana', $contact->name);
        $this->assertNull($contact->phone);

        $identity = ContactIdentity::firstOrFail();
        $this->assertSame('telegram', $identity->channel);
        $this->assertSame('99887766', $identity->external_id);

        $lead = Lead::firstOrFail();
        $this->assertSame($contact->id, $lead->contact_id);
        // El rótulo dice el canal real: un lead de Telegram como «WhatsApp:»
        // haría que los reportes por fuente mientan desde el primer día.
        $this->assertSame('Telegram: Ana', $lead->title);
        $this->assertSame('telegram', $lead->source);
        $this->assertSame('conv-tg-1', $lead->wacrm_conversation_id);
    }

    public function test_el_canal_queda_en_el_evento(): void
    {
        $this->entrante($this->telegram())->assertOk();

        $evento = LeadEvent::where('event_type', 'message_in')->firstOrFail();

        $this->assertSame('telegram', $evento->payload['channel']);
        // El id de conversación viaja también: es lo que va a permitir
        // responder desde el chat del lead sin direccionar por teléfono.
        $this->assertSame('conv-tg-1', $evento->payload['conversation_id']);
    }

    public function test_el_segundo_mensaje_reusa_contacto_y_lead(): void
    {
        $this->entrante($this->telegram())->assertOk();
        $this->entrante($this->telegram(['message' => ['text' => 'sigo acá', 'type' => 'text']]))->assertOk();

        $this->assertSame(1, Contact::count());
        $this->assertSame(1, Lead::count());
        $this->assertSame(1, ContactIdentity::count());
        $this->assertSame(2, LeadEvent::where('event_type', 'message_in')->count());
    }

    public function test_un_canal_desconocido_no_rompe_y_se_guarda_crudo(): void
    {
        // Los canales nacen en el wacrm y los deploys no son simultáneos: este
        // proyecto va a recibir eventos de canales que todavía no conoce.
        $this->entrante($this->telegram(['channel' => 'canal_del_futuro']))->assertOk();

        $lead = Lead::firstOrFail();
        $this->assertSame('canal_del_futuro', $lead->source);
        $this->assertSame(
            'canal_del_futuro',
            LeadEvent::where('event_type', 'message_in')->firstOrFail()->payload['channel'],
        );
    }

    public function test_un_evento_viejo_sin_canal_sigue_siendo_de_whatsapp(): void
    {
        // Exactamente el payload de antes de F0: un wacrm sin desplegar.
        $this->entrante([
            'conversation_id' => 'conv-wa-1',
            'contact' => ['id' => 'wacrm-c9', 'name' => 'Beto', 'phone' => '584125550001'],
            'message' => ['text' => 'hola', 'type' => 'text'],
        ])->assertOk();

        $lead = Lead::firstOrFail();
        $this->assertSame('whatsapp', $lead->source);
        $this->assertSame('Whatsapp: Beto', $lead->title);

        // Y le deja la identidad derivada del teléfono, sin que el wacrm la
        // mande: en WhatsApp el identificador del canal ES el teléfono.
        $identity = ContactIdentity::firstOrFail();
        $this->assertSame('whatsapp', $identity->channel);
        $this->assertSame('584125550001', $identity->external_id);
    }

    public function test_un_contacto_que_ya_existia_no_se_duplica(): void
    {
        $previo = Contact::create([
            'account_id' => $this->account->id,
            'name' => 'Beto',
            'phone' => '584125550001',
        ]);

        // Sin identidad: es el estado de un contacto anterior a F0 cuya fila no
        // alcanzó el backfill (alta manual, importación, formulario web).
        ContactIdentity::where('contact_id', $previo->id)->delete();

        $this->entrante([
            'channel' => 'whatsapp',
            'conversation_id' => 'conv-wa-2',
            'contact' => ['id' => 'wacrm-c9', 'name' => 'Beto', 'phone' => '584125550001'],
            'message' => ['text' => 'hola', 'type' => 'text'],
        ])->assertOk();

        $this->assertSame(1, Contact::count());
        $this->assertSame($previo->id, Lead::firstOrFail()->contact_id);
        // Y de paso le deja la identidad que le faltaba.
        $this->assertSame(1, ContactIdentity::where('contact_id', $previo->id)->count());
    }

    public function test_sin_ningun_identificador_si_se_descarta(): void
    {
        // El único caso que se sigue descartando, y ya no es «no trae
        // teléfono» sino «no trae NINGÚN identificador».
        $this->entrante([
            'channel' => 'telegram',
            'contact' => ['name' => 'Fantasma'],
            'message' => ['text' => 'hola', 'type' => 'text'],
        ])->assertOk();

        $this->assertSame(0, Contact::count());
        $this->assertSame(0, Lead::count());
    }

    public function test_la_ventana_de_un_lead_de_telegram_no_tiene_limite(): void
    {
        $this->entrante($this->telegram())->assertOk();

        $lead = Lead::firstOrFail();

        // El entrante se envejece: en WhatsApp esto cerraría la ventana.
        LeadEvent::where('lead_id', $lead->id)->where('event_type', 'message_in')
            ->update(['created_at' => now()->subDays(5)]);

        $window = app(ServiceWindow::class)->forLead($lead->fresh());

        $this->assertTrue($window['is_open'], 'Telegram no tiene ventana que vencer.');
        $this->assertNull($window['window_hours']);
        $this->assertSame('telegram', $window['source']);
    }

    public function test_la_ventana_de_un_lead_de_whatsapp_no_cambio(): void
    {
        $this->entrante([
            'channel' => ChannelRules::WHATSAPP,
            'conversation_id' => 'conv-wa-3',
            'contact' => ['id' => 'c', 'name' => 'Beto', 'phone' => '584125550001'],
            'message' => ['text' => 'hola', 'type' => 'text'],
        ])->assertOk();

        $lead = Lead::firstOrFail();

        LeadEvent::where('lead_id', $lead->id)->where('event_type', 'message_in')
            ->update(['created_at' => now()->subDays(5)]);

        $window = app(ServiceWindow::class)->forLead($lead->fresh());

        $this->assertFalse($window['is_open'], 'En WhatsApp la ventana de 24 h sí vence.');
        $this->assertSame(24, $window['window_hours']);
    }
}
