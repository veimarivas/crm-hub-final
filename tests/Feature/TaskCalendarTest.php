<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T7 — calendario visual de tareas.
 *
 * Lo que mas importa aca es la **zona horaria**: el calendario expone
 * cualquier inconsistencia que en una lista pasa desapercibida. Una tarea de
 * las 23:30 tiene que caer en el dia que ve el usuario, no en el que calcule
 * el navegador de turno.
 */
class TaskCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $agent;

    private Account $account;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-12 15:00:00'); // miercoles, UTC

        $this->owner = User::create(['name' => 'Owner', 'email' => 'o@test.com', 'password' => bcrypt('secret')]);
        $this->account = Account::create([
            'name' => 'Empresa', 'owner_user_id' => $this->owner->id,
            'business_hours_timezone' => 'America/La_Paz', // UTC-4
        ]);
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
        $contact = Contact::create(['account_id' => $this->account->id, 'name' => 'Ana', 'phone' => '+59171234567']);

        $this->lead = Lead::create([
            'account_id' => $this->account->id, 'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id,
            'contact_id' => $contact->id, 'title' => 'Ana', 'value' => 100, 'source' => 'whatsapp',
            'responsible_user_id' => $this->owner->id,
        ]);
    }

    private function makeTask(array $overrides = []): Task
    {
        return Task::create([
            'account_id' => $this->account->id,
            'lead_id' => $this->lead->id,
            'assigned_to' => $overrides['assigned_to'] ?? $this->owner->id,
            'task_type' => $overrides['task_type'] ?? 'call',
            'text' => $overrides['text'] ?? 'Llamar a Ana',
            'due_at' => $overrides['due_at'] ?? '2026-08-13 14:00:00',
        ]);
    }

    private function calendar(array $query = [], ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->owner)
            ->get(route('tasks.index', ['view' => 'calendar', 'month' => '2026-08', ...$query]))
            ->assertOk()
            ->viewData('page')['props']['calendar'];
    }

    // ---- Zona horaria ----

    public function test_el_dia_de_la_tarea_se_calcula_en_la_zona_de_la_cuenta(): void
    {
        // 2026-08-14 02:30 UTC = 2026-08-13 22:30 en La Paz. Para el equipo
        // esta tarea es del jueves 13, no del viernes 14.
        $this->makeTask(['due_at' => '2026-08-14 02:30:00']);

        $task = $this->calendar()['tasks'][0];

        $this->assertSame('2026-08-13', $task['due_date']);
        $this->assertSame('22:30', $task['due_time']);
    }

    public function test_el_hoy_del_calendario_tambien_va_en_la_zona_de_la_cuenta(): void
    {
        // 2026-08-12 15:00 UTC = 11:00 en La Paz, mismo dia.
        $this->assertSame('2026-08-12', $this->calendar()['today']);
        $this->assertSame('America/La_Paz', $this->calendar()['timezone']);
    }

    // ---- Reprogramar ----

    public function test_arrastrar_a_otro_dia_conserva_la_hora(): void
    {
        $task = $this->makeTask(['due_at' => '2026-08-13 14:00:00']); // 10:00 La Paz

        $this->actingAs($this->owner)
            ->patch(route('tasks.reschedule', $task), ['date' => '2026-08-20'])
            ->assertRedirect();

        // Mover «llamar a las 10:00» del jueves al jueves siguiente no puede
        // convertirla en una tarea de medianoche.
        $due = $task->refresh()->due_at->setTimezone('America/La_Paz');
        $this->assertSame('2026-08-20', $due->format('Y-m-d'));
        $this->assertSame('10:00', $due->format('H:i'));
    }

    public function test_se_puede_reprogramar_con_una_hora_nueva(): void
    {
        $task = $this->makeTask();

        $this->actingAs($this->owner)
            ->patch(route('tasks.reschedule', $task), ['date' => '2026-08-20', 'time' => '16:30'])
            ->assertRedirect();

        $this->assertSame('16:30', $task->refresh()->due_at->setTimezone('America/La_Paz')->format('H:i'));
    }

    public function test_reprogramar_queda_en_el_timeline_del_lead(): void
    {
        $task = $this->makeTask();

        $this->actingAs($this->owner)->patch(route('tasks.reschedule', $task), ['date' => '2026-08-20']);

        // Una tarea que se corre tres veces cuenta una historia, y esa historia
        // se pierde si reprogramar es silencioso.
        $this->assertSame(1, $this->lead->events()->where('event_type', 'task_rescheduled')->count());
    }

    public function test_reprogramar_reabre_el_aviso_de_vencimiento(): void
    {
        $task = $this->makeTask(['due_at' => '2026-08-01 14:00:00']);
        $task->forceFill(['overdue_notified_at' => now()])->save();

        $this->actingAs($this->owner)->patch(route('tasks.reschedule', $task), ['date' => '2026-08-20']);

        // Si no se limpia, la tarea vence de nuevo y nadie se entera.
        $this->assertNull($task->refresh()->overdue_notified_at);
    }

    public function test_un_agente_no_reprograma_la_tarea_de_otro(): void
    {
        $task = $this->makeTask(['assigned_to' => $this->owner->id]);

        $this->actingAs($this->agent)
            ->patch(route('tasks.reschedule', $task), ['date' => '2026-08-20'])
            ->assertForbidden();
    }

    public function test_una_fecha_invalida_se_rechaza(): void
    {
        $task = $this->makeTask();

        $this->actingAs($this->owner)
            ->patch(route('tasks.reschedule', $task), ['date' => '20 de agosto'])
            ->assertSessionHasErrors('date');
    }

    // ---- Filtros ----

    public function test_filtra_por_tipo_de_tarea(): void
    {
        $this->makeTask(['task_type' => 'call', 'text' => 'Llamada']);
        $this->makeTask(['task_type' => 'meet', 'text' => 'Reunion']);

        $tasks = $this->calendar(['type' => 'meet'])['tasks'];

        $this->assertCount(1, $tasks);
        $this->assertSame('Reunion', $tasks[0]['text']);
    }

    public function test_el_admin_filtra_por_responsable_viendo_al_equipo(): void
    {
        $this->makeTask(['assigned_to' => $this->owner->id, 'text' => 'Del owner']);
        $this->makeTask(['assigned_to' => $this->agent->id, 'text' => 'Del agente']);

        $tasks = $this->calendar(['mine' => 0, 'responsible' => $this->agent->id])['tasks'];

        $this->assertCount(1, $tasks);
        $this->assertSame('Del agente', $tasks[0]['text']);
    }

    public function test_el_agente_solo_ve_su_agenda_aunque_pida_la_del_equipo(): void
    {
        $this->makeTask(['assigned_to' => $this->owner->id]);
        $this->makeTask(['assigned_to' => $this->agent->id]);

        // `mine=0` a mano no le abre la agenda del equipo.
        $tasks = $this->calendar(['mine' => 0], $this->agent)['tasks'];

        $this->assertCount(1, $tasks);
    }

    // ---- Días laborables ----

    public function test_los_dias_laborables_salen_del_horario_de_la_cuenta(): void
    {
        $this->account->forceFill([
            'business_hours_enabled' => true,
            'business_hours_schedule' => [
                'mon' => ['from' => '09:00', 'to' => '18:00'],
                'tue' => ['from' => '09:00', 'to' => '18:00'],
                'wed' => ['from' => '09:00', 'to' => '18:00'],
                'thu' => ['from' => '09:00', 'to' => '18:00'],
                'fri' => ['from' => '09:00', 'to' => '18:00'],
                'sat' => null,
                'sun' => null,
            ],
        ])->save();

        // No hace falta un ajuste nuevo: ya se configura en /settings/business-hours.
        $this->assertSame([1, 2, 3, 4, 5], $this->calendar()['workingDays']);
    }

    public function test_sin_horario_configurado_todos_los_dias_son_laborables(): void
    {
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $this->calendar()['workingDays']);
    }
}
