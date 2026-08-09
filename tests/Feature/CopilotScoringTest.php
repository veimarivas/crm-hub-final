<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Task;
use App\Models\User;
use App\Services\Copilot\LeadScorer;
use App\Services\Copilot\LeadSignals;
use App\Services\Copilot\ScoreLeads;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T1 — el copiloto.
 *
 * Lo que estos tests protegen no es el número exacto sino las **promesas** que
 * el módulo le hace al usuario: que el score se explica, que no se inventa una
 * calibración que no tiene, y que un lead frío puntúa menos que uno caliente.
 * Si algún día los pesos cambian, estos tests deben seguir pasando; si dejan de
 * pasar, cambió una promesa y no un peso.
 */
class CopilotScoringTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Account $account;

    private Pipeline $pipeline;

    private $stages;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-08 12:00:00');

        $this->owner = User::create(['name' => 'Owner', 'email' => 'o@test.com', 'password' => bcrypt('secret')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => 'owner']);
        $this->owner->refresh();

        $this->pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas', 'is_default' => true]);
        $this->stages = collect([
            ['name' => 'Nuevo', 'stage_type' => 'open'],
            ['name' => 'Contactado', 'stage_type' => 'open'],
            ['name' => 'Negociación', 'stage_type' => 'open'],
            ['name' => 'Ganado', 'stage_type' => 'won'],
            ['name' => 'Perdido', 'stage_type' => 'lost'],
        ])->map(fn ($s, $i) => PipelineStage::create([
            'pipeline_id' => $this->pipeline->id, 'position' => $i, 'color' => '#0ea5e9', ...$s,
        ]));
    }

    private function makeLead(array $overrides = []): Lead
    {
        $contact = Contact::create([
            'account_id' => $this->account->id,
            'name' => 'Contacto',
            'phone' => '+5917000'.random_int(1000, 9999),
        ]);

        return Lead::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $overrides['stage_id'] ?? $this->stages[0]->id,
            'contact_id' => $contact->id,
            'title' => $overrides['title'] ?? 'Lead',
            'value' => $overrides['value'] ?? 1000,
            'source' => $overrides['source'] ?? 'whatsapp',
            'status' => $overrides['status'] ?? 'open',
            'responsible_user_id' => $this->owner->id,
        ]);
    }

    /**
     * Registra un mensaje entrante/saliente en una fecha concreta.
     *
     * `created_at` no es fillable en `LeadEvent`, así que hay que pisarlo
     * después de crear: pasarlo en el `create()` lo ignora en silencio y todos
     * los eventos quedan con la hora del test.
     */
    private function message(Lead $lead, string $type, string $at, array $payload = []): void
    {
        $lead->events()->create([
            'account_id' => $this->account->id,
            'event_type' => $type,
            'payload' => $payload,
        ])->forceFill(['created_at' => Carbon::parse($at)])->save();
    }

    // ---- Señales ----

    public function test_las_senales_salen_en_lote_y_cuentan_humano_vs_ia(): void
    {
        $lead = $this->makeLead();
        $this->message($lead, 'message_in', '2026-08-07 10:00:00');
        $this->message($lead, 'message_in', '2026-08-07 10:05:00');
        $this->message($lead, 'message_out', '2026-08-07 10:06:00', ['sender' => 'bot']);
        $this->message($lead, 'message_out', '2026-08-07 11:00:00', ['sender' => 'agent']);

        $signals = (new LeadSignals($this->account->id))->forLeads(collect([$lead]))[$lead->id];

        $this->assertSame(2, $signals['inbound_count']);
        // La respuesta de la IA no cuenta como atención humana.
        $this->assertSame(1, $signals['human_replies']);
        $this->assertEqualsWithDelta(1.0, $signals['days_since_inbound'], 0.1);
    }

    public function test_lead_sin_actividad_no_rompe_las_senales(): void
    {
        $lead = $this->makeLead();

        $signals = (new LeadSignals($this->account->id))->forLeads(collect([$lead]))[$lead->id];

        $this->assertSame(0, $signals['inbound_count']);
        $this->assertNull($signals['days_since_inbound']);
        $this->assertFalse($signals['has_pending_task']);
    }

    public function test_la_tasa_por_fuente_ignora_muestras_chicas(): void
    {
        // 3 cerrados de 'booking': no alcanza para juzgar la fuente.
        foreach (range(1, 3) as $i) {
            $this->makeLead(['source' => 'booking', 'status' => 'won']);
        }
        $lead = $this->makeLead(['source' => 'booking']);

        $signals = (new LeadSignals($this->account->id))->forLeads(collect([$lead]))[$lead->id];

        $this->assertNull($signals['source_win_rate'], 'Con 3 casos, «100% de conversión» es ruido.');
    }

    // ---- Score ----

    public function test_un_lead_activo_puntua_mas_que_uno_abandonado(): void
    {
        $caliente = $this->makeLead(['stage_id' => $this->stages[2]->id]);
        $this->message($caliente, 'message_in', '2026-08-08 09:00:00');
        $this->message($caliente, 'message_in', '2026-08-08 09:02:00');
        $this->message($caliente, 'message_in', '2026-08-08 09:03:00');
        $this->message($caliente, 'message_out', '2026-08-08 09:10:00', ['sender' => 'agent']);

        $frio = $this->makeLead();
        $this->message($frio, 'message_in', '2026-06-01 09:00:00');

        $signals = (new LeadSignals($this->account->id))->forLeads(collect([$caliente, $frio]));
        $scorer = new LeadScorer;

        $this->assertGreaterThan(
            $scorer->score($signals[$frio->id])['score'],
            $scorer->score($signals[$caliente->id])['score'],
        );
    }

    public function test_el_score_viene_con_su_desglose_y_suma_lo_que_muestra(): void
    {
        $lead = $this->makeLead();
        $this->message($lead, 'message_in', '2026-08-08 09:00:00');

        $signals = (new LeadSignals($this->account->id))->forLeads(collect([$lead]))[$lead->id];
        $result = (new LeadScorer)->score($signals);

        $this->assertCount(6, $result['factors'], 'Seis factores, uno por peso declarado.');

        foreach ($result['factors'] as $factor) {
            $this->assertNotEmpty($factor['detail'], 'Cada factor explica por qué suma lo que suma.');
            $this->assertLessThanOrEqual($factor['max'], $factor['points']);
        }

        // El número que se muestra es la suma de lo que se muestra: sin esto,
        // el desglose sería decorativo.
        $this->assertSame($result['score'], (int) round(array_sum(array_column($result['factors'], 'points'))));
    }

    public function test_nadie_le_contesto_resta_el_factor_de_atencion(): void
    {
        $lead = $this->makeLead();
        $this->message($lead, 'message_in', '2026-08-08 09:00:00');

        $signals = (new LeadSignals($this->account->id))->forLeads(collect([$lead]))[$lead->id];
        $atencion = collect((new LeadScorer)->score($signals)['factors'])->firstWhere('key', 'attention');

        $this->assertSame(0.0, $atencion['points']);
        $this->assertSame('Escribió y nadie le contestó', $atencion['detail']);
    }

    public function test_el_score_esta_acotado_entre_0_y_100(): void
    {
        $lead = $this->makeLead(['stage_id' => $this->stages[2]->id, 'value' => 999999]);
        foreach (range(1, 50) as $i) {
            $this->message($lead, 'message_in', '2026-08-08 09:00:00');
        }
        $this->message($lead, 'message_out', '2026-08-08 09:30:00', ['sender' => 'agent']);

        $signals = (new LeadSignals($this->account->id))->forLeads(collect([$lead]))[$lead->id];
        $score = (new LeadScorer)->score($signals)['score'];

        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThanOrEqual(100, $score);
    }

    // ---- Bandas ----

    public function test_con_pocos_leads_las_bandas_son_absolutas(): void
    {
        $bands = (new LeadScorer)->bands(collect([10, 50, 90]));

        $this->assertSame('absoluto', $bands['mode'], 'Un tercil sobre 3 leads no significa nada.');
    }

    public function test_con_leads_suficientes_las_bandas_son_por_percentil(): void
    {
        $scores = collect(range(1, 30));

        $bands = (new LeadScorer)->bands($scores);

        $this->assertSame('percentil', $bands['mode']);
        $this->assertGreaterThan($bands['warm'], $bands['hot']);
    }

    // ---- Calibración: la promesa que más importa ----

    public function test_sin_historia_suficiente_la_calibracion_se_declara_sin_calibrar(): void
    {
        foreach (range(1, 5) as $i) {
            $lead = $this->makeLead(['status' => 'won']);
            $lead->forceFill(['score_band' => 'caliente'])->save();
        }

        $calibration = (new LeadScorer)->calibration($this->account->id);

        $this->assertFalse($calibration['calibrated']);
        $this->assertSame(5, $calibration['closed']);
        // El dato crudo se devuelve igual; lo que NO se hace es presentarlo
        // como si fuera representativo.
        $this->assertSame(100.0, $calibration['bands']['caliente']['rate']);
    }

    public function test_la_calibracion_mide_lo_que_realmente_cerro_por_banda(): void
    {
        // 3 de 4 «caliente» ganados, 1 de 4 «frio».
        foreach ([['caliente', 'won'], ['caliente', 'won'], ['caliente', 'won'], ['caliente', 'lost'],
            ['frio', 'won'], ['frio', 'lost'], ['frio', 'lost'], ['frio', 'lost']] as [$band, $status]) {
            $this->makeLead(['status' => $status])->forceFill(['score_band' => $band])->save();
        }

        $bands = (new LeadScorer)->calibration($this->account->id)['bands'];

        $this->assertSame(75.0, $bands['caliente']['rate']);
        $this->assertSame(25.0, $bands['frio']['rate']);
    }

    // ---- Persistencia ----

    public function test_puntuar_la_cuenta_persiste_score_banda_y_factores(): void
    {
        $lead = $this->makeLead();
        $this->message($lead, 'message_in', '2026-08-08 09:00:00');

        $result = app(ScoreLeads::class)->forAccount($this->account->id);
        $lead->refresh();

        $this->assertSame(1, $result['scored']);
        $this->assertNotNull($lead->score);
        $this->assertContains($lead->score_band, ['caliente', 'tibio', 'frio']);
        $this->assertIsArray($lead->score_factors, 'El cast `array` evita el doble encode.');
        $this->assertNotNull($lead->scored_at);
    }

    public function test_los_leads_cerrados_conservan_la_banda_que_tenian(): void
    {
        $cerrado = $this->makeLead(['status' => 'won']);
        $cerrado->forceFill(['score' => 90, 'score_band' => 'caliente'])->save();

        app(ScoreLeads::class)->forAccount($this->account->id);
        $cerrado->refresh();

        // Repuntuar al cerrar destruiría la calibración: se perdería en qué
        // banda estaba cuando todavía se podía hacer algo.
        $this->assertSame(90, $cerrado->score);
        $this->assertSame('caliente', $cerrado->score_band);
    }

    public function test_el_comando_agendado_corre_sobre_todas_las_cuentas(): void
    {
        $lead = $this->makeLead();
        $this->message($lead, 'message_in', '2026-08-08 09:00:00');

        $this->artisan('copilot:score-leads')->assertSuccessful();

        $this->assertNotNull($lead->refresh()->score);
    }
}
