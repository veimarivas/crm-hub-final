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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Panel de seguimiento del admin. Lo que se fija aca son las definiciones:
 * si cambian, los numeros que el admin usa para decidir cambian de sentido.
 */
class SupervisionMetricsTest extends TestCase
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
            'wacrm_conversation_id' => 'conv-'.$name,
            'responsible_user_id' => $responsible?->id,
        ]);
    }

    /** Los eventos se insertan con created_at explicito para controlar los tiempos. */
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
        return (new ResponseMetrics($this->account->id, now()->subDays($days)->startOfDay()))->build();
    }

    public function test_mide_la_primera_respuesta_desde_el_primer_mensaje_del_contacto(): void
    {
        $lead = $this->makeLead($this->agent);
        $this->msg($lead, 'message_in', now()->subHours(3)->toDateTimeString());
        $this->msg($lead, 'message_out', now()->subHours(3)->addMinutes(10)->toDateTimeString(), $this->agent);

        $row = collect($this->build()['leads'])->firstWhere('id', $lead->id);

        $this->assertSame(600, $row['first_response_seconds']);
        $this->assertSame('responsable', $row['first_responder']);
        $this->assertNull($row['awaiting_minutes']);
    }

    public function test_los_mensajes_seguidos_del_contacto_no_reinician_el_reloj(): void
    {
        $lead = $this->makeLead($this->agent);
        $this->msg($lead, 'message_in', now()->subHours(2)->toDateTimeString());
        $this->msg($lead, 'message_in', now()->subHours(2)->addMinutes(5)->toDateTimeString());
        $this->msg($lead, 'message_in', now()->subHours(2)->addMinutes(9)->toDateTimeString());
        $this->msg($lead, 'message_out', now()->subHours(2)->addMinutes(20)->toDateTimeString(), $this->agent);

        $row = collect($this->build()['leads'])->firstWhere('id', $lead->id);

        // 20 minutos desde el PRIMER mensaje, no 11 desde el ultimo.
        $this->assertSame(1200, $row['first_response_seconds']);
    }

    public function test_la_respuesta_de_la_ia_no_cierra_la_espera_del_humano(): void
    {
        $lead = $this->makeLead($this->agent);
        $this->msg($lead, 'message_in', now()->subMinutes(90)->toDateTimeString());
        $this->msg($lead, 'message_out', now()->subMinutes(89)->toDateTimeString(), bot: true);

        $row = collect($this->build()['leads'])->firstWhere('id', $lead->id);

        $this->assertSame('ia', $row['first_responder']);
        $this->assertNull($row['first_response_seconds'], 'La IA no cuenta como respuesta humana.');
        $this->assertSame(90, $row['awaiting_minutes']);
        $this->assertTrue($row['breached_sla']);
        $this->assertSame(1, $row['bot_replies']);
    }

    public function test_distingue_al_responsable_de_otro_agente_que_contesta_por_el(): void
    {
        $lead = $this->makeLead($this->agent);
        $this->msg($lead, 'message_in', now()->subHour()->toDateTimeString());
        $this->msg($lead, 'message_out', now()->subMinutes(50)->toDateTimeString(), $this->owner);

        $row = collect($this->build()['leads'])->firstWhere('id', $lead->id);

        $this->assertSame('otro_agente', $row['first_responder']);
    }

    public function test_el_saliente_proactivo_no_cuenta_como_respuesta(): void
    {
        $lead = $this->makeLead($this->agent);
        // El agente escribe primero; el contacto contesta y nadie mas responde.
        $this->msg($lead, 'message_out', now()->subHours(5)->toDateTimeString(), $this->agent);
        $this->msg($lead, 'message_in', now()->subHours(4)->toDateTimeString());

        $row = collect($this->build()['leads'])->firstWhere('id', $lead->id);

        $this->assertNull($row['avg_response_seconds'], 'No hubo ninguna respuesta a un entrante.');
        $this->assertSame(240, $row['awaiting_minutes']);
    }

    public function test_promedia_varias_respuestas_del_mismo_lead(): void
    {
        $lead = $this->makeLead($this->agent);
        $base = now()->subHours(6);
        $this->msg($lead, 'message_in', $base->toDateTimeString());
        $this->msg($lead, 'message_out', $base->copy()->addMinutes(10)->toDateTimeString(), $this->agent);
        $this->msg($lead, 'message_in', $base->copy()->addMinutes(30)->toDateTimeString());
        $this->msg($lead, 'message_out', $base->copy()->addMinutes(50)->toDateTimeString(), $this->agent);

        $row = collect($this->build()['leads'])->firstWhere('id', $lead->id);

        $this->assertSame(600, $row['first_response_seconds']);
        $this->assertSame(900, $row['avg_response_seconds']); // (10 + 20) / 2 min
        $this->assertSame(1200, $row['slowest_response_seconds']);
    }

    public function test_agrupa_por_responsable_y_reporta_los_sin_asignar_aparte(): void
    {
        $suyo = $this->makeLead($this->agent, 'Ana');
        $this->msg($suyo, 'message_in', now()->subHours(2)->toDateTimeString());
        $this->msg($suyo, 'message_out', now()->subHours(2)->addMinutes(5)->toDateTimeString(), $this->agent);

        $huerfano = $this->makeLead(null, 'Beto');
        $this->msg($huerfano, 'message_in', now()->subHours(2)->toDateTimeString());

        $agents = collect($this->build()['agents']);

        $daniel = $agents->firstWhere('id', $this->agent->id);
        $this->assertSame(1, $daniel['leads']);
        $this->assertSame(1, $daniel['answered']);
        $this->assertSame(0, $daniel['waiting_now']);
        $this->assertSame(300, $daniel['avg_first_response_seconds']);

        $nadie = $agents->firstWhere('id', null);
        $this->assertSame(1, $nadie['leads']);
        $this->assertSame(1, $nadie['never_answered']);
        $this->assertSame(1, $nadie['breached_sla']);

        // El owner no tiene conversaciones: no ensucia la tabla con una fila vacia.
        $this->assertNull($agents->firstWhere('id', $this->owner->id));
    }

    public function test_ignora_la_actividad_fuera_de_la_ventana(): void
    {
        $lead = $this->makeLead($this->agent);
        $this->msg($lead, 'message_in', now()->subDays(60)->toDateTimeString());
        $this->msg($lead, 'message_out', now()->subDays(60)->addMinutes(5)->toDateTimeString(), $this->agent);

        $this->assertCount(0, $this->build(30)['leads']);
        $this->assertCount(1, $this->build(90)['leads']);
    }

    public function test_solo_el_admin_entra_al_panel(): void
    {
        $this->actingAs($this->agent)->get(route('supervision.index'))->assertForbidden();
        $this->actingAs($this->owner)->get(route('supervision.index'))->assertOk();
    }
}
