<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\DashboardWidget;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Dashboard\WidgetContext;
use App\Services\Dashboard\WidgetRegistry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T2 — dashboard por widgets.
 *
 * El test que da sentido a la tarea es
 * `test_no_se_calcula_el_widget_apagado`: la personalización no es solo
 * cosmética, es dejar de ejecutar consultas que nadie mira. Sin ese test,
 * cualquiera podría «optimizar» resolviendo todo de nuevo y nadie lo notaría.
 */
class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $agent;

    private Account $account;

    private Pipeline $pipeline;

    private $stages;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-08 12:00:00');

        $this->owner = User::create(['name' => 'Owner', 'email' => 'o@test.com', 'password' => bcrypt('secret'), 'email_verified_at' => now()]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => 'owner']);
        $this->owner->refresh();

        $this->agent = User::create([
            'name' => 'Agente', 'email' => 'a@test.com', 'password' => bcrypt('secret'),
            'account_id' => $this->account->id, 'account_role' => 'agent', 'email_verified_at' => now(),
        ]);

        $this->pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas', 'is_default' => true]);
        $this->stages = collect([
            ['name' => 'Nuevo', 'stage_type' => 'open'],
            ['name' => 'Ganado', 'stage_type' => 'won'],
        ])->map(fn ($s, $i) => PipelineStage::create([
            'pipeline_id' => $this->pipeline->id, 'position' => $i, 'color' => '#0ea5e9', ...$s,
        ]));
    }

    private function makeLead(array $overrides = []): Lead
    {
        $contact = Contact::create([
            'account_id' => $this->account->id, 'name' => 'Contacto',
            'phone' => '+5917000'.random_int(1000, 9999),
        ]);

        return Lead::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stages[0]->id,
            'contact_id' => $contact->id,
            'title' => 'Lead',
            'value' => 500,
            'source' => 'whatsapp',
            'status' => $overrides['status'] ?? 'open',
            'responsible_user_id' => $overrides['responsible_user_id'] ?? $this->owner->id,
        ]);
    }

    // ---- Layout por defecto ----

    public function test_sin_configuracion_se_usa_el_layout_por_defecto_del_rol(): void
    {
        $adminLayout = collect(app(WidgetRegistry::class)->layoutFor($this->owner, true))->pluck('widget_key');
        $agentLayout = collect(app(WidgetRegistry::class)->layoutFor($this->agent, false))->pluck('widget_key');

        $this->assertTrue($adminLayout->contains('team_ranking'));
        $this->assertFalse($agentLayout->contains('team_ranking'), 'Un agente no compara su desempeño con el del resto.');
    }

    public function test_el_dashboard_carga_con_el_layout_por_defecto(): void
    {
        $this->makeLead();

        $props = $this->actingAs($this->owner)->get('/dashboard')->viewData('page')['props'];

        $this->assertNotEmpty($props['layout']);
        $this->assertArrayHasKey('kpis', $props['widgets']);
        $this->assertSame(1, $props['widgets']['kpis']['stats']['openLeads']);
    }

    // ---- El criterio de aceptación de la tarea ----

    public function test_no_se_calcula_el_widget_apagado(): void
    {
        $this->makeLead();

        // Solo KPIs visible; el resto apagado.
        $this->saveLayout($this->owner, [
            ['widget_key' => 'kpis', 'size' => 'full', 'is_visible' => true],
            ['widget_key' => 'recent_leads', 'size' => 'lg', 'is_visible' => false],
            ['widget_key' => 'team_ranking', 'size' => 'md', 'is_visible' => false],
        ]);

        $queries = [];
        DB::listen(function ($q) use (&$queries) { $queries[] = $q->sql; });

        $props = $this->actingAs($this->owner)->get('/dashboard')->viewData('page')['props'];

        $this->assertArrayHasKey('kpis', $props['widgets']);
        $this->assertArrayNotHasKey('recent_leads', $props['widgets']);
        $this->assertArrayNotHasKey('team_ranking', $props['widgets']);

        // El ranking de equipo es el único que agrupa por responsable: si esa
        // consulta aparece, el widget apagado se calculó igual.
        $agrupaPorResponsable = collect($queries)->contains(fn ($sql) => str_contains($sql, 'responsible_user_id')
            && str_contains(strtolower($sql), 'group by'));

        $this->assertFalse($agrupaPorResponsable, 'Se ejecutó la consulta de un widget apagado.');
    }

    public function test_el_layout_apagado_igual_viaja_para_poder_reactivarlo(): void
    {
        $this->saveLayout($this->owner, [
            ['widget_key' => 'kpis', 'size' => 'full', 'is_visible' => true],
            ['widget_key' => 'my_tasks', 'size' => 'md', 'is_visible' => false],
        ]);

        $props = $this->actingAs($this->owner)->get('/dashboard')->viewData('page')['props'];

        $keys = collect($props['layout'])->pluck('widget_key');
        $this->assertTrue($keys->contains('my_tasks'), 'Sin esto el usuario no podría volver a prenderlo.');
        $this->assertArrayNotHasKey('my_tasks', $props['widgets'], 'Pero sin datos.');
    }

    // ---- Guardado y corte de rol ----

    public function test_guardar_el_layout_persiste_orden_tamano_y_visibilidad(): void
    {
        $this->saveLayout($this->owner, [
            ['widget_key' => 'my_tasks', 'size' => 'lg', 'is_visible' => true],
            ['widget_key' => 'kpis', 'size' => 'full', 'is_visible' => false],
        ]);

        $rows = DashboardWidget::where('user_id', $this->owner->id)->orderBy('position')->get();

        $this->assertSame('my_tasks', $rows[0]->widget_key);
        $this->assertSame('lg', $rows[0]->size);
        $this->assertSame('kpis', $rows[1]->widget_key);
        $this->assertFalse($rows[1]->is_visible);
    }

    public function test_el_layout_es_por_usuario_y_no_por_cuenta(): void
    {
        $this->saveLayout($this->owner, [['widget_key' => 'my_tasks', 'size' => 'md', 'is_visible' => true]]);

        // El agente sigue con el default: acomodar el propio tablero no le
        // mueve el tablero a nadie más.
        $this->assertSame(0, DashboardWidget::where('user_id', $this->agent->id)->count());

        $props = $this->actingAs($this->agent)->get('/dashboard')->viewData('page')['props'];
        $this->assertGreaterThan(1, count($props['layout']));
    }

    public function test_un_agente_no_puede_activarse_un_widget_de_admin(): void
    {
        $this->actingAs($this->agent)->patch(route('dashboard.layout'), [
            'widgets' => [
                ['widget_key' => 'kpis', 'size' => 'full', 'is_visible' => true],
                ['widget_key' => 'team_ranking', 'size' => 'md', 'is_visible' => true],
            ],
        ])->assertRedirect();

        $keys = DashboardWidget::where('user_id', $this->agent->id)->pluck('widget_key');

        $this->assertFalse($keys->contains('team_ranking'), 'El corte de rol va en el servidor, no en la pantalla.');
    }

    public function test_un_widget_inventado_es_rechazado(): void
    {
        $this->actingAs($this->owner)->patch(route('dashboard.layout'), [
            'widgets' => [['widget_key' => 'inventado', 'size' => 'md', 'is_visible' => true]],
        ])->assertSessionHasErrors('widgets.0.widget_key');
    }

    public function test_el_registro_rechaza_resolver_un_widget_de_admin_para_un_agente(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(WidgetRegistry::class)->resolve('team_ranking', WidgetContext::for($this->agent, 30));
    }

    public function test_restaurar_borra_la_configuracion_y_vuelve_al_default(): void
    {
        $this->saveLayout($this->owner, [['widget_key' => 'my_tasks', 'size' => 'md', 'is_visible' => true]]);

        $this->actingAs($this->owner)->delete(route('dashboard.layout.reset'))->assertRedirect();

        $this->assertSame(0, DashboardWidget::where('user_id', $this->owner->id)->count());

        $props = $this->actingAs($this->owner)->get('/dashboard')->viewData('page')['props'];
        $this->assertGreaterThan(1, count($props['layout']));
    }

    // ---- Scope de rol en los datos ----

    public function test_el_agente_solo_ve_sus_numeros(): void
    {
        $this->makeLead(['responsible_user_id' => $this->owner->id]);
        $this->makeLead(['responsible_user_id' => $this->agent->id]);

        $props = $this->actingAs($this->agent)->get('/dashboard')->viewData('page')['props'];

        $this->assertSame(1, $props['widgets']['kpis']['stats']['openLeads']);
        $this->assertArrayNotHasKey('team_ranking', $props['widgets']);
    }

    /** @param array<int, array<string, mixed>> $widgets */
    private function saveLayout(User $user, array $widgets): void
    {
        $this->actingAs($user)->patch(route('dashboard.layout'), ['widgets' => $widgets])->assertRedirect();
    }
}
