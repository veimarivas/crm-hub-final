<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportMeasuresTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Account $account;

    private Pipeline $pipeline;

    private $stages;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-07 12:00:00');

        $this->owner = User::create(['name' => 'Owner', 'email' => 'o@test.com', 'password' => bcrypt('secret')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => 'owner']);
        $this->owner->refresh();

        $this->pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas', 'is_default' => true]);
        $this->stages = collect([
            ['name' => 'Nuevo', 'stage_type' => 'open', 'color' => '#0ea5e9'],
            ['name' => 'Negociación', 'stage_type' => 'open', 'color' => '#f59e0b'],
            ['name' => 'Ganado', 'stage_type' => 'won', 'color' => '#10b981'],
            ['name' => 'Perdido', 'stage_type' => 'lost', 'color' => '#f43f5e'],
        ])->map(fn ($s, $i) => PipelineStage::create(['pipeline_id' => $this->pipeline->id, 'position' => $i, ...$s]));
    }

    /** Crea un lead cerrado (won/lost) con timestamps explícitos. */
    private function makeLead(array $overrides = []): Lead
    {
        Carbon::setTestNow($overrides['created_at'] ?? '2026-08-01 10:00:00');
        $lead = Lead::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stages[0]->id,
            'title' => 'Lead',
            'value' => $overrides['value'] ?? 500,
            'source' => $overrides['source'] ?? 'whatsapp',
            'responsible_user_id' => $this->owner->id,
        ]);
        Carbon::setTestNow('2026-08-07 12:00:00');

        if (isset($overrides['closed_at'])) {
            $lead->update(['status' => 'won', 'stage_id' => $this->stages[2]->id, 'closed_at' => $overrides['closed_at']]);
            if ($overrides['won'] ?? true) {
                $lead->update(['invoiced_cents' => $overrides['invoiced_cents'] ?? 0, 'collected_cents' => $overrides['collected_cents'] ?? 0]);
            }
        }

        return $lead->refresh();
    }

    public function test_reportes_devuelven_kpis_de_la_ventana_con_delta(): void
    {
        // Ventana actual (7d): 2 ganados, 1 perdido; la anterior: 1 ganado.
        $this->makeLead(['created_at' => '2026-08-01 10:00:00', 'closed_at' => '2026-08-05 10:00:00', 'value' => 1000]);
        $this->makeLead(['created_at' => '2026-08-06 10:00:00', 'closed_at' => '2026-08-07 09:00:00', 'value' => 200]);
        $this->makeLead(['created_at' => '2026-07-28 10:00:00', 'closed_at' => '2026-07-31 10:00:00', 'value' => 50]);

        $response = $this->actingAs($this->owner)->get('/reports?days=7');
        $response->assertOk();

        $kpi = collect($response->viewData('page')['props']['kpi']);
        $won = $kpi->firstWhere('key', 'won');
        $created = $kpi->firstWhere('key', 'created');

        // 2 ganados en la ventana actual vs 1 en la anterior → +100%.
        $this->assertSame(2, $won['value']);
        $this->assertSame(100.0, $won['delta']);
        // creados en la ventana: 01/08 y 06/08; el del 28/07 cae en la anterior.
        $this->assertSame(2, $created['value']);
    }

    public function test_reportes_series_semanal_tiene_26_puntos(): void
    {
        $this->makeLead(['created_at' => '2026-08-05 10:00:00', 'closed_at' => '2026-08-07 09:00:00']);

        $response = $this->actingAs($this->owner)->get('/reports?days=90');
        $weekly = $response->viewData('page')['props']['weekly'];

        $this->assertCount(26, $weekly);
        $last = collect($weekly)->last();
        $this->assertSame(1, (int) $last['created']);
        $this->assertSame(1, (int) $last['won']);
    }

    public function test_reportes_incluyen_revenue_y_histograma_de_cierre(): void
    {
        // Creado hace 6 días, ganado hoy con factura/cobro.
        $this->makeLead(['created_at' => '2026-08-01 10:00:00', 'closed_at' => '2026-08-07 10:00:00', 'value' => 1000, 'invoiced_cents' => 100000, 'collected_cents' => 40000]);

        $response = $this->actingAs($this->owner)->get('/reports?days=30');
        $props = $response->viewData('page')['props'];

        $this->assertSame(1000.0, (float) $props['revenue']['invoiced']);
        $this->assertSame(400.0, (float) $props['revenue']['collected']);
        // 6 días de ventana → bucket "3–7 d".
        $this->assertSame(1, collect($props['closeTime'])->firstWhere('name', '3–7 d')['value']);
    }

    public function test_reports_export_csv_usa_separador_y_bom(): void
    {
        $this->makeLead(['created_at' => '2026-08-01 10:00:00', 'closed_at' => '2026-08-05 10:00:00', 'value' => 1000]);

        $response = $this->actingAs($this->owner)->get('/reports/export?days=30');
        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

$content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('Titulo;Contacto;Telefono', $content);
        $this->assertStringContainsString('Lead', $content);
    }

    public function test_export_respeta_el_scope_de_rol(): void
    {
        $agent = User::create(['name' => 'Agent', 'email' => 'a@test.com', 'password' => bcrypt('secret'), 'account_id' => $this->account->id, 'account_role' => 'agent']);

        $this->makeLead(['created_at' => '2026-08-01 10:00:00']); // del owner
        $other = $this->makeLead(['created_at' => '2026-08-02 10:00:00']);
        $other->update(['responsible_user_id' => $agent->id]);

        $response = $this->actingAs($agent)->get('/reports/export?days=30');
        $content = $response->streamedContent();

        $this->assertStringNotContainsString($this->owner->name, $content);
        $this->assertStringContainsString($agent->name, $content);
    }

    public function test_agente_ve_reports_sin_seccion_de_equipo(): void
    {
        $agent = User::create(['name' => 'Agent', 'email' => 'a2@test.com', 'password' => bcrypt('secret'), 'account_id' => $this->account->id, 'account_role' => 'agent']);
        $this->makeLead(['closed_at' => '2026-08-05 10:00:00']);

        $response = $this->actingAs($agent)->get('/reports');
        $props = $response->viewData('page')['props'];

        $this->assertFalse($props['isAdmin']);
        $this->assertCount(0, $props['teamStages']);
        $this->assertCount(0, $props['byUser']);
    }

    public function test_lead_index_filtra_por_stage(): void
    {
        $this->makeLead(['created_at' => '2026-08-01 10:00:00']);
        $leadB = $this->makeLead(['created_at' => '2026-08-02 10:00:00']);
        $leadB->update(['stage_id' => $this->stages[1]->id]);

        $response = $this->actingAs($this->owner)->get('/leads?stage_id='.$this->stages[1]->id);
        $response->assertOk();

        $leads = $response->viewData('page')['props']['leads'];
        $this->assertCount(1, $leads);
        $this->assertSame($leadB->id, $leads[0]['id']);
    }
}