<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Supervision\ResponseMetrics;
use App\Services\Supervision\TeamComparison;
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

        $since = now()->subDays($days)->startOfDay();

        $metrics = (new ResponseMetrics($request->user()->account_id, $since))->build();

        // Comparativas de equipo: en clase aparte para no tocar el GEMELO.
        $comparison = (new TeamComparison($request->user()->account_id, $since))->build();

        return Inertia::render('Supervision/Index', [
            ...$metrics,
            'comparison' => $comparison,
            'days' => $days,
            'ranges' => self::RANGES,
            'members' => User::where('account_id', $request->user()->account_id)
                ->orderBy('name')
                ->get(['id', 'name', 'account_role']),
        ]);
    }

    /**
     * Ficha de un responsable: KPIs, histograma, embudo de sus leads abiertos
     * y los pendientes operativos de su bandeja.
     */
    public function show(Request $request, User $user): Response
    {
        abort_unless($user->account_id === $request->user()->account_id, 403,
            'El responsable no pertenece a tu cuenta.');

        $days = (int) $request->query('days', 30);

        if (! in_array($days, self::RANGES, true)) {
            $days = 30;
        }

        $since = now()->subDays($days)->startOfDay();

        $data = (new ResponseMetrics($request->user()->account_id, $since))->forResponsible($user->id);

        return Inertia::render('Supervision/Agent', [
            ...$data,
            // Promedio del equipo para superponerlo a las series del agente:
            // un número solo no dice si es lento, dice si es más lento.
            'teamDaily' => (new TeamComparison($request->user()->account_id, $since))->teamDailyAverage(),
            'days' => $days,
            'ranges' => self::RANGES,
            'sla_minutes' => ResponseMetrics::SLA_MINUTES,
        ]);
    }
}
