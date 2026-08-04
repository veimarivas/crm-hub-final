<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Integration;
use App\Models\User;
use App\Services\Wacrm\AiStatusProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Indicador de IA del header.
 *
 * Todo fallo se pintaba igual, «Sin conexión», y eso manda a revisar la red
 * cuando lo más común es una API key sin el scope. Cada motivo tiene un
 * arreglo distinto, así que el indicador tiene que distinguirlos.
 */
class AiStatusProbeTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        $this->integration = Integration::create([
            'account_id' => $this->account->id,
            'wacrm_url' => 'https://wacrm.test',
            'wacrm_api_key' => 'wacrm_live_test',
        ]);

        Cache::flush();
    }

    private function probe(): AiStatusProbe
    {
        return app(AiStatusProbe::class);
    }

    public function test_una_key_sin_scope_no_se_reporta_como_sin_conexion(): void
    {
        Http::fake(['*/ai/status' => Http::response(['message' => 'Missing scope'], 403)]);

        $status = $this->probe()->probe($this->integration);

        $this->assertSame('unauthorized', $status['reason']);
        $this->assertSame(403, $status['http_status']);
        $this->assertFalse($status['available']);
    }

    public function test_un_wacrm_sin_el_endpoint_se_distingue_del_caido(): void
    {
        Http::fake(['*/ai/status' => Http::response('', 404)]);

        $this->assertSame('not_supported', $this->probe()->probe($this->integration)['reason']);
    }

    public function test_si_no_se_llega_al_servidor_dice_sin_conexion(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timeout'));

        $status = $this->probe()->probe($this->integration);

        $this->assertSame('unreachable', $status['reason']);
        $this->assertStringContainsString('timeout', $status['detail']);
    }

    public function test_el_estado_bueno_pasa_tal_cual(): void
    {
        Http::fake(['*/ai/status' => Http::response([
            'configured' => true, 'available' => true, 'reason' => null,
            'provider' => 'ollama', 'model' => 'qwen2.5:7b',
        ])]);

        $status = $this->probe()->forAccount($this->account->id);

        $this->assertTrue($status['available']);
        $this->assertSame('qwen2.5:7b', $status['model']);
    }

    public function test_un_fallo_se_recuerda_poco_para_que_el_indicador_vuelva_a_verde_solo(): void
    {
        // Secuencia y no dos `fake()`: los stubs se acumulan y gana el primero
        // que coincide, así que el segundo `fake()` nunca llegaría a usarse.
        Http::fake(['*/ai/status' => Http::sequence()
            ->push('', 500)
            ->push(['configured' => true, 'available' => true], 200)]);

        $this->assertFalse($this->probe()->forAccount($this->account->id)['available']);

        // Con el caché de 2 min de un estado bueno, un corte de 10 segundos se
        // veía como caída durante dos minutos. El fallo se recuerda 30s.
        $this->travel(31)->seconds();

        $this->assertTrue($this->probe()->forAccount($this->account->id)['available']);
    }

    public function test_sin_integracion_no_se_muestra_nada(): void
    {
        $this->integration->delete();

        $this->assertNull($this->probe()->forAccount($this->account->id));
    }
}
