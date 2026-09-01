<?php

namespace Tests;

use App\Models\Integration;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /**
     * Integración con el wacrm + un alta de broadcast que siempre acepta la
     * audiencia entera.
     *
     * Desde D1b los envíos masivos los hace el wacrm, así que cualquier test
     * que llegue a `broadcasts.store` necesita las dos cosas. Lo que esos tests
     * miden es la SELECCIÓN de destinatarios (el corte por rol, los filtros, la
     * intersección con lo tildado a mano) — no el envío, que se prueba del otro
     * lado. Por eso el doble acepta todo: si recortara, estaría midiendo la
     * regla equivocada.
     *
     * El informe se arma desde el propio pedido para que `total_recipients`
     * refleje lo que el test mandó y no un número fijo.
     */
    protected function fakeWacrmBroadcasts(string $accountId): void
    {
        Integration::firstOrCreate(
            ['account_id' => $accountId],
            [
                'wacrm_url' => 'https://wacrm.test',
                'wacrm_api_key' => 'komo_live_test',
                'is_active' => true,
            ],
        );

        Http::fake([
            '*/api/v1/broadcasts' => function ($request) {
                $total = count($request->data()['recipients'] ?? []);

                return Http::response([
                    'id' => (string) Str::uuid(),
                    'status' => 'sending',
                    'report' => [
                        'requested' => $total,
                        'unknown_contacts' => 0,
                        'out_of_window' => 0,
                        'sending_to' => $total,
                        'excluded' => [],
                        'excluded_truncated' => false,
                    ],
                ], 201);
            },
        ]);
    }
}
