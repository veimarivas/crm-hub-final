<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /** Ventanas permitidas para el selector ?days= (deben estar en WindowPicker). */
    private const ALLOWED_DAYS = [7, 15, 30, 90];

    /** Paleta del donut de ingresos por fuente (semántica de marca). */
    private const DONUT_COLORS = ['#045474', '#10b981', '#3b82f6', '#8b5cf6', '#ec4899', '#f59e0b', '#64748b'];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $accountId = $user->account_id;
        $isAdmin = $user->hasRoleAtLeast(User::ROLE_ADMIN);

        // Scope Lead: admin ve todo, agent solo lo asignado a él.
        $leadScope = fn ($q) => $isAdmin ? $q : $q->where('responsible_user_id', $user->id);

        // Ventana del reporte (?days=; default 30). Aplica a series y KPIs; el
        // embudo y el ranking de equipo siempre muestran el estado actual
        // (leads abiertos hoy), no de la ventana.
        $days = $this->resolveDays($request);
        $periodStart = now()->subDays($days);

        $pipelines = Pipeline::forAccount($accountId)->with('stages')->get();
        $selected = $pipelines->firstWhere('id', $request->query('pipeline'))
            ?? $pipelines->firstWhere('is_default', true)
            ?? $pipelines->first();

        // ---- KPIs de la ventana + delta vs el periodo anterior ----
        $current = $this->periodStats($accountId, $leadScope, $periodStart, now());
        $previous = $this->periodStats($accountId, $leadScope, $periodStart->copy()->subDays($days), $periodStart);

        $kpi = [
            [
                'key' => 'won', 'label' => 'Ganados', 'value' => $current['won'],
                'delta' => $this->delta($current['won'], $previous['won']), 'pp' => false,
            ],
            [
                'key' => 'created', 'label' => 'Leads nuevos', 'value' => $current['created'],
                'delta' => $this->delta($current['created'], $previous['created']), 'pp' => false,
            ],
            [
                'key' => 'rate', 'label' => 'Conversión', 'value' => $current['rate'], 'suffix' => '%',
                'delta' => $previous['rate'] ? round($current['rate'] - $previous['rate'], 1) : null, 'pp' => true,
            ],
            [
                'key' => 'invoiced', 'label' => 'Facturado', 'value' => $current['invoiced'], 'money' => true,
                'delta' => $this->delta($current['invoiced'], $previous['invoiced']), 'pp' => false,
            ],
        ];

        // ---- Tendencia semanal (6 meses): creados + ganados/perdidos ----
        $weekly = $this->weeklySeries($accountId, $leadScope);

        // ---- Embudo: etapas abiertas en orden + ganado. El % de paso entre
        // etapas lo calcula FunnelSteps en el cliente (no hay N+1 aquí).
        $openSteps = $selected?->stages->where('stage_type', 'open')->sortBy('position')->values() ?? collect();
        $wonStage = $selected?->stages->firstWhere('stage_type', 'won');

        $funnel = $openSteps->map(fn ($stage) => [
            'id' => $stage->id,
            'name' => $stage->name,
            'color' => $stage->color,
            'count' => $leadScope(Lead::where('stage_id', $stage->id)->where('status', 'open'))->count(),
            'value' => (float) $leadScope(Lead::where('stage_id', $stage->id)->where('status', 'open'))->sum('value'),
        ]);

        if ($wonStage) {
            $funnel->push([
                'id' => $wonStage->id,
                'name' => 'Ganado',
                'color' => '#10b981',
                'count' => $leadScope(Lead::where('stage_id', $wonStage->id)->where('status', 'won'))->count(),
                'value' => (float) $leadScope(Lead::where('stage_id', $wonStage->id)->where('status', 'won'))->sum('value'),
            ]);
        }

        // ---- Ganados / perdidos por mes (últimos 6). Alimenta la serie de
        // revenue mensual (wonValue) del gráfico de Facturado.
        $monthly = collect(range(5, 0))->map(function ($monthsAgo) use ($accountId, $leadScope) {
            $start = now()->subMonths($monthsAgo)->startOfMonth();
            $end = $start->copy()->endOfMonth();

            $base = fn (string $status) => $leadScope(Lead::forAccount($accountId)
                ->where('status', $status)
                ->whereBetween('closed_at', [$start, $end]));

            return [
                'month' => $start->translatedFormat('M Y'),
                'won' => $base('won')->count(),
                'wonValue' => (float) $base('won')->sum('value'),
                'lost' => $base('lost')->count(),
            ];
        });

        // ---- Ranking del equipo: solo admin lo ve; agent no compara con otros.
        // `stages` (abiertos por etapa) alimenta el CompareBars apilado.
        $stageColumns = $pipelines
            ->flatMap(fn ($p) => $p->stages->where('stage_type', 'open')->values())
            ->unique('id')
            ->values()
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'color' => $s->color])
            ->values();

        $byUser = $isAdmin
            ? User::where('account_id', $accountId)
                ->get(['id', 'name'])
                ->map(function ($u) use ($accountId, $stageColumns) {
                    $openByStage = Lead::forAccount($accountId)
                        ->where('status', 'open')
                        ->where('responsible_user_id', $u->id)
                        ->selectRaw('stage_id, count(*) as total')
                        ->groupBy('stage_id')
                        ->pluck('total', 'stage_id');

                    return [
                        'id' => $u->id,
                        'name' => $u->name,
                        'won' => Lead::forAccount($accountId)->where('status', 'won')
                            ->where('responsible_user_id', $u->id)
                            ->where('closed_at', '>=', now()->startOfMonth())->count(),
                        'wonValue' => (float) Lead::forAccount($accountId)->where('status', 'won')
                            ->where('responsible_user_id', $u->id)
                            ->where('closed_at', '>=', now()->startOfMonth())->sum('value'),
                        'open' => Lead::forAccount($accountId)->where('status', 'open')
                            ->where('responsible_user_id', $u->id)->count(),
                        'stages' => $stageColumns
                            ->mapWithKeys(fn ($s) => [$s['name'] => (int) ($openByStage[$s['id']] ?? 0)])
                            ->all(),
                    ];
                })
                ->sortByDesc('wonValue')
                ->values()
            : collect();

        $stageNames = $stageColumns->pluck('name')->all();
        $stageColors = $stageColumns->pluck('color', 'name')->all();

        // Datos para el CompareBars apilado (admin): una fila por vendedor.
        $teamStages = $isAdmin
            ? $byUser->filter(fn ($u) => $u['open'] > 0)
                ->map(fn ($u) => collect(['name' => $u['name']])->merge($u['stages'])->all())
                ->values()
            : collect();

        // ---- Fuentes (tabla de conversión + donut de ingresos) ----
        $sourceLabels = [
            'whatsapp' => 'WhatsApp',
            'booking' => 'Formulario de reserva',
            'lead_ad' => 'Meta Lead Ad',
            'web_form' => 'Formulario web',
            'manual' => 'Manual',
            'api' => 'API externa',
            'email' => 'Correo',
        ];

        $bySource = collect(array_keys($sourceLabels))->push(null)->map(function ($source) use ($accountId, $leadScope, $sourceLabels) {
            $base = fn () => $leadScope(Lead::forAccount($accountId)
                ->when($source === null, fn ($q) => $q->whereNull('source')->orWhere('source', ''))
                ->when($source !== null, fn ($q) => $q->where('source', $source)));

            return [
                'source' => $source ?? 'other',
                'label' => $source ? $sourceLabels[$source] : 'Sin fuente',
                'total' => $base()->count(),
                'won' => (clone $base())->where('status', 'won')->count(),
                'lost' => (clone $base())->where('status', 'lost')->count(),
                'open' => (clone $base())->where('status', 'open')->count(),
                'won_value' => (float) (clone $base())->where('status', 'won')->sum('value'),
                'conversion_rate' => null, // rellenado abajo
            ];
        })->map(function ($s) {
            $closed = $s['won'] + $s['lost'];
            $s['conversion_rate'] = $closed > 0 ? round($s['won'] / $closed * 100, 1) : 0;

            return $s;
        })->filter(fn ($s) => $s['total'] > 0)->sortByDesc('total')->values();

        // Donut: ingresos (won_value) por fuente. Clic → /leads?source=X.
        $sourceRevenue = $bySource
            ->filter(fn ($s) => $s['won_value'] > 0)
            ->values()
            ->map(fn ($s, $i) => [
                'name' => $s['label'],
                'value' => (float) $s['won_value'],
                'sourceKey' => $s['source'],
                'color' => self::DONUT_COLORS[$i % count(self::DONUT_COLORS)],
            ])->sortByDesc('value')->values();

        // Canales de marketing: propios + utm_source (first-touch).
        $utmSourceRows = $leadScope(Lead::forAccount($accountId))
            ->whereNotIn('source', ['whatsapp', 'booking'])
            ->selectRaw("utm_source, count(*) as total,
                sum(case when status='won' then 1 else 0 end) as won,
                sum(case when status='lost' then 1 else 0 end) as lost,
                sum(case when status='open' then 1 else 0 end) as open_count,
                sum(case when status='won' then value else 0 end) as won_value")
            ->groupBy('utm_source')
            ->orderByDesc('total')
            ->limit(12)
            ->get()
            ->map(fn ($r) => [
                'label' => $r->utm_source ?: '(direct)',
                'total' => (int) $r->total,
                'won' => (int) $r->won,
                'lost' => (int) $r->lost,
                'open' => (int) $r->open_count,
                'won_value' => (float) $r->won_value,
                'conversion_rate' => ($r->won + $r->lost) > 0
                    ? round(($r->won / ($r->won + $r->lost)) * 100, 1) : 0,
            ])->values();

        $propioRows = $leadScope(Lead::forAccount($accountId))
            ->whereIn('source', ['whatsapp', 'booking'])
            ->selectRaw("source, count(*) as total,
                sum(case when status='won' then 1 else 0 end) as won,
                sum(case when status='lost' then 1 else 0 end) as lost,
                sum(case when status='open' then 1 else 0 end) as open_count,
                sum(case when status='won' then value else 0 end) as won_value")
            ->groupBy('source')
            ->get()
            ->map(fn ($r) => [
                'label' => $r->source === 'booking' ? 'Formulario de reserva' : 'WhatsApp',
                'total' => (int) $r->total,
                'won' => (int) $r->won,
                'lost' => (int) $r->lost,
                'open' => (int) $r->open_count,
                'won_value' => (float) $r->won_value,
                'conversion_rate' => ($r->won + $r->lost) > 0
                    ? round(($r->won / ($r->won + $r->lost)) * 100, 1) : 0,
            ])
            ->filter(fn ($r) => $r['total'] > 0)
            ->values();

        $marketingRows = $propioRows->concat($utmSourceRows)->values();

        // Top campañas (utm_campaign).
        $utmCampaignRows = $leadScope(Lead::forAccount($accountId))
            ->whereNotNull('utm_campaign')
            ->where('utm_campaign', '!=', '')
            ->selectRaw("utm_campaign, utm_source, count(*) as total,
                sum(case when status='won' then 1 else 0 end) as won,
                sum(case when status='lost' then 1 else 0 end) as lost,
                sum(case when status='won' then value else 0 end) as won_value")
            ->groupBy('utm_campaign', 'utm_source')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'label' => $r->utm_campaign,
                'source' => $r->utm_source ?: '(sin fuente)',
                'total' => (int) $r->total,
                'won' => (int) $r->won,
                'lost' => (int) $r->lost,
                'won_value' => (float) $r->won_value,
                'conversion_rate' => ($r->won + $r->lost) > 0
                    ? round(($r->won / ($r->won + $r->lost)) * 100, 1) : 0,
            ])->values();

        // ---- Revenue real (Invoice): facturado vs cobrado en la ventana ----
        $revRow = $leadScope(Lead::forAccount($accountId))
            ->where('status', 'won')
            ->whereBetween('closed_at', [$periodStart, now()])
            ->selectRaw('COALESCE(SUM(invoiced_cents),0) as inv, COALESCE(SUM(collected_cents),0) as col')
            ->first();

        $revenue = [
            'invoiced' => (float) round($revRow->inv / 100, 2),
            'collected' => (float) round($revRow->col / 100, 2),
            'monthly' => $monthly->map(fn ($m) => ['label' => $m['month'], 'revenue' => (float) $m['wonValue'], 'won' => (int) $m['won'], 'lost' => (int) $m['lost']])->values(),
        ];

        // ---- Histograma: días entre creado y cerrado (ganados + perdidos) ----
        $closeTime = $this->closeTimeHistogram($accountId, $leadScope, $periodStart);

        $totalWon = $leadScope(Lead::forAccount($accountId)->where('status', 'won'))->count();
        $totalLost = $leadScope(Lead::forAccount($accountId)->where('status', 'lost'))->count();
        $countWonWindow = $current['won'];

        return Inertia::render('Reports/Index', [
            'pipelines' => $pipelines->map(fn ($p) => ['id' => $p->id, 'name' => $p->name]),
            'pipelineId' => $selected?->id,
            'days' => $days,
            'kpi' => $kpi,
            'weekly' => $weekly,
            'funnel' => $funnel,
            'monthly' => $monthly,
            'byUser' => $byUser,
            'teamStages' => $teamStages,
            'stageNames' => $stageNames,
            'stageColors' => $stageColors,
            'conversion' => [
                'won' => $totalWon,
                'lost' => $totalLost,
                'rate' => ($totalWon + $totalLost) > 0 ? round($totalWon / ($totalWon + $totalLost) * 100) : 0,
                'avgTicket' => (float) ($totalWon > 0
                    ? $leadScope(Lead::forAccount($accountId)->where('status', 'won'))->avg('value')
                    : 0),
            ],
            'isAdmin' => $isAdmin,
            'bySource' => $bySource,
            'byUtmSource' => $marketingRows,
            'byUtmCampaign' => $utmCampaignRows,
            'sourceRevenue' => $sourceRevenue,
            'revenue' => $revenue,
            'closeTime' => $closeTime,
            'currency' => $user->account->default_currency,
        ]);
    }

    /** Ventana válida desde el query (?days=). */
    private function resolveDays(Request $request): int
    {
        $days = (int) $request->query('days', 30);

        return in_array($days, self::ALLOWED_DAYS, true) ? $days : 30;
    }

    /** Métricas de una ventana de tiempo (usada para KPIs y su delta). */
    private function periodStats(
        string $accountId,
        $leadScope,
        \Carbon\CarbonInterface $from,
        \Carbon\CarbonInterface $to,
    ): array {
        $closedBase = fn (string $status) => $leadScope(Lead::forAccount($accountId))
            ->where('status', $status)
            ->whereBetween('closed_at', [$from, $to]);

        $won = $closedBase('won')->count();
        $lost = $closedBase('lost')->count();

        $rev = $leadScope(Lead::forAccount($accountId))
            ->where('status', 'won')
            ->whereBetween('closed_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(invoiced_cents),0) as inv, COALESCE(SUM(collected_cents),0) as col')
            ->first();

        return [
            'created' => (int) $leadScope(Lead::forAccount($accountId)->whereBetween('created_at', [$from, $to]))->count(),
            'won' => $won,
            'lost' => $lost,
            'wonValue' => (float) $closedBase('won')->sum('value'),
            'avgTicket' => $won > 0 ? (float) $closedBase('won')->avg('value') : 0,
            'invoiced' => (float) round($rev->inv / 100, 2),
            'collected' => (float) round($rev->col / 100, 2),
            'rate' => ($won + $lost) > 0 ? round($won / ($won + $lost) * 100, 1) : 0,
        ];
    }

    private function delta(int|float $current, int|float $previous): ?float
    {
        if ($previous == 0) {
            return null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    /** Series semanales (26 semanas ≈ 6 meses): creados / ganados / perdidos. */
    private function weeklySeries(string $accountId, $leadScope): array
    {
        $weekStart = now()->subWeeks(25)->startOfWeek();

        // `%x-%v` = año/semana ISO (lunes como primer día), igual que
        // Carbon::startOfWeek()/isoWeek(); con `%X-%V` (domingo) las claves
        // no coinciden y todas las series salen en cero.
        $grouped = fn ($q, string $field) => $q
            ->selectRaw("DATE_FORMAT(`{$field}`, '%x-%v') as wk, count(*) as n")
            ->groupBy('wk')
            ->pluck('n', 'wk');

        $created = $grouped($leadScope(Lead::forAccount($accountId)->where('created_at', '>=', $weekStart)), 'created_at');
        $won = $grouped($leadScope(Lead::forAccount($accountId)->where('status', 'won')->whereNotNull('closed_at')->where('closed_at', '>=', $weekStart)), 'closed_at');
        $lost = $grouped($leadScope(Lead::forAccount($accountId)->where('status', 'lost')->whereNotNull('closed_at')->where('closed_at', '>=', $weekStart)), 'closed_at');

        return collect(range(25, 0))->map(function ($i) use ($created, $won, $lost) {
            $week = now()->subWeeks($i)->startOfWeek();
            $key = $week->isoFormat('GGGG').'-'.sprintf('%02d', $week->isoWeek());

            return [
                'label' => $week->format('d/m'),
                'created' => (int) ($created[$key] ?? 0),
                'won' => (int) ($won[$key] ?? 0),
                'lost' => (int) ($lost[$key] ?? 0),
            ];
        })->values()->all();
    }

    /** Histograma de días hasta el cierre dentro de la ventana. */
    private function closeTimeHistogram(string $accountId, $leadScope, string $from): array
    {
        $durations = $leadScope(Lead::forAccount($accountId))
            ->whereIn('status', ['won', 'lost'])
            ->whereNotNull('closed_at')
            ->where('closed_at', '>=', $from)
            ->selectRaw('TIMESTAMPDIFF(DAY, created_at, closed_at) as days')
            ->pluck('days');

        $buckets = [
            ['name' => '< 1 d', 'min' => 0, 'max' => 0],
            ['name' => '1–3 d', 'min' => 1, 'max' => 3],
            ['name' => '3–7 d', 'min' => 4, 'max' => 7],
            ['name' => '7–14 d', 'min' => 8, 'max' => 14],
            ['name' => '14–30 d', 'min' => 15, 'max' => 30],
            ['name' => '> 30 d', 'min' => 31, 'max' => null],
        ];
        $colors = ['#10b981', '#34d399', '#f59e0b', '#f97316', '#e11d48', '#9f1239'];

        return collect($buckets)
            ->map(fn ($bucket, $i) => [
                'name' => $bucket['name'],
                'value' => $durations->filter(function ($days) use ($bucket) {
                    if ($bucket['max'] === null) {
                        return $days >= $bucket['min'];
                    }

                    return $days >= $bucket['min'] && $days <= $bucket['max'];
                })->count(),
                'color' => $colors[$i],
            ])
            ->filter(fn ($b) => $b['value'] > 0)
            ->values()
            ->all();
    }

    /** Del mismo modo que en index, agrega la etapa sólo si da datos (columna usable). */

    /**
     * CSV de los leads del período (misma ventana + rol que index()).
     * Replica el patrón del wacrm: stream chunked, BOM UTF-8, separador `;`.
     */
    public function export(Request $request): StreamedResponse
    {
        $accountId = $request->user()->account_id;
        $isAdmin = $request->user()->hasRoleAtLeast(User::ROLE_ADMIN);
        $user = $request->user();

        $days = $this->resolveDays($request);
        $periodStart = now()->subDays($days);

        $pipelines = Pipeline::forAccount($accountId)->get();
        $selected = $pipelines->firstWhere('id', $request->query('pipeline'))
            ?? $pipelines->firstWhere('is_default', true)
            ?? $pipelines->first();

        $filename = 'reporte_leads_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($selected, $periodStart, $isAdmin, $user) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'ID', 'Titulo', 'Contacto', 'Telefono', 'Email',
                'Etapa', 'Estado', 'Valor', 'Moneda',
                'Fuente', 'UTM Source', 'UTM Campaign',
                'Responsable',
                'Creado', 'Cerrado', 'Facturado (Bs)', 'Cobrado (Bs)',
            ], ';');

            $selected?->leads()
                ->with(['contact:id,name,phone,email', 'stage:id,name', 'responsible:id,name'])
                ->where('created_at', '>=', $periodStart)
                ->when(! $isAdmin, fn ($q) => $q->where('responsible_user_id', $user->id))
                ->orderByDesc('created_at')
                ->chunk(500, function ($chunk) use ($out) {
                    foreach ($chunk as $lead) {
                        fputcsv($out, [
                            $lead->id,
                            $lead->title,
                            $lead->contact?->name ?? '',
                            $lead->contact?->phone ?? '',
                            $lead->contact?->email ?? '',
                            $lead->stage?->name ?? '',
                            $lead->status,
                            $lead->value,
                            $lead->currency,
                            $lead->source,
                            $lead->utm_source ?? '',
                            $lead->utm_campaign ?? '',
                            $lead->responsible?->name ?? '',
                            $lead->created_at?->toDateTimeString(),
                            $lead->closed_at?->toDateTimeString() ?? '',
                            number_format(($lead->invoiced_cents ?? 0) / 100, 2, ',', ''),
                            number_format(($lead->collected_cents ?? 0) / 100, 2, ',', ''),
                        ], ';');
                    }
                });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}