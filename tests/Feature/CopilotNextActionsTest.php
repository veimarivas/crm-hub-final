<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Task;
use App\Models\User;
use App\Services\Copilot\NextActions;
use App\Services\Copilot\ScoreLeads;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T1.c — la capa prescriptiva.
 *
 * Un score dice cuánto importa un lead; esto dice qué hacer, que es lo único
 * que cambia un resultado. Los tests fijan que cada sugerencia aparezca cuando
 * corresponde, con su motivo, y —sobre todo— que **no** aparezca cuando no.
 * Un panel que sugiere siempre algo se vuelve ruido de fondo en una semana.
 */
class CopilotNextActionsTest extends TestCase
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
            ['name' => 'Negociación', 'stage_type' => 'open'],
            ['name' => 'Ganado', 'stage_type' => 'won'],
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
            'title' => 'Lead',
            'value' => 1000,
            'source' => 'whatsapp',
            'status' => $overrides['status'] ?? 'open',
            'responsible_user_id' => $this->owner->id,
        ]);
    }

    private function message(Lead $lead, string $type, string $at, array $payload = []): void
    {
        $lead->events()->create([
            'account_id' => $this->account->id,
            'event_type' => $type,
            'payload' => $payload,
        ])->forceFill(['created_at' => Carbon::parse($at)])->save();
    }

    private function pendingTask(Lead $lead): void
    {
        Task::create([
            'account_id' => $this->account->id,
            'lead_id' => $lead->id,
            'assigned_to' => $this->owner->id,
            'task_type' => 'call',
            'text' => 'Llamar',
            'due_at' => now()->addDay(),
        ]);
    }

    /** @return array<string, array<string, mixed>> sugerencias por key */
    private function actionsFor(Lead $lead): array
    {
        return collect(app(NextActions::class)->forLead($lead->refresh()))->keyBy('key')->all();
    }

    public function test_sugiere_responder_cuando_el_cliente_quedo_esperando(): void
    {
        $lead = $this->makeLead();
        $this->message($lead, 'message_in', '2026-08-08 09:00:00');

        $actions = $this->actionsFor($lead);

        $this->assertArrayHasKey('reply', $actions);
        $this->assertSame('danger', $actions['reply']['tone'], '3 h de espera supera el SLA.');
        $this->assertStringContainsString('3 h', $actions['reply']['reason']);
    }

    public function test_no_sugiere_responder_si_ya_le_contestaron(): void
    {
        $lead = $this->makeLead();
        $this->message($lead, 'message_in', '2026-08-08 09:00:00');
        $this->message($lead, 'message_out', '2026-08-08 09:05:00', ['sender' => 'agent']);

        $this->assertArrayNotHasKey('reply', $this->actionsFor($lead));
    }

    public function test_sugiere_agendar_cuando_no_hay_tarea_pendiente(): void
    {
        $lead = $this->makeLead();

        $actions = $this->actionsFor($lead);

        $this->assertArrayHasKey('task', $actions);
    }

    public function test_no_sugiere_agendar_si_ya_hay_una_tarea(): void
    {
        $lead = $this->makeLead();
        $this->pendingTask($lead);

        $this->assertArrayNotHasKey('task', $this->actionsFor($lead));
    }

    public function test_avisa_cuando_el_lead_se_enfrio(): void
    {
        $lead = $this->makeLead();
        $lead->forceFill(['score_band' => 'frio', 'score_band_previous' => 'caliente'])->save();

        $actions = $this->actionsFor($lead);

        $this->assertArrayHasKey('cooled', $actions);
        $this->assertStringContainsString('caliente', $actions['cooled']['reason']);
    }

    public function test_no_avisa_de_enfriamiento_si_el_lead_mejoro(): void
    {
        $lead = $this->makeLead();
        $lead->forceFill(['score_band' => 'caliente', 'score_band_previous' => 'frio'])->save();

        $this->assertArrayNotHasKey('cooled', $this->actionsFor($lead));
    }

    public function test_sugiere_mover_cuando_lleva_mucho_en_la_misma_etapa(): void
    {
        $lead = $this->makeLead(['stage_id' => $this->stages[1]->id]);
        $lead->forceFill(['created_at' => Carbon::parse('2026-07-01 10:00:00')])->save();

        $actions = $this->actionsFor($lead);

        $this->assertArrayHasKey('stagnant', $actions);
        $this->assertStringContainsString('Negociación', $actions['stagnant']['reason']);
    }

    public function test_un_lead_cerrado_no_recibe_sugerencias(): void
    {
        $lead = $this->makeLead(['status' => 'won']);
        $this->message($lead, 'message_in', '2026-08-08 09:00:00');

        // Sugerirle algo a un lead cerrado no solo es ruido: empuja a reabrir
        // algo que el equipo ya decidió.
        $this->assertSame([], app(NextActions::class)->forLead($lead));
    }

    public function test_las_sugerencias_se_ordenan_por_urgencia_y_se_cortan(): void
    {
        $lead = $this->makeLead(['stage_id' => $this->stages[1]->id]);
        $lead->forceFill([
            'created_at' => Carbon::parse('2026-06-01 10:00:00'),
            'score_band' => 'frio',
            'score_band_previous' => 'caliente',
        ])->save();
        $this->message($lead, 'message_in', '2026-08-08 09:00:00');

        $actions = app(NextActions::class)->forLead($lead->refresh());

        $this->assertLessThanOrEqual(4, count($actions), 'Si todo urge, nada urge.');
        // Un cliente esperando gana a cualquier otra cosa.
        $this->assertSame('reply', $actions[0]['key']);
        $this->assertSame('cooled', $actions[1]['key']);
    }

    public function test_cada_sugerencia_trae_motivo_y_accion(): void
    {
        $lead = $this->makeLead();
        $this->message($lead, 'message_in', '2026-08-08 09:00:00');

        foreach (app(NextActions::class)->forLead($lead->refresh()) as $action) {
            $this->assertNotEmpty($action['reason'], 'Sin motivo, la sugerencia no se acciona.');
            $this->assertNotEmpty($action['action']['type'], 'Sin acción, es un consejo genérico.');
        }
    }

    // ---- Banda anterior ----

    public function test_la_banda_anterior_solo_se_pisa_cuando_cambia(): void
    {
        $lead = $this->makeLead();
        $lead->forceFill(['score_band' => 'caliente'])->save();

        // Primera pasada: baja a otra banda y se recuerda de dónde venía.
        $lead->forceFill(['score_band' => 'frio', 'score_band_previous' => 'caliente'])->save();

        // Segunda pasada sin cambio de banda: la anterior debe sobrevivir, si
        // no, a las 24 h «se enfrió» dejaría de detectarse.
        app(ScoreLeads::class)->forAccount($this->account->id);
        $lead->refresh();

        if ($lead->score_band === 'frio') {
            $this->assertSame('caliente', $lead->score_band_previous);
        } else {
            $this->assertSame('frio', $lead->score_band_previous);
        }
    }

    public function test_el_panel_del_copiloto_viaja_con_la_ficha(): void
    {
        $lead = $this->makeLead();
        $this->message($lead, 'message_in', '2026-08-08 09:00:00');
        app(ScoreLeads::class)->forAccount($this->account->id);

        $props = $this->actingAs($this->owner)
            ->get(route('leads.show', $lead))
            ->viewData('page')['props'];

        $this->assertNotNull($props['copilot']['score']);
        $this->assertNotEmpty($props['copilot']['factors']);
        $this->assertNotEmpty($props['copilot']['actions']);
        // Con una cuenta nueva no hay historia: la ficha tiene que decirlo.
        $this->assertFalse($props['copilot']['calibration']['calibrated']);
    }
}
