import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';

function money(value, currency) {
    return new Intl.NumberFormat('es', { style: 'currency', currency: currency || 'USD', maximumFractionDigits: 0 }).format(value || 0);
}

function rateTone(rate) {
    if (rate >= 50) return { badge: 'bg-emerald-50 text-emerald-700 ring-emerald-200', bar: 'from-emerald-500 to-teal-600' };
    if (rate >= 25) return { badge: 'bg-amber-50 text-amber-700 ring-amber-200', bar: 'from-amber-400 to-orange-500' };
    return { badge: 'bg-red-50 text-red-700 ring-red-200', bar: 'from-red-400 to-rose-500' };
}

function KpiCard({ label, value, sub, gradient, iconPath, extra }) {
    return (
        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 relative overflow-hidden group hover:shadow-md transition-all">
            <div className={`absolute -top-4 -right-4 w-24 h-24 rounded-full opacity-10 bg-gradient-to-br ${gradient}`} />
            <div className={`relative w-10 h-10 rounded-xl bg-gradient-to-br ${gradient} flex items-center justify-center text-white shadow-md mb-3`}>
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d={iconPath} /></svg>
            </div>
            <p className="text-[11px] font-semibold uppercase tracking-wider text-gray-400">{label}</p>
            <p className="text-3xl font-extrabold text-gray-900 mt-1 tabular-nums leading-none">{value}</p>
            {sub && <p className="text-xs text-gray-500 mt-2">{sub}</p>}
            {extra}
        </div>
    );
}

function SourceTable({ title, subtitle, rows, currency, showChannel = false, monoLabel = false }) {
    if (!rows || rows.length === 0) return null;
    const maxTotal = Math.max(1, ...rows.map(r => r.total));

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
                            const sourceIcon = { whatsapp: '💬', lead_ad: '📣', web_form: '📋', manual: '✍️', api: '⚙️', other: '❓', google: '🔍', google_ads: '🔍', meta: '📣', meta_ads: '📣', facebook: '📣', instagram: '📸', tiktok: '🎵', tiktok_ads: '🎵', linkedin: '💼', email: '📧', bing: '🔎', youtube: '▶️', '(direct)': '🌐' }[(s.source || s.label || '').toLowerCase()] ?? '📊';
                            return (
                                <tr key={(s.source || s.label) + (s.source ?? '')} className="hover:bg-gray-50 transition-colors">
                                    <td className="px-5 py-3">
                                        {monoLabel ? (
                                            <span className="font-mono text-xs text-gray-900 block max-w-xs truncate" title={s.label}>{s.label}</span>
                                        ) : (
                                            <span className="inline-flex items-center gap-2 font-semibold text-gray-900">
                                                <span className="text-lg">{sourceIcon}</span>
                                                {s.label}
                                            </span>
                                        )}
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

export default function Index({ pipelines, pipelineId, funnel, monthly, byUser, conversion, bySource = [], byUtmSource = [], byUtmCampaign = [], currency }) {
    const maxFunnel = Math.max(1, ...funnel.map((s) => s.count));
    const maxMonthly = Math.max(1, ...monthly.map((m) => m.won + m.lost));
    const totalRevenue = conversion.won > 0 ? conversion.avgTicket * conversion.won : 0;

    return (
        <AuthenticatedLayout>
            <Head title="Reportes" />

            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Reportes</h1>
                        <p className="text-sm text-gray-500 mt-1">Rendimiento del embudo y del equipo</p>
                    </div>
                    {pipelines.length > 1 && (
                        <select
                            value={pipelineId ?? ''}
                            onChange={(e) => router.get(route('reports.index'), { pipeline: e.target.value })}
                            className="px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm font-medium bg-white shadow-sm focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 outline-none"
                        >
                            {pipelines.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                        </select>
                    )}
                </div>

                {/* KPIs con visualización */}
                <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-5">
                    <KpiCard
                        label="Tasa de conversión"
                        value={`${conversion.rate}%`}
                        gradient="from-emerald-500 to-teal-600"
                        iconPath="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22"
                        extra={(
                            <div className="mt-3 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                                <div className="h-full bg-gradient-to-r from-emerald-500 to-teal-600 rounded-full transition-all" style={{ width: `${conversion.rate}%` }} />
                            </div>
                        )}
                    />
                    <KpiCard
                        label="Ganados (total)"
                        value={conversion.won}
                        sub={money(totalRevenue, currency)}
                        gradient="from-[#045474] to-[#1c486c]"
                        iconPath="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.562.562 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z"
                    />
                    <KpiCard
                        label="Perdidos"
                        value={conversion.lost}
                        sub={conversion.won + conversion.lost > 0 ? `${Math.round((conversion.lost / (conversion.won + conversion.lost)) * 100)}% del cierre` : '—'}
                        gradient="from-red-400 to-rose-500"
                        iconPath="M6 18L18 6M6 6l12 12"
                    />
                    <KpiCard
                        label="Ticket promedio"
                        value={money(conversion.avgTicket, currency)}
                        sub="por venta ganada"
                        gradient="from-amber-400 to-orange-500"
                        iconPath="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"
                    />
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Embudo */}
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 sm:p-6">
                        <div className="flex items-center justify-between mb-5">
                            <div>
                                <h3 className="text-base font-bold text-gray-900">Embudo actual</h3>
                                <p className="text-xs text-gray-500 mt-0.5">Leads abiertos por etapa</p>
                            </div>
                            <span className="text-[11px] font-bold text-gray-400 uppercase tracking-wider">{funnel.reduce((a, s) => a + s.count, 0)} total</span>
                        </div>
                        <div className="space-y-3">
                            {funnel.map((stage) => (
                                <div key={stage.name}>
                                    <div className="flex items-center justify-between text-sm mb-1.5">
                                        <span className="font-semibold text-gray-700 inline-flex items-center gap-2">
                                            <span className="w-2 h-2 rounded-full" style={{ backgroundColor: stage.color }} />
                                            {stage.name}
                                        </span>
                                        <span className="text-xs text-gray-500 tabular-nums">
                                            <span className="font-bold text-gray-800">{stage.count}</span> · {money(stage.value, currency)}
                                        </span>
                                    </div>
                                    <div className="h-7 bg-gray-50 rounded-lg overflow-hidden">
                                        <div
                                            className="h-full rounded-lg transition-all flex items-center px-2 shadow-inner"
                                            style={{
                                                width: `${Math.max((stage.count / maxFunnel) * 100, stage.count > 0 ? 8 : 0)}%`,
                                                background: `linear-gradient(90deg, ${stage.color}, ${stage.color}cc)`,
                                            }}
                                        >
                                            {stage.count > 0 && <span className="text-[10px] font-bold text-white drop-shadow">{stage.count}</span>}
                                        </div>
                                    </div>
                                </div>
                            ))}
                            {funnel.length === 0 && <p className="py-8 text-center text-sm text-gray-400">Sin datos</p>}
                        </div>
                    </div>

                    {/* Cierres por mes */}
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 sm:p-6">
                        <div className="flex items-center justify-between mb-5">
                            <div>
                                <h3 className="text-base font-bold text-gray-900">Cierres por mes</h3>
                                <p className="text-xs text-gray-500 mt-0.5">Últimos 6 meses</p>
                            </div>
                            <div className="flex gap-3 text-xs font-medium text-gray-500">
                                <span className="flex items-center gap-1.5"><span className="w-2.5 h-2.5 rounded-sm bg-gradient-to-br from-emerald-500 to-teal-600" /> Ganados</span>
                                <span className="flex items-center gap-1.5"><span className="w-2.5 h-2.5 rounded-sm bg-gradient-to-br from-red-400 to-rose-500" /> Perdidos</span>
                            </div>
                        </div>
                        <div className="flex items-end gap-2 h-48">
                            {monthly.map((m) => (
                                <div key={m.month} className="flex-1 flex flex-col items-center justify-end h-full group cursor-default">
                                    <div className="text-[10px] font-bold text-gray-500 mb-1 tabular-nums opacity-0 group-hover:opacity-100 transition-opacity">
                                        {m.won + m.lost}
                                    </div>
                                    <div className="w-full flex items-end justify-center gap-1" style={{ height: '85%' }}>
                                        <div className="w-1/3 rounded-t-md bg-gradient-to-b from-emerald-500 to-teal-600 relative group-hover:brightness-110 transition-all cursor-pointer" style={{ height: `${(m.won / maxMonthly) * 100}%`, minHeight: m.won > 0 ? '4px' : '0' }} title={`Ganados: ${m.won}`}>
                                            {m.won > 0 && <span className="absolute -top-5 left-1/2 -translate-x-1/2 text-[10px] font-bold text-emerald-600 opacity-0 group-hover:opacity-100">{m.won}</span>}
                                        </div>
                                        <div className="w-1/3 rounded-t-md bg-gradient-to-b from-red-400 to-rose-500 relative group-hover:brightness-110 transition-all cursor-pointer" style={{ height: `${(m.lost / maxMonthly) * 100}%`, minHeight: m.lost > 0 ? '4px' : '0' }} title={`Perdidos: ${m.lost}`}>
                                            {m.lost > 0 && <span className="absolute -top-5 left-1/2 -translate-x-1/2 text-[10px] font-bold text-red-500 opacity-0 group-hover:opacity-100">{m.lost}</span>}
                                        </div>
                                    </div>
                                    <span className="text-[10px] font-semibold text-gray-400 mt-2 pt-1 border-t border-gray-100 w-full text-center">{m.month}</span>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>

                {/* Conversión por fuente */}
                <SourceTable
                    title="Conversión por fuente"
                    subtitle="De dónde vienen los leads y qué tasa convierten a ganado"
                    rows={bySource}
                    currency={currency}
                />

                {/* Conversión por canal de marketing (utm_source) */}
                <SourceTable
                    title="Canales de marketing"
                    subtitle="Atribución first-touch por utm_source (Google, Meta, TikTok, orgánico, email, …). (direct) = tráfico directo o sin UTM."
                    rows={byUtmSource}
                    currency={currency}
                />

                {/* Top campañas */}
                <SourceTable
                    title="Top campañas"
                    subtitle="Las 10 campañas (utm_campaign) con más leads en el período."
                    rows={byUtmCampaign}
                    currency={currency}
                    showChannel={true}
                    monoLabel={true}
                />

                {/* Ranking del equipo */}
                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div className="p-5 sm:p-6 border-b border-gray-100">
                        <h3 className="text-base font-bold text-gray-900">Equipo este mes</h3>
                        <p className="text-xs text-gray-500 mt-0.5">Ventas ganadas por responsable</p>
                    </div>
                    {byUser.length === 0 ? (
                        <div className="p-10 text-center text-sm text-gray-400">Sin datos del equipo este mes</div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm min-w-[520px]">
                                <thead className="sticky top-0 z-10 bg-gray-50/95 backdrop-blur">
                                    <tr>
                                        <th className="text-left px-5 py-3.5 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Vendedor</th>
                                        <th className="text-right px-5 py-3.5 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Abiertos</th>
                                        <th className="text-right px-5 py-3.5 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Ganados</th>
                                        <th className="text-right px-5 py-3.5 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Ingresos</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-50">
                                    {(() => {
                                        const maxIncome = Math.max(1, ...byUser.map(u => u.wonValue));
                                        return byUser.map((user, i) => {
                                            const medal = i === 0 && user.wonValue > 0 ? '🥇' : i === 1 && user.wonValue > 0 ? '🥈' : i === 2 && user.wonValue > 0 ? '🥉' : '';
                                            return (
                                                <tr key={user.name} className="hover:bg-gray-50 transition-colors">
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
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
