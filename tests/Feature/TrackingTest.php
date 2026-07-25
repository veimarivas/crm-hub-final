<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApiKey;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Models\WebForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Atribución first-touch multi-canal: UTMs + click IDs.
 * Cubre los 4 puntos de captura (API, web form, referral de WhatsApp,
 * snippet /track.js) + el reporte agregado por utm_source.
 */
class TrackingTest extends TestCase
{
    use RefreshDatabase;

    private function makeAccount(): array
    {
        $user = User::create(['name' => 'Vendedor', 'email' => 'v@test.com', 'password' => bcrypt('password')]);
        $account = Account::create(['name' => 'Empresa', 'owner_user_id' => $user->id]);
        $user->update(['account_id' => $account->id, 'account_role' => 'owner']);

        $pipeline = Pipeline::create(['account_id' => $account->id, 'name' => 'Ventas', 'is_default' => true]);
        $stages = collect([
            ['name' => 'Nuevo', 'stage_type' => 'open'],
            ['name' => 'Ganado', 'stage_type' => 'won'],
            ['name' => 'Perdido', 'stage_type' => 'lost'],
        ])->map(fn ($s, $i) => PipelineStage::create(['pipeline_id' => $pipeline->id, 'position' => $i, ...$s]));

        return [$user->fresh(), $account, $pipeline, $stages];
    }

    public function test_api_post_leads_acepta_bloque_tracking_y_registra_first_touch(): void
    {
        [$user, $account] = $this->makeAccount();
        [, $token] = ApiKey::issue($account->id, $user->id, 'meta_ads', ['leads:write']);

        $this->withToken($token)->postJson('/api/v1/leads', [
            'name' => 'Ana',
            'phone' => '+58 412 555 0001',
            'source' => 'lead_ad',
            'tracking' => [
                'utm_source' => 'google',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'brand-abril',
                'gclid' => 'CJ0KEQjw',
                'landing_url' => 'https://mi-landing.com/oferta?utm_source=google',
            ],
        ])->assertCreated();

        $lead = Lead::forAccount($account->id)->first();
        $this->assertSame('google', $lead->utm_source);
        $this->assertSame('cpc', $lead->utm_medium);
        $this->assertSame('brand-abril', $lead->utm_campaign);
        $this->assertSame('CJ0KEQjw', $lead->gclid);
        $this->assertSame('https://mi-landing.com/oferta?utm_source=google', $lead->landing_url);
        $this->assertNotNull($lead->first_touch_at);
    }

    public function test_first_touch_no_se_sobreescribe_al_reenviar_lead_ad(): void
    {
        [$user, $account] = $this->makeAccount();
        [, $token] = ApiKey::issue($account->id, $user->id, 'meta_ads', ['leads:write']);

        // 1er submit con Google.
        $this->withToken($token)->postJson('/api/v1/leads', [
            'name' => 'Bea',
            'phone' => '+58 412 555 0002',
            'meta_leadgen_id' => 'LG_A',
            'tracking' => ['utm_source' => 'google', 'utm_campaign' => 'original'],
        ])->assertCreated();

        // Reenvío del mismo leadgen con tracking distinto — el original manda.
        $this->withToken($token)->postJson('/api/v1/leads', [
            'name' => 'Bea',
            'phone' => '+58 412 555 0002',
            'meta_leadgen_id' => 'LG_A',
            'tracking' => ['utm_source' => 'facebook', 'utm_campaign' => 'intento-de-pisar'],
        ])->assertOk()->assertJsonPath('duplicated', true);

        $lead = Lead::forAccount($account->id)->first();
        $this->assertSame('google', $lead->utm_source);
        $this->assertSame('original', $lead->utm_campaign);
    }

    public function test_patch_tracking_completa_campos_vacios_pero_no_pisa_los_existentes(): void
    {
        [$user, $account] = $this->makeAccount();
        [, $token] = ApiKey::issue($account->id, $user->id, 'meta_ads', ['leads:write']);

        // Lead sin tracking previo.
        $this->withToken($token)->postJson('/api/v1/leads', [
            'name' => 'Carla', 'phone' => '+58 412 555 0003',
            'tracking' => ['utm_source' => 'tiktok'],
        ])->assertCreated();

        $lead = Lead::forAccount($account->id)->first();

        // PATCH agrega campos nuevos y NO pisa el utm_source ya seteado.
        $this->withToken($token)->patchJson("/api/v1/leads/{$lead->id}/tracking", [
            'utm_source' => 'meta',  // debería ser ignorado (ya hay 'tiktok')
            'utm_campaign' => 'retarg-mayo',
            'ttclid' => 'TT_XYZ',
        ])->assertOk()->assertJsonPath('changed', true);

        $lead->refresh();
        $this->assertSame('tiktok', $lead->utm_source);         // preservado
        $this->assertSame('retarg-mayo', $lead->utm_campaign);  // agregado
        $this->assertSame('TT_XYZ', $lead->ttclid);
    }

    public function test_web_form_captura_utms_desde_el_post_del_formulario(): void
    {
        [, $account, $pipeline] = $this->makeAccount();
        $form = WebForm::create([
            'account_id' => $account->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Landing',
            'token' => Str::lower(Str::random(24)),
        ]);

        $this->post("/f/{$form->token}", [
            'name' => 'Diego',
            'phone' => '+58 412 555 0004',
            'utm_source' => 'meta',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'ig-stories-abril',
            'fbclid' => 'FB_ABC123',
            'landing_url' => 'https://landing.mx/promo',
        ])->assertRedirect();

        $lead = Lead::forAccount($account->id)->first();
        $this->assertNotNull($lead);
        $this->assertSame('meta', $lead->utm_source);
        $this->assertSame('ig-stories-abril', $lead->utm_campaign);
        $this->assertSame('FB_ABC123', $lead->fbclid);
        $this->assertSame('https://landing.mx/promo', $lead->landing_url);
    }

    public function test_referral_de_whatsapp_deriva_utm_source_meta_ads(): void
    {
        [, $account] = $this->makeAccount();
        Integration::create([
            'account_id' => $account->id,
            'wacrm_url' => 'http://localhost:8000',
            'wacrm_api_key' => 'k',
            'webhook_secret' => 'whsec_s',
            'is_active' => true,
        ]);

        $body = json_encode([
            'event' => 'message.received',
            'data' => [
                'conversation_id' => 'conv-utm-1',
                'contact' => ['phone' => '584125550005', 'name' => 'Elsa'],
                'message' => [
                    'text' => 'Vi su anuncio',
                    'type' => 'text',
                    'wamid' => 'wamid.U1',
                    'referral' => [
                        'source_id' => 'AD_777',
                        'source_type' => 'ad',
                        'source_url' => 'https://fb.me/promo',
                        'ctwa_clid' => 'CTWA_XYZ',
                    ],
                ],
            ],
        ]);

        $this->call('POST', "/webhooks/wacrm/{$account->id}", [], [], [], [
            'HTTP_X-Webhook-Signature' => 'sha256='.hash_hmac('sha256', $body, 'whsec_s'),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $lead = Lead::forAccount($account->id)->first();
        $this->assertSame('meta_ads', $lead->utm_source);
        $this->assertSame('cpc', $lead->utm_medium);
        $this->assertSame('AD_777', $lead->utm_campaign);
        $this->assertSame('CTWA_XYZ', $lead->fbclid);
    }

    public function test_track_js_devuelve_snippet_ejecutable_y_cacheado(): void
    {
        $response = $this->get('/track.js')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8');

        $body = $response->getContent();
        $this->assertStringContainsString('KomoTrack', $body);
        $this->assertStringContainsString('komo_first_touch_v1', $body);
        $this->assertStringContainsString('data-komo-track', $body);
    }

    public function test_reportes_agrupan_por_utm_source(): void
    {
        [$user, $account, $pipeline, $stages] = $this->makeAccount();

        // Fabrico 3 leads: 2 de google (1 ganado, 1 perdido) y 1 de meta.
        Lead::create(['account_id' => $account->id, 'pipeline_id' => $pipeline->id, 'stage_id' => $stages[1]->id, 'title' => 'G1', 'status' => 'won', 'value' => 100, 'utm_source' => 'google']);
        Lead::create(['account_id' => $account->id, 'pipeline_id' => $pipeline->id, 'stage_id' => $stages[2]->id, 'title' => 'G2', 'status' => 'lost', 'utm_source' => 'google']);
        Lead::create(['account_id' => $account->id, 'pipeline_id' => $pipeline->id, 'stage_id' => $stages[1]->id, 'title' => 'M1', 'status' => 'won', 'value' => 50, 'utm_source' => 'meta']);

        $response = $this->actingAs($user)->get('/reports');
        $response->assertOk();

        $rows = collect($response->viewData('page')['props']['byUtmSource']);
        $google = $rows->firstWhere('label', 'google');
        $meta = $rows->firstWhere('label', 'meta');

        $this->assertNotNull($google);
        $this->assertSame(2, $google['total']);
        $this->assertSame(1, $google['won']);
        $this->assertSame(1, $google['lost']);
        $this->assertSame(50.0, (float) $google['conversion_rate']);
        $this->assertSame(1, $meta['total']);
        $this->assertSame(100.0, (float) $meta['conversion_rate']);
    }
}
