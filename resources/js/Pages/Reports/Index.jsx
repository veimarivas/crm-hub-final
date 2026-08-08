import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import {
    ChartCard,
    CompareBars,
    DonutChart,
    FunnelSteps,
    SERIES,
    TONE,
    TrendArea,
    WindowPicker,
    fmtInteger,
    fmtMoney,
} from '@/Components/Charts';

function money(value, currency) {
    return fmtMoney(Number(value) || 0, currency);
}

/** Chip del delta vs periodo anterior. `pp` = puntos porcentuales (no %). */
function DeltaChip({ delta, pp = false, invert = false }) {
    if (delta === null || delta === undefined) return null;
    const up = delta > 0;
    const good = invert ? !up : up;
    const cls = good ? 'text-emerald-600 bg-emerald-50' : 'text-rose-600 bg-rose-50';
    const arrow = up ? '▲' : delta < 0 ? '▼' : '◆';
    const suffix = pp ? ' p.p.' : '%';

    return (
        <span className={`inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[11px] font-bold tabular-nums ${cls}`}>
            {arrow} {Math.abs(delta)}{suffix}
            <span className="font-medium opacity-70">vs anterior</span>
        </span>
    );
}

function KpiCard({ label, value, suffix, gradient, iconPath, children }) {
    return (
        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 relative overflow-hidden group hover:shadow-md transition-all">
            <div className={`absolute -top-4 -right-4 w-24 h-24 rounded-full opacity-10 bg-gradient-to-br ${gradient}`} />
            <div className="relative flex items-center justify-between gap-2">
                <div>
                    <p className="text-[11px] font-semibold uppercase tracking-wider text-gray-400">{label}</p>
                    <p className="text-3xl font-extrabold text-gray-900 mt-1 tabular-nums leading-none">{value}<span className="text-lg text-gray-400">{suffix || ''}</span></p>
                    <div className="mt-2">{children}</div>
                </div>
                <div className={`relative w-10 h-10 rounded-xl bg-gradient-to-br ${gradient} flex items-center justify-center text-white shadow-md shrink-0`}>
                    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d={iconPath} /></svg>
                </div>
            </div>
        </div>
    );
}

function MiniStat({ label, value, tone }) {
    const tones = {
        brand: 'bg-[#045474]',
        positive: 'bg-emerald-500',
        warning: 'bg-amber-400',
        danger: 'bg-rose-500',
    };
    return (
        <div className="rounded-xl border border-gray-100 bg-gray-50/60 px-3 py-2.5">
            <p className="text-[10px] font-semibold uppercase tracking-wider text-gray-400">{label}</p>
            <p className={`text-lg font-extrabold text-gray-900 tabular-nums mt-0.5 flex items-center gap-1.5`}>
                <span className={`w-1.5 h-1.5 rounded-full ${tones[tone] || tones.brand}`} />
                {value}
            </p>
        </div>
    );
}

function SourceTable({ title, subtitle, rows, currency, showChannel = false, monoLabel = false, drillSource = false }) {
    if (!rows || rows.length === 0) return null;
    const maxTotal = Math.max(1, ...rows.map((r) => r.total));

    const rateTone = (rate) => {
        if (rate >= 50) return { badge: 'bg-emerald-50 text-emerald-700 ring-emerald-200', bar: 'from-emerald-500 to-teal-600' };
        if (rate >= 25) return { badge: 'bg-amber-50 text-amber-700 ring-amber-200', bar: 'from-amber-400 to-orange-500' };
        return { badge: 'bg-red-50 text-red-700 ring-red-200', bar: 'from-red-400 to-rose-500' };
    };

    return (
        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div className="p-5 sm:p-6 border-b border-gray-100">
                <h3 className="text-base font-bold text-gray-900">{title}</h3>
                {subtitle && <p className="text-xs text-gray-500 mt-0.5">{subtitle}</p>}
            </div>
            <div className="overflow-x-auto">
                <table className="w-full text-sm min-w-[720px]">
                    <thead className="sticky top-0 z-10">
                        <tr className="bg-gray-50/95 backdrop-blur">
                            <th className="text-left px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">{monoLabel ? 'Campaña' : 'Fuente'}</th>
                            {showChannel && <th className="text-left px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Canal</th>}
                            <th className="text-left px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider w-[22%]">Volumen</th>
                            <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Ganados</th>
                            <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Perdidos</th>
                            <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Ingresos</th>
                            <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Conversión</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-50">
                        {rows.map((s) => {
                            const rate = s.conversion_rate;
                            const tone = rateTone(rate);
                            const sourceIcon = { whatsapp: '💬', booking: '📅', 'formulario de reserva': '📅', lead_ad: '📣', web_form: '📋', manual: '✍️', api: '⚙️', other: '❓', google: '🔍', google_ads: '🔍', meta: '📣', meta_ads: '📣', facebook: '📣', instagram: '📸', tiktok: '🎵', tiktok_ads: '🎵', linkedin: '💼', email: '📧', bing: '🔎', youtube: '▶️', '(direct)': '🌐' }[(s.source || s.label || '').toLowerCase()] ?? '📊';
                            const name = (
                                <span className="inline-flex items-center gap-2 font-semibold text-gray-900">
                                    <span className="text-lg">{sourceIcon}</span>
                                    {s.label}
                                </span>
                            );
                            return (
                                <tr key={(s.source || s.label) + (s.source ?? '')} className="hover:bg-gray-50 transition-colors">
                                    <td className="px-5 py-3">
                                        {monoLabel ? (
                                            <span className="font-mono text-xs text-gray-900 block max-w-xs truncate" title={s.label}>{s.label}</span>
                                        ) : drillSource ? (
                                            <button
                                                onClick={() => router.get(route('leads.index'), { source: s.source })}
                                                className="hover:text-[#0d9488] text-left"
                                                title={`Ver leads de ${s.label}`}
                                            >
                                                {name}
                                            </button>
                                        ) : name}
                                    </td>
                                    {showChannel && <td className="px-5 py-3 text-xs text-gray-600">{s.source}</td>}
                                    <td className="px-5 py-3">
                                        <div className="flex items-center gap-2">
                                            <span className="text-sm font-bold text-gray-900 tabular-nums w-10 shrink-0">{s.total}</span>
                                            <div className="flex-1 h-1.5 bg-gray-100 rounded-full overflow-hidden min-w-[40px]">
                                                <div className="h-full bg-gradient-to-r from-[#045474] to-[#1c486c] rounded-full" style={{ width: `${(s.total / maxTotal) * 100}%` }} />
                                            </div>
                                        </div>
                                        {s.open !== undefined && <span className="text-[10px] text-gray-400 ml-12">{s.open} abiertos</span>}
                                    </td>
                                    <td className="px-5 py-3 text-right tabular-nums text-emerald-600 font-semibold">{s.won}</td>
                                    <td className="px-5 py-3 text-right tabular-nums text-red-500">{s.lost}</td>
                                    <td className="px-5 py-3 text-right tabular-nums text-gray-800 font-semibold">{money(s.won_value, currency)}</td>
                                    <td className="px-5 py-3 text-right">
                                        <div className="inline-flex items-center gap-2">
                                            <div className="w-16 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                                                <div className={`h-full bg-gradient-to-r ${tone.bar} rounded-full`} style={{ width: `${Math.min(rate, 100)}%` }} />
                                            </div>
                                            <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold ring-1 tabular-nums ${tone.badge}`}>
                                                {rate}%
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

export default function Index({
    pipelines, pipelineId, days, kpi = [], weekly = [], funnel = [], byUser = [],
    teamStages = [], stageNames = [], stageColors = {},
    bySource = [], byUtmSource = [], byUtmCampaign = [],
    sourceRevenue = [], revenue = { invoiced: 0, collected: 0, monthly: [] }, closeTime = [],
    isAdmin, currency,
}) {
    const gotoLeads = (params) => router.get(route('leads.index'), params, { preserveScroll: true });

    const kpiValue = (k) => {
        if (k.money) return money(k.value, currency);
        return fmtInteger(k.value);
    };

    return (
        <AuthenticatedLayout>
            <Head title="Reportes" />

            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Reportes</h1>
                        <p className="text-sm text-gray-500 mt-1">Tendencias, embudo, ingresos y tiempo de cierre</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-3">
                        {pipelines.length > 1 && (
                            <select
                                value={pipelineId ?? ''}
                                onChange={(e) => router.get(route('reports.index'), { pipeline: e.target.value, days })}
                                className="px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm font-medium bg-white shadow-sm focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 outline-none"
                            >
                                {pipelines.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                            </select>
                        )}
                        <WindowPicker days={days} routeName="reports.index" preserve={{ pipeline: pipelineId }} />
                        <a
                            href={route('reports.export', { days, pipeline: pipelineId || undefined })}
                            className="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-gray-600 hover:text-gray-900 bg-white border border-gray-200 rounded-xl shadow-sm transition-colors"
                            title="Descargar leads del periodo en CSV"
                        >
                            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                            CSV
                        </a>
                    </div>
                </div>

                {/* KPIs de la ventana + delta vs el periodo anterior */}
                <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-5">
                    {kpi.map((k) => (
                        <KpiCard
                            key={k.key}
                            label={k.label}
                            value={kpiValue(k)}
                            suffix={k.suffix || ''}
                            gradient={{
                                won: 'from-emerald-500 to-teal-600',
                                created: 'from-[#045474] to-[#1c486c]',
                                rate: 'from-amber-400 to-orange-500',
                                invoiced: 'from-[#2e5eaa] to-[#5478c2]',
                            }[k.key] || 'from-[#045474] to-[#1c486c]'}
                            iconPath={{
                                won: 'M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.562.562 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z',
                                created: 'M12 4.5v15m7.5-7.5h-15',
                                rate: 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
                                invoiced: 'M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
                            }[k.key] || 'M12 4.5v15m7.5-7.5h-15'}
                        >
                            <DeltaChip delta={k.delta} pp={k.pp} />
                        </KpiCard>
                    ))}
                </div>

                {/* Gráficos analíticos 1–6 */}
                <div className="grid gap-6 lg:grid-cols-2">
                    <ChartCard title="Tendencia semanal" subtitle="Leads creados, ganados y perdidos (últimos 6 meses)">
                        <TrendArea
                            data={weekly}
                            xKey="label"
                            series={[
                                { key: 'created', name: 'Creados', color: TONE.brand },
                                { key: 'won', name: 'Ganados', color: TONE.positive },
                                { key: 'lost', name: 'Perdidos', color: TONE.danger },
                            ]}
                        />
                    </ChartCard>

                    <ChartCard title="Embudo actual" subtitle="Leads abiertos por etapa · clic para verlos" actions={(
                        <span className="text-[11px] font-bold text-gray-400 uppercase tracking-wider">
                            {funnel.reduce((a, s) => a + s.count, 0)} total
                        </span>
                    )}>
                        <FunnelSteps
                            steps={funnel}
                            onStepClick={(s) => gotoLeads({ pipeline: pipelineId, stage_id: s.id })}
                        />
                    </ChartCard>

                    <ChartCard title="Ingresos por fuente" subtitle="Distribución del revenue ganado · clic en ver">
                        <DonutChart
                            data={sourceRevenue}
                            centerLabel="Ingresos"
                            valueFormatter={(v) => money(v, currency)}
                            onSliceClick={(s) => s?.sourceKey && s.sourceKey !== 'other'
                                ? gotoLeads({ source: s.sourceKey })
                                : undefined}
                        />
                    </ChartCard>

                    <ChartCard title="Tiempo hasta cerrar" subtitle="Días entre que entra y se gana/se pierde">
                        <CompareBars data={closeTime} xKey="name" layout="vertical" />
                    </ChartCard>

                    <ChartCard
                        title="Facturación"
                        subtitle={`Invoiced vs cobrado · últimos ${days} días`}
                    >
                        <div className="grid grid-cols-3 gap-3 mb-4">
                            <MiniStat label="Facturado" value={money(revenue.invoiced, currency)} tone="brand" />
                            <MiniStat label="Cobrado" value={money(revenue.collected, currency)} tone="positive" />
                            <MiniStat label="Por cobrar" value={money(Math.max(0, revenue.invoiced - revenue.collected), currency)} tone="warning" />
                        </div>
                        <TrendArea
                            data={revenue.monthly}
                            xKey="label"
                            series={[
                                { key: 'revenue', name: 'Ingresos', color: SERIES.facturado },
                                { key: 'won', name: 'Ganados', color: TONE.positive },
                            ]}
                            height={180}
                            valueFormatter={(v) => money(v, currency)}
                        />
                    </ChartCard>

                    {isAdmin && (
                        <ChartCard title="Equipo por etapa" subtitle="Leads abiertos de cada vendedor por etapa del pipeline">
                            <CompareBars
                                data={teamStages}
                                xKey="name"
                                series={stageNames.map((sn) => ({ key: sn, name: sn, color: stageColors[sn] || '#64748b' }))}
                            />
                        </ChartCard>
                    )}
                </div>

                {/* Tablas de conversión */}
                <SourceTable
                    title="Conversión por fuente"
                    subtitle="De dónde vienen los leads y qué tasa convierten a ganado · clic en la fuente para filtrar"
                    rows={bySource}
                    currency={currency}
                    drillSource
                />

                <SourceTable
                    title="Canales de marketing"
                    subtitle="WhatsApp y Formulario de reserva son canales propios. El resto es atribución first-touch por utm_source (Google, Meta, TikTok, orgánico, email, …). (direct) = sin UTM."
                    rows={byUtmSource}
                    currency={currency}
                />

                <SourceTable
                    title="Top campañas"
                    subtitle="Las 10 campañas (utm_campaign) con más leads en el período."
                    rows={byUtmCampaign}
                    currency={currency}
                    showChannel
                    monoLabel
                />

                {isAdmin && byUser.length > 0 && (
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="p-5 sm:p-6 border-b border-gray-100">
                            <h3 className="text-base font-bold text-gray-900">Equipo este mes</h3>
                            <p className="text-xs text-gray-500 mt-0.5">Ventas ganadas por responsable · clic en fila para la ficha</p>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm min-w-[520px]">
                                <thead className="sticky top-0 z-10 bg-gray-50/95 backdrop-blur">
                                    <tr>
                                        <th className="text-left px-5 py-3.5 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Vendedor</th>
                                        <th className="text-right px-5 py-3.5 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Abiertos</th>
                                        {stageNames.map((sn) => (
                                            <th key={sn} className="text-right px-3 py-3.5 text-[11px] font-bold text-gray-500 uppercase tracking-wider min-w-[70px]" title={sn}>{sn}</th>
                                        ))}
                                        <th className="text-right px-5 py-3.5 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Ganados</th>
                                        <th className="text-right px-5 py-3.5 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Ingresos</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-50">
                                    {(() => {
                                        const maxIncome = Math.max(1, ...byUser.map((u) => u.wonValue || 0));
                                        return byUser.map((user, i) => {
                                            const medal = i === 0 && user.wonValue > 0 ? '🥇' : i === 1 && user.wonValue > 0 ? '🥈' : i === 2 && user.wonValue > 0 ? '🥉' : '';
                                            return (
                                                <tr
                                                    key={user.name}
                                                    onClick={() => user.id && router.visit(route('supervision.agent', { user: user.id }))}
                                                    className="hover:bg-gray-50 transition-colors cursor-pointer"
                                                    title="Ver ficha de atención"
                                                >
                                                    <td className="px-5 py-4">
                                                        <div className="flex items-center gap-3">
                                                            <span className="text-lg w-5 text-center">{medal}</span>
                                                            <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-[#045474] to-[#1c486c] flex items-center justify-center text-white text-xs font-bold shadow-md">
                                                                {user.name.charAt(0).toUpperCase()}
                                                            </div>
                                                            <span className="font-semibold text-gray-900">{user.name}</span>
                                                        </div>
                                                    </td>
                                                    <td className="px-5 py-4 text-right tabular-nums text-gray-600">{user.open}</td>
                                                    {stageNames.map((sn) => (
                                                        <td key={sn} className="px-3 py-4 text-right tabular-nums text-gray-500">
                                                            {user.stages?.[sn] || 0}
                                                        </td>
                                                    ))}
                                                    <td className="px-5 py-4 text-right tabular-nums font-bold text-emerald-600">{user.won}</td>
                                                    <td className="px-5 py-4 text-right">
                                                        <div className="inline-flex items-center gap-2 justify-end w-full">
                                                            <div className="hidden sm:block w-20 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                                                                <div className="h-full bg-gradient-to-r from-emerald-500 to-teal-600 rounded-full" style={{ width: `${(user.wonValue / maxIncome) * 100}%` }} />
                                                            </div>
                                                            <span className="tabular-nums font-extrabold text-gray-900">{money(user.wonValue, currency)}</span>
                                                        </div>
                                                    </td>
                                                </tr>
                                            );
                                        });
                                    })()}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}