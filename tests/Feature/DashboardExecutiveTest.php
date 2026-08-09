<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dashboard ejecutivo: los KPIs con delta contra el periodo anterior y la
 * lista de los leads olvidados (abiertos sin tarea pendiente).
 */
class DashboardExecutiveTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Account $account;

    private Pipeline $pipeline;

    private PipelineStage $stage;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-20 12:00:00');

        $this->owner = User::create(['name' => 'Owner', 'email' => 'o@test.com', 'password' => bcrypt('secret')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);
        $this->owner->refresh();

        $this->pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas', 'is_default' => true]);
        $this->stage = PipelineStage::create(['pipeline_id' => $this->pipeline->id, 'name' => 'Nuevo', 'stage_type' => 'open', 'position' => 0]);
    }

    private function makeLead(string $createdAt, array $overrides = []): Lead
    {
        Carbon::setTestNow($createdAt);
        $lead = Lead::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stage->id,
            'title' => $overrides['title'] ?? 'Lead',
            'value' => $overrides['value'] ?? 100,
            'responsible_user_id' => $this->owner->id,
        ]);
        Carbon::setTestNow('2026-08-20 12:00:00');

        if (isset($overrides['closed_at'])) {
            $lead->update(['status' => $overrides['status'] ?? 'won', 'closed_at' => $overrides['closed_at']]);
        }

        return $lead->refresh();
    }

    /**
     * Props del dashboard.
     *
     * Desde T2 el tablero es por widgets: lo que antes vivía suelto en la raíz
     * (`stats`, `deltas`, `forgottenLeads`) ahora llega dentro del payload de
     * su widget. Las definiciones de cada métrica no cambiaron —los tests de
     * abajo son los mismos— solo dónde viaja el dato.
     */
    private function props(): array
    {
        $widgets = $this->actingAs($this->owner)->get('/dashboard')
            ->assertOk()
            ->viewData('page')['props']['widgets'];

        return [
            'stats' => $widgets['kpis']['stats'],
            'deltas' => $widgets['kpis']['deltas'],
            'forgottenLeads' => $widgets['forgotten_leads']['items'],
        ];
    }

    public function test_los_ganados_del_mes_se_comparan_con_el_mismo_tramo_del_mes_pasado(): void
    {
        // Mes actual a la fecha (1-20 ago): 2 ganados.
        $this->makeLead('2026-08-01 10:00:00', ['closed_at' => '2026-08-05 10:00:00']);
        $this->makeLead('2026-08-02 10:00:00', ['closed_at' => '2026-08-10 10:00:00']);
        // Mismo tramo del mes pasado (1-20 jul): 1 ganado.
        $this->makeLead('2026-07-01 10:00:00', ['closed_at' => '2026-07-05 10:00:00']);
        // Fuera del tramo comparable (25 jul): no entra en la base.
        $this->makeLead('2026-07-20 10:00:00', ['closed_at' => '2026-07-25 10:00:00']);

        $props = $this->props();

        $this->assertSame(2, $props['stats']['wonThisMonth']);
        $this->assertSame(100.0, $props['deltas']['wonThisMonth']);
    }

    public function test_los_abiertos_se_comparan_con_los_que_estaban_abiertos_hace_un_mes(): void
    {
        // Abierto desde junio: contaba hace un mes y sigue contando ahora.
        $this->makeLead('2026-06-01 10:00:00');
        // Abierto desde junio pero cerrado el 1 de agosto: contaba hace un mes
        // (20 de julio) y ya no cuenta ahora.
        $this->makeLead('2026-06-02 10:00:00', ['closed_at' => '2026-08-01 10:00:00']);
        // Nuevo de agosto: cuenta ahora, no hace un mes.
        $this->makeLead('2026-08-15 10:00:00');

        $props = $this->props();

        // Ahora 2 abiertos, hace un mes también 2 → sin variación.
        $this->assertSame(2, $props['stats']['openLeads']);
        $this->assertSame(0.0, $props['deltas']['openLeads']);
    }

    public function test_sin_base_de_comparacion_el_delta_va_en_null(): void
    {
        $this->makeLead('2026-08-15 10:00:00');

        $props = $this->props();

        // Nadie ganó nada el mes pasado: no hay porcentaje que calcular.
        $this->assertNull($props['deltas']['wonThisMonth']);
    }

    public function test_lista_los_5_leads_olvidados_mas_antiguos(): void
    {
        // 6 abiertos sin tarea; solo deben salir los 5 más antiguos.
        foreach (range(1, 6) as $i) {
            $this->makeLead('2026-08-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).' 10:00:00', ['title' => "Olvidado {$i}"]);
        }

        // Este tiene tarea pendiente: no es un olvidado.
        $conTarea = $this->makeLead('2026-07-01 10:00:00', ['title' => 'Con tarea']);
        Task::create([
            'account_id' => $this->account->id,
            'lead_id' => $conTarea->id,
            'assigned_to' => $this->owner->id,
            'text' => 'Llamar',
            'due_at' => now()->addDay(),
        ]);

        $props = $this->props();
        $forgotten = collect($props['forgottenLeads']);

        $this->assertSame(6, $props['stats']['leadsWithoutTask']);
        $this->assertCount(5, $forgotten);
        $this->assertSame('Olvidado 1', $forgotten->first()['title']);
        $this->assertSame(19, $forgotten->first()['days_open']);
        $this->assertNotContains('Con tarea', $forgotten->pluck('title')->all());
    }
}
