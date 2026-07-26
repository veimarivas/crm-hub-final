<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\WhatsApp\ServiceWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ventana de servicio de WhatsApp calculada desde `lead_events`.
 *
 * De esto depende que un envío salga gratis o se facture, así que las reglas
 * quedan fijadas acá. Es el mismo cálculo que el `ServiceWindow` del wacrm:
 * si cambia una regla hay que tocar los dos (y sus tests).
 */
class ServiceWindowTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Pipeline $pipeline;

    private PipelineStage $stage;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        $this->pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas', 'is_default' => true]);
        $this->stage = PipelineStage::create(['pipeline_id' => $this->pipeline->id, 'name' => 'Nuevo', 'stage_type' => 'open', 'position' => 0]);
    }

    private function makeLead(?string $sourceRef = null): Lead
    {
        $contact = Contact::create(['account_id' => $this->account->id, 'name' => 'Ana', 'phone' => '5917'.random_int(1000000, 9999999)]);

        return Lead::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stage->id,
            'contact_id' => $contact->id,
            'title' => 'WhatsApp: Ana',
            'source' => 'whatsapp',
            'source_ref' => $sourceRef,
            'wacrm_conversation_id' => 'conv-'.random_int(1, 99999),
        ]);
    }

    private function inbound(Lead $lead, string $at, bool $fromAd = false): void
    {
        LeadEvent::create([
            'lead_id' => $lead->id,
            'account_id' => $this->account->id,
            'event_type' => 'message_in',
            'payload' => ['text' => 'Hola', 'ad_referral' => $fromAd],
        ])->forceFill(['created_at' => $at])->save();
    }

    private function window(Lead $lead): array
    {
        return app(ServiceWindow::class)->forLead($lead->fresh());
    }

    public function test_un_mensaje_del_cliente_abre_24h(): void
    {
        $lead = $this->makeLead();
        $this->inbound($lead, now()->subHours(2)->toDateTimeString());

        $w = $this->window($lead);

        $this->assertTrue($w['is_open']);
        $this->assertSame('whatsapp', $w['source']);
        $this->assertSame(24, $w['window_hours']);
        $this->assertEqualsWithDelta(22 * 3600, $w['remaining_seconds'], 60);
    }

    public function test_pasadas_las_24h_la_ventana_se_cierra(): void
    {
        $lead = $this->makeLead();
        $this->inbound($lead, now()->subHours(25)->toDateTimeString());

        $w = $this->window($lead);

        $this->assertFalse($w['is_open']);
        $this->assertSame(0, $w['remaining_seconds']);
    }

    public function test_cada_mensaje_nuevo_renueva_la_ventana(): void
    {
        $lead = $this->makeLead();
        $this->inbound($lead, now()->subHours(23)->toDateTimeString());
        $this->inbound($lead, now()->subMinutes(10)->toDateTimeString());

        $this->assertEqualsWithDelta(23.83 * 3600, $this->window($lead)['remaining_seconds'], 120);
    }

    public function test_el_anuncio_de_facebook_da_72h(): void
    {
        $lead = $this->makeLead();
        $this->inbound($lead, now()->subHours(2)->toDateTimeString(), fromAd: true);

        $w = $this->window($lead);

        $this->assertSame('meta_ad', $w['source']);
        $this->assertSame(72, $w['window_hours']);
        $this->assertEqualsWithDelta(70 * 3600, $w['remaining_seconds'], 60);
    }

    public function test_las_72h_siguen_cubriendo_aunque_el_ultimo_mensaje_sea_viejo(): void
    {
        $lead = $this->makeLead();
        // Tocó el anuncio hace 30 h; su último mensaje fue hace 26 h. Las 24 h
        // vencieron, las 72 h del anuncio no.
        $this->inbound($lead, now()->subHours(30)->toDateTimeString(), fromAd: true);
        $this->inbound($lead, now()->subHours(26)->toDateTimeString());

        $w = $this->window($lead);

        $this->assertTrue($w['is_open'], 'El free entry point de 72h sigue vigente.');
        $this->assertSame('meta_ad', $w['source']);
        $this->assertEqualsWithDelta(42 * 3600, $w['remaining_seconds'], 60);
    }

    public function test_un_mensaje_reciente_gana_a_un_anuncio_por_vencer(): void
    {
        $lead = $this->makeLead();
        $this->inbound($lead, now()->subHours(71)->toDateTimeString(), fromAd: true);
        $this->inbound($lead, now()->subMinutes(10)->toDateTimeString());

        $w = $this->window($lead);

        $this->assertSame('whatsapp', $w['source']);
        $this->assertSame(24, $w['window_hours']);
    }

    public function test_los_leads_viejos_sin_la_marca_usan_el_anuncio_del_lead(): void
    {
        // Antes de guardar `ad_referral` por evento, lo único que quedaba era
        // el source_ref del lead. El fallback usa su creación como el clic.
        $lead = $this->makeLead(sourceRef: 'ad-123');
        $lead->forceFill(['created_at' => now()->subHours(10)])->save();
        $this->inbound($lead, now()->subHours(10)->toDateTimeString()); // sin marca

        $w = $this->window($lead);

        $this->assertSame('meta_ad', $w['source']);
        $this->assertSame(72, $w['window_hours']);
        $this->assertEqualsWithDelta(62 * 3600, $w['remaining_seconds'], 120);
    }

    public function test_sin_mensajes_del_cliente_no_hay_ventana(): void
    {
        $lead = $this->makeLead();

        $w = $this->window($lead);

        $this->assertFalse($w['is_open']);
        $this->assertSame('none', $w['source']);
        $this->assertNull($w['expires_at']);
    }

    public function test_avisa_cuando_queda_poco(): void
    {
        $lead = $this->makeLead();
        $this->inbound($lead, now()->subHours(21)->toDateTimeString());

        $this->assertTrue($this->window($lead)['is_expiring']);
    }

    public function test_el_inbox_expone_la_ventana_de_cada_conversacion(): void
    {
        $owner = User::where('account_id', $this->account->id)->first();
        $lead = $this->makeLead();
        $lead->update(['responsible_user_id' => $owner->id]);
        $this->inbound($lead, now()->subHours(1)->toDateTimeString(), fromAd: true);

        $items = $this->actingAs($owner)->get(route('inbox', ['filter' => 'all']))
            ->viewData('page')['props']['items'];

        $this->assertSame('meta_ad', $items[0]['service_window']['source']);
        $this->assertSame(72, $items[0]['service_window']['window_hours']);
    }
}
