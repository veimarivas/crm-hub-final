<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Supervision\ResponseMetrics;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Panel de seguimiento del admin: cómo está atendiendo cada responsable.
 *
 * Complementa a Reportes (que mide resultado: ganados, conversión, embudo)
 * midiendo el *proceso*: quién contesta, en cuánto tiempo, qué contactos
 * quedaron esperando y en qué etapa está cada uno.
 *
 * Admin-only por la ruta (middleware admin.only): un agente no compara su
 * desempeño con el del resto.
 */
class SupervisionController extends Controller
{
    /** Ventanas ofrecidas en la UI, en días. */
    private const RANGES = [7, 15, 30, 90];

    public function index(Request $request): Response
    {
        $days = (int) $request->query('days', 30);

        if (! in_array($days, self::RANGES, true)) {
            $days = 30;
        }

        $metrics = (new ResponseMetrics(
            $request->user()->account_id,
            now()->subDays($days)->startOfDay(),
        ))->build();

        return Inertia::render('Supervision/Index', [
            ...$metrics,
            'days' => $days,
            'ranges' => self::RANGES,
            'members' => User::where('account_id', $request->user()->account_id)
                ->orderBy('name')
                ->get(['id', 'name', 'account_role']),
        ]);
    }
}
