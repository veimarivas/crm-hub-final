<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Supervision\ResponseMetrics;
use App\Services\Supervision\TeamComparison;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Comparativas de equipo de `/supervision`. Fija que consumen las mismas
 * definiciones que `ResponseMetrics` (el GEMELO) sin redefinirlas: la IA no
 * cierra la espera y el reloj arranca en el primer mensaje de la ráfaga.
 */
class TeamComparisonTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private User $agent;

    private Pipeline $pipeline;

    private PipelineStage $stage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Administrador', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);
        $this->owner->refresh();

        $this->agent = User::create([
            'name' => 'Daniel', 'email' => 'daniel@test.com', 'password' => bcrypt('password'),
            'account_id' => $this->account->id, 'account_role' => User::ROLE_AGENT,
        ]);

        $this->pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas', 'is_default' => true]);
        $this->stage = PipelineStage::create(['pipeline_id' => $this->pipeline->id, 'name' => 'Nuevo', 'stage_type' => 'open', 'position' => 0]);
    }

    private function makeLead(?User $responsible = null, string $name = 'Ana'): Lead
    {
        $contact = Contact::create(['account_id' => $this->account->id, 'name' => $name, 'phone' => '5917'.random_int(1000000, 9999999)]);

        return Lead::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stage->id,
            'contact_id' => $contact->id,
            'title' => "WhatsApp: {$name}",
            'source' => 'whatsapp',
            'responsible_user_id' => $responsible?->id,
        ]);
    }

    private function msg(Lead $lead, string $type, string $at, ?User $sender = null, bool $bot = false): void
    {
        LeadEvent::create([
            'lead_id' => $lead->id,
            'account_id' => $this->account->id,
            'user_id' => $sender?->id,
            'event_type' => $type,
            'payload' => $bot ? ['sender' => 'bot'] : ['sender' => 'agent'],
        ])->forceFill(['created_at' => $at])->save();
    }

    private function build(int $days = 30): array
    {
        return (new TeamComparison($this->account->id, now()->subDays($days)->startOfDay()))->build();
    }

    public function test_la_mediana_por_responsable_no_se_arrastra_por_un_caso_olvidado(): void
    {
        // Tres respuestas: 10 min, 20 min y una olvidada de 10 horas.
        foreach ([10, 20, 600] as $i => $minutes) {
            $lead = $this->makeLead($this->agent, "Ana{$i}");
            $at = now()->subDays(2)->addHours($i);
            $this->msg($lead, 'message_in', $at->toDateTimeString());
            $this->msg($lead, 'message_out', $at->copy()->addMinutes($minutes)->toDateTimeString(), $this->agent);
        }

        $row = collect($this->build()['responseByAgent'])->firstWhere('id', $this->agent->id);

        // Mediana = 20 min (el promedio seria 210).
        $this->assertSame(20.0, $row['value']);
        $this->assertSame(3, $row['samples']);
        $this->assertSame('Daniel', $row['name']);
    }

    public function test_la_ia_no_cierra_la_espera_ni_cuenta_como_respuesta(): void
    {
        $lead = $this->makeLead($this->agent);
        $at = now()->subDay();
        $this->msg($lead, 'message_in', $at->toDateTimeString());
        $this->msg($lead, 'message_out', $at->copy()->addMinutes(1)->toDateTimeString(), null, bot: true);
        $this->msg($lead, 'message_out', $at->copy()->addMinutes(45)->toDateTimeString(), $this->agent);

        $row = collect($this->build()['responseByAgent'])->firstWhere('id', $this->agent->id);

        // 45 minutos desde el entrante, no 44 desde que la IA contesto.
        $this->assertSame(45.0, $row['value']);
    }

    public function test_el_cumplimiento_del_sla_se_calcula_por_dia(): void
    {
        $day = now()->subDays(3)->setTime(10, 0);

        // Dos dentro del SLA y una fuera, el mismo dia.
        foreach ([5, 10, ResponseMetrics::SLA_MINUTES + 30] as $i => $minutes) {
            $lead = $this->makeLead($this->agent, "Cli{$i}");
            $at = $day->copy()->addMinutes($i * 5);
            $this->msg($lead, 'message_in', $at->toDateTimeString());
            $this->msg($lead, 'message_out', $at->copy()->addMinutes($minutes)->toDateTimeString(), $this->agent);
        }

        $row = collect($this->build()['slaDaily'])->firstWhere('date', $day->format('Y-m-d'));

        $this->assertSame(3, $row['total']);
        $this->assertSame(2, $row['within']);
        $this->assertSame(66.7, $row['pct']);
    }

    public function test_un_dia_sin_respuestas_va_en_null_y_no_en_cero(): void
    {
        $sla = collect($this->build(7)['slaDaily']);

        $this->assertCount(8, $sla); // 7 dias atras + hoy
        $this->assertNull($sla->first()['pct']);
    }

    public function test_el_backlog_agrupa_por_antiguedad_de_la_espera(): void
    {
        // Espera de 2 horas: cae en el balde "1-4 h".
        $lead = $this->makeLead($this->agent);
        $this->msg($lead, 'message_in', now()->subHours(2)->toDateTimeString());

        // Este ya fue atendido: no entra al backlog.
        $atendido = $this->makeLead($this->agent, 'Beto');
        $this->msg($atendido, 'message_in', now()->subHours(5)->toDateTimeString());
        $this->msg($atendido, 'message_out', now()->subHours(4)->toDateTimeString(), $this->agent);

        $backlog = collect($this->build()['backlog']);

        $this->assertSame(1, $backlog->sum('value'));
        $this->assertSame(1, $backlog->firstWhere('name', '1–4 h')['value']);
    }

    public function test_el_heatmap_cuenta_entrantes_por_hora_y_dia(): void
    {
        $at = now()->subDays(2)->setTime(15, 0);

        $lead = $this->makeLead($this->agent);
        $this->msg($lead, 'message_in', $at->toDateTimeString());
        $this->msg($lead, 'message_in', $at->copy()->addMinutes(20)->toDateTimeString());

        $heatmap = collect($this->build()['heatmap']);

        $this->assertCount(7, $heatmap);
        $row = $heatmap[$at->dayOfWeekIso - 1];
        $this->assertSame(2, $row['hours'][15]);
        $this->assertSame(0, $row['hours'][3]);
    }

    public function test_supervision_index_entrega_las_comparativas(): void
    {
        $lead = $this->makeLead($this->agent);
        $this->msg($lead, 'message_in', now()->subDay()->toDateTimeString());
        $this->msg($lead, 'message_out', now()->subDay()->addMinutes(10)->toDateTimeString(), $this->agent);

        $props = $this->actingAs($this->owner)->get('/supervision')
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertArrayHasKey('comparison', $props);
        $this->assertSame(ResponseMetrics::SLA_MINUTES, $props['comparison']['slaMinutes']);
        $this->assertCount(1, $props['comparison']['responseByAgent']);
    }

    public function test_la_ficha_del_agente_trae_el_promedio_del_equipo(): void
    {
        $lead = $this->makeLead($this->agent);
        $this->msg($lead, 'message_in', now()->subDay()->toDateTimeString());
        $this->msg($lead, 'message_out', now()->subDay()->addMinutes(10)->toDateTimeString(), $this->agent);

        $props = $this->actingAs($this->owner)->get('/supervision/agents/'.$this->agent->id)
            ->assertOk()
            ->viewData('page')['props'];

        $teamDaily = collect($props['teamDaily']);
        $ayer = $teamDaily->firstWhere('date', now()->subDay()->format('Y-m-d'));

        $this->assertSame(600, $ayer['team_avg_response_seconds']);
    }
}
