<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `POST /api/v1/team/provision` — el wacrm da de alta acá a los miembros que
 * se crean allá. Sin este endpoint el puente era de ida solamente y un
 * miembro del wacrm no aparecía para asignarle contactos.
 */
class TeamProvisionApiTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        [, $this->key] = ApiKey::issue($this->account->id, $this->owner->id, 'wacrm', ['team:write']);
    }

    private function provision(array $payload): TestResponse
    {
        return $this->withToken($this->key)->postJson('/api/v1/team/provision', $payload);
    }

    public function test_crea_el_miembro_en_la_cuenta(): void
    {
        $this->provision([
            'name' => 'Daniel',
            'email' => 'daniel@test.com',
            'password' => 'secretpass123',
            'role' => 'agent',
        ])->assertCreated()->assertJsonPath('created', true);

        $member = User::where('email', 'daniel@test.com')->sole();

        $this->assertSame($this->account->id, $member->account_id);
        $this->assertSame(User::ROLE_AGENT, $member->account_role);
        // Puede iniciar sesión con la clave que se mandó desde el wacrm.
        $this->assertTrue(Hash::check('secretpass123', $member->password));
    }

    public function test_es_idempotente_y_no_pisa_el_password(): void
    {
        $this->provision(['name' => 'Daniel', 'email' => 'daniel@test.com', 'password' => 'secretpass123']);

        // El miembro entró y cambió su clave.
        $member = User::where('email', 'daniel@test.com')->sole();
        $member->update(['password' => Hash::make('la-mia-nueva')]);

        $this->provision(['name' => 'Daniel Pérez', 'email' => 'daniel@test.com', 'password' => 'secretpass123'])
            ->assertOk()
            ->assertJsonPath('created', false);

        $member->refresh();
        $this->assertSame('Daniel Pérez', $member->name, 'El nombre sí se actualiza.');
        $this->assertTrue(Hash::check('la-mia-nueva', $member->password), 'Su clave no se revierte.');
        $this->assertSame(1, User::where('email', 'daniel@test.com')->count());
    }

    public function test_sin_password_queda_uno_aleatorio_no_adivinable(): void
    {
        $this->provision(['name' => 'Daniel', 'email' => 'daniel@test.com'])->assertCreated();

        $member = User::where('email', 'daniel@test.com')->sole();

        $this->assertFalse(Hash::check('', $member->password));
        $this->assertFalse(Hash::check('daniel@test.com', $member->password));
    }

    public function test_un_email_de_otra_cuenta_da_409(): void
    {
        $ajeno = User::create(['name' => 'Ajeno', 'email' => 'ajeno@otra.com', 'password' => bcrypt('password')]);
        $otra = Account::create(['name' => 'Otra', 'owner_user_id' => $ajeno->id]);
        $ajeno->update(['account_id' => $otra->id, 'account_role' => User::ROLE_OWNER]);

        $this->provision(['name' => 'Ajeno', 'email' => 'ajeno@otra.com'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'email_in_other_account');

        $this->assertSame($otra->id, $ajeno->fresh()->account_id, 'No se roban usuarios entre cuentas.');
    }

    public function test_exige_el_scope_team_write(): void
    {
        [, $sinScope] = ApiKey::issue($this->account->id, $this->owner->id, 'otra', ['contacts:read']);

        $this->withToken($sinScope)
            ->postJson('/api/v1/team/provision', ['name' => 'Daniel', 'email' => 'daniel@test.com'])
            ->assertForbidden();

        // Sin clave tampoco pasa.
        $this->postJson('/api/v1/team/provision', ['name' => 'Daniel', 'email' => 'daniel@test.com'])
            ->assertForbidden();
    }
}
