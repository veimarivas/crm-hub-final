<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Avisos del admin al equipo: notas y recordatorios.
 *
 * La regla que sostiene todo: un recordatorio programado EXISTE desde que se
 * crea pero no se ve hasta su `deliver_at`. Si alguna lectura se saltea el
 * scope `delivered()`, el agente se entera antes de tiempo — por eso hay un
 * test por cada camino de lectura (campana, listado y acceso directo).
 */
class TeamMessagesTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private User $agente;

    private User $otro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Administrador', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        $this->agente = $this->makeAgent('daniel@test.com', 'Daniel');
        $this->otro = $this->makeAgent('silvia@test.com', 'Silvia');
    }

    private function makeAgent(string $email, string $name): User
    {
        return User::create([
            'name' => $name, 'email' => $email, 'password' => bcrypt('password'),
            'account_id' => $this->account->id, 'account_role' => User::ROLE_AGENT,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Revisar contactos sin responder',
            'body' => 'Quedaron tres de la semana pasada.',
            'category' => 'seguimiento',
            'user_ids' => [$this->agente->id],
        ], $overrides);
    }

    public function test_una_nota_individual_llega_con_su_apartado(): void
    {
        $this->actingAs($this->owner)
            ->post(route('team-messages.store'), $this->payload())
            ->assertRedirect();

        $notification = AppNotification::where('user_id', $this->agente->id)->sole();

        $this->assertSame('seguimiento', $notification->category);
        $this->assertSame(AppNotification::TYPE_TEAM_NOTE, $notification->type);
        $this->assertSame($this->owner->id, $notification->sent_by_user_id);
        $this->assertNull($notification->deliver_at, 'Una nota sin fecha se ve al instante.');
    }

    public function test_el_envio_masivo_crea_una_notificacion_por_destinatario(): void
    {
        $this->actingAs($this->owner)
            ->post(route('team-messages.store'), $this->payload([
                'category' => 'marketing',
                'user_ids' => [$this->agente->id, $this->otro->id],
            ]))
            ->assertRedirect();

        $this->assertSame(1, AppNotification::where('user_id', $this->agente->id)->count());
        $this->assertSame(1, AppNotification::where('user_id', $this->otro->id)->count());

        // Mismo batch: el historial los muestra como un solo envio.
        $this->assertCount(1, AppNotification::query()->distinct()->pluck('batch_id'));
    }

    public function test_el_recordatorio_programado_no_se_ve_antes_de_tiempo(): void
    {
        $this->actingAs($this->owner)->post(route('team-messages.store'), $this->payload([
            'deliver_at' => now()->addDay()->toDateTimeString(),
        ]))->assertRedirect();

        $notification = AppNotification::where('user_id', $this->agente->id)->sole();
        $this->assertSame(AppNotification::TYPE_TEAM_REMINDER, $notification->type);

        // 1. No cuenta en la campana.
        $response = $this->actingAs($this->agente)->get(route('dashboard'));
        $this->assertSame(0, $response->viewData('page')['props']['unreadNotifications']);

        // 2. No aparece en el listado.
        $listado = $this->actingAs($this->agente)->get(route('notifications'));
        $this->assertCount(0, $listado->viewData('page')['props']['notifications']['data']);

        // 3. No se puede abrir por URL directa.
        $this->actingAs($this->agente)->get(route('notifications.go', $notification))->assertNotFound();
    }

    public function test_el_recordatorio_aparece_cuando_llega_su_momento(): void
    {
        $this->actingAs($this->owner)->post(route('team-messages.store'), $this->payload([
            'deliver_at' => now()->addHours(2)->toDateTimeString(),
        ]))->assertRedirect();

        $this->travel(3)->hours();

        $response = $this->actingAs($this->agente)->get(route('notifications'));

        $this->assertCount(1, $response->viewData('page')['props']['notifications']['data']);
        $this->assertSame(1, $this->actingAs($this->agente)->get(route('dashboard'))
            ->viewData('page')['props']['unreadNotifications']);
    }

    public function test_marcar_todo_leido_no_consume_los_recordatorios_pendientes(): void
    {
        $this->actingAs($this->owner)->post(route('team-messages.store'), $this->payload([
            'deliver_at' => now()->addDay()->toDateTimeString(),
        ]))->assertRedirect();

        $this->actingAs($this->agente)->post(route('notifications.read-all'))->assertRedirect();

        // Si se marcara como leido ahora, el recordatorio nunca se veria.
        $this->assertNull(AppNotification::where('user_id', $this->agente->id)->sole()->read_at);
    }

    public function test_no_se_puede_avisar_a_alguien_de_otra_cuenta(): void
    {
        $ajeno = User::create(['name' => 'Ajeno', 'email' => 'ajeno@otra.com', 'password' => bcrypt('password')]);
        $otraCuenta = Account::create(['name' => 'Otra', 'owner_user_id' => $ajeno->id]);
        $ajeno->update(['account_id' => $otraCuenta->id, 'account_role' => User::ROLE_AGENT]);

        $this->actingAs($this->owner)
            ->post(route('team-messages.store'), $this->payload(['user_ids' => [$ajeno->id]]))
            ->assertSessionHasErrors('user_ids');

        $this->assertSame(0, AppNotification::where('user_id', $ajeno->id)->count());
    }

    public function test_el_apartado_es_obligatorio_y_acotado(): void
    {
        $this->actingAs($this->owner)
            ->post(route('team-messages.store'), $this->payload(['category' => 'ventas']))
            ->assertSessionHasErrors('category');
    }

    public function test_un_recordatorio_en_el_pasado_se_rechaza(): void
    {
        $this->actingAs($this->owner)
            ->post(route('team-messages.store'), $this->payload(['deliver_at' => now()->subHour()->toDateTimeString()]))
            ->assertSessionHasErrors('deliver_at');
    }

    public function test_solo_el_admin_puede_mandar_avisos(): void
    {
        $this->actingAs($this->agente)->get(route('team-messages.index'))->assertForbidden();
        $this->actingAs($this->agente)->post(route('team-messages.store'), $this->payload())->assertForbidden();
        $this->actingAs($this->owner)->get(route('team-messages.index'))->assertOk();
    }
}
