<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pestañas y filtros de /notifications.
 *
 * El filtrado va en el servidor, no en el cliente: con paginación, filtrar
 * la página ya traída deja pestañas vacías aunque haya resultados en la
 * página siguiente.
 */
class NotificationTabsTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Daniel', 'email' => 'daniel@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->user->id]);
        $this->user->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_AGENT]);
    }

    private function make(array $attrs = []): AppNotification
    {
        return AppNotification::create(array_merge([
            'account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'type' => AppNotification::TYPE_TEAM_NOTE,
            'title' => 'Aviso',
        ], $attrs));
    }

    private function props(array $query = []): array
    {
        return $this->actingAs($this->user)
            ->get(route('notifications', $query))
            ->viewData('page')['props'];
    }

    public function test_las_pestanas_separan_nuevas_de_leidas(): void
    {
        $this->make(['title' => 'Nueva']);
        $this->make(['title' => 'Leida', 'read_at' => now()]);

        $this->assertSame(['all' => 2, 'unread' => 1, 'read' => 1], $this->props()['counts']);

        $unread = $this->props(['tab' => 'unread'])['notifications']['data'];
        $this->assertCount(1, $unread);
        $this->assertSame('Nueva', $unread[0]['title']);

        $read = $this->props(['tab' => 'read'])['notifications']['data'];
        $this->assertCount(1, $read);
        $this->assertSame('Leida', $read[0]['title']);
    }

    public function test_el_filtro_por_apartado_se_combina_con_la_pestana(): void
    {
        $this->make(['title' => 'Seg nueva', 'category' => 'seguimiento']);
        $this->make(['title' => 'Seg leida', 'category' => 'seguimiento', 'read_at' => now()]);
        $this->make(['title' => 'Mkt nueva', 'category' => 'marketing']);

        $props = $this->props(['tab' => 'unread', 'category' => 'seguimiento']);

        $this->assertCount(1, $props['notifications']['data']);
        $this->assertSame('Seg nueva', $props['notifications']['data'][0]['title']);

        // Los contadores por apartado se cuentan dentro de la pestaña activa:
        // en "nuevas" hay 1 de seguimiento, no 2.
        $this->assertSame(1, $props['categoryCounts']['seguimiento']);
        $this->assertSame(1, $props['categoryCounts']['marketing']);
        $this->assertSame(0, $props['categoryCounts']['personal']);
    }

    public function test_los_recordatorios_pendientes_no_cuentan_en_ninguna_pestana(): void
    {
        $this->make(['title' => 'Visible']);
        $this->make(['title' => 'Programada', 'deliver_at' => now()->addDay()]);

        $counts = $this->props()['counts'];

        $this->assertSame(1, $counts['all']);
        $this->assertSame(1, $counts['unread']);
        $this->assertCount(1, $this->props(['tab' => 'unread'])['notifications']['data']);
    }

    public function test_un_tab_invalido_cae_a_todas(): void
    {
        $this->make();

        $this->assertSame('all', $this->props(['tab' => 'archivadas'])['tab']);
        $this->assertNull($this->props(['category' => 'ventas'])['category']);
    }

    public function test_se_puede_marcar_y_desmarcar_una_sola(): void
    {
        $n = $this->make();

        $this->actingAs($this->user)->post(route('notifications.read', $n))->assertRedirect();
        $this->assertNotNull($n->fresh()->read_at);

        // El mismo botón la devuelve a "nueva".
        $this->actingAs($this->user)->post(route('notifications.read', $n))->assertRedirect();
        $this->assertNull($n->fresh()->read_at);
    }

    public function test_no_se_puede_marcar_la_notificacion_de_otro(): void
    {
        $otro = User::create([
            'name' => 'Silvia', 'email' => 'silvia@test.com', 'password' => bcrypt('password'),
            'account_id' => $this->account->id, 'account_role' => User::ROLE_AGENT,
        ]);

        $n = $this->make(['user_id' => $otro->id]);

        $this->actingAs($this->user)->post(route('notifications.read', $n))->assertForbidden();
        $this->assertNull($n->fresh()->read_at);
    }

    public function test_no_se_puede_marcar_un_recordatorio_que_todavia_no_llego(): void
    {
        $n = $this->make(['deliver_at' => now()->addDay()]);

        $this->actingAs($this->user)->post(route('notifications.read', $n))->assertNotFound();
        $this->assertNull($n->fresh()->read_at);
    }
}
