import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';

function money(value, currency) {
    return new Intl.NumberFormat('es', { style: 'currency', currency: currency || 'USD', maximumFractionDigits: 0 }).format(value || 0);
}

export default function Index({ pipelines, pipelineId, funnel, monthly, byUser, conversion, bySource = [], byUtmSource = [], byUtmCampaign = [], currency }) {
    const maxFunnel = Math.max(1, ...funnel.map((s) => s.count));
    const maxMonthly = Math.max(1, ...monthly.map((m) => m.won + m.lost));

    return (
        <AuthenticatedLayout>
            <Head title="Reportes" />

            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Reportes</h1>
                        <p className="text-sm text-gray-400 mt-1">Rendimiento del embudo y del equipo</p>
                    </div>
                    {pipelines.length > 1 && (
                        <select
                            value={pipelineId ?? ''}
                            onChange={(e) => router.get(route('reports.index'), { pipeline: e.target.value })}
                            className="px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm font-medium bg-white shadow-sm"
                        >
                            {pipelines.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                        </select>
                    )}
                </div>

                {/* KPIs de conversión */}
                <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-5">
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                        <p className="text-xs font-semibold uppercase tracking-wider text-gray-400">Tasa de conversión</p>
                        <p className="text-3xl font-extrabold text-gray-900 mt-1 tabular-nums">{conversion.rate}%</p>
                        <div className="mt-2 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                            <div className="h-full bg-gradient-to-r from-emerald-500 to-teal-600 rounded-full" style={{ width: `${conversion.rate}%` }} />
                        </div>
                    </div>
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                        <p className="text-xs font-semibold uppercase tracking-wider text-gray-400">Ganados (total)</p>
                        <p className="text-3xl font-extrabold text-emerald-600 mt-1 tabular-nums">{conversion.won}</p>
                    </div>
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                        <p className="text-xs font-semibold uppercase tracking-wider text-gray-400">Perdidos (total)</p>
                        <p className="text-3xl font-extrabold text-red-500 mt-1 tabular-nums">{conversion.lost}</p>
                    </div>
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                        <p className="text-xs font-semibold uppercase tracking-wider text-gray-400">Ticket promedio</p>
                        <p className="text-3xl font-extrabold text-gray-900 mt-1 tabular-nums">{money(conversion.avgTicket, currency)}</p>
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Embudo */}
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 sm:p-6">
                        <h3 className="text-base font-bold text-gray-900">Embudo actual</h3>
                        <p className="text-xs text-gray-400 mt-0.5 mb-5">Leads abiertos por etapa</p>
                        <div className="space-y-3">
                            {funnel.map((stage) => (
                                <div key={stage.name}>
                                    <div className="flex items-center justify-between text-sm mb-1">
                                        <span className="font-semibold text-gray-700">{stage.name}</span>
                                        <span className="text-xs text-gray-500 tabular-nums">
                                            {stage.count} · {money(stage.value, currency)}
                                        </span>
                                    </div>
                                    <div className="h-7 bg-gray-50 rounded-lg overflow-hidden">
                                        <div
                                            className="h-full rounded-lg transition-all flex items-center px-2"
                                            style={{
                                                width: `${Math.max((stage.count / maxFunnel) * 100, stage.count > 0 ? 8 : 0)}%`,
                                                background: `linear-gradient(90deg, ${stage.color}, ${stage.color}cc)`,
                                            }}
                                        >
                                            {stage.count > 0 && <span className="text-[10px] font-bold text-white">{stage.count}</span>}
                                        </div>
                                    </div>
                                </div>
                            ))}
                            {funnel.length === 0 && <p className="py-8 text-center text-sm text-gray-400">Sin datos</p>}
                        </div>
                    </div>

                    {/* Ganados/perdidos por mes */}
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 sm:p-6">
                        <div className="flex items-center justify-between mb-5">
                            <div>
                                <h3 className="text-base font-bold text-gray-900">Cierres por mes</h3>
                                <p className="text-xs text-gray-400 mt-0.5">Últimos 6 meses</p>
                            </div>
                            <div className="flex gap-3 text-xs font-medium text-gray-500">
                                <span className="flex items-center gap-1.5"><span className="w-2.5 h-2.5 rounded-sm bg-gradient-to-br from-emerald-500 to-teal-600" /> Ganados</span>
                                <span className="flex items-center gap-1.5"><span className="w-2.5 h-2.5 rounded-sm bg-gradient-to-br from-red-400 to-rose-500" /> Perdidos</span>
                            </div>
                        </div>
                        <div className="flex items-end gap-2 h-44">
                            {monthly.map((m) => (
                                <div key={m.month} className="flex-1 flex flex-col items-center justify-end h-full group">
                                    <div className="w-full flex items-end justify-center gap-1" style={{ height: '85%' }}>
                                        <div className="w-1/3 rounded-t-md bg-gradient-to-b from-emerald-500 to-teal-600 relative group-hover:brightness-110 transition-all" style={{ height: `${(m.won / maxMonthly) * 100}%`, minHeight: m.won > 0 ? '4px' : '0' }}>
                                            {m.won > 0 && <span className="absolute -top-5 left-1/2 -translate-x-1/2 text-[10px] font-bold text-emerald-600 opacity-0 group-hover:opacity-100">{m.won}</span>}
                                        </div>
                                        <div className="w-1/3 rounded-t-md bg-gradient-to-b from-red-400 to-rose-500 relative group-hover:brightness-110 transition-all" style={{ height: `${(m.lost / maxMonthly) * 100}%`, minHeight: m.lost > 0 ? '4px' : '0' }}>
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
                {bySource.length > 0 && (
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="p-5 sm:p-6 border-b border-gray-100">
                            <h3 className="text-base font-bold text-gray-900">Conversión por fuente</h3>
                            <p className="text-xs text-gray-400 mt-0.5">De dónde vienen los leads y qué tasa convierten a ganado</p>
                        </div>
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="bg-gray-50/80">
                                    <th className="text-left px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Fuente</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Total</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Abiertos</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Ganados</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Perdidos</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Ingresos</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">% Conv.</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-50">
                                {bySource.map((s) => {
                                    const rate = s.conversion_rate;
                                    const rateColor = rate >= 50 ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : rate >= 25 ? 'bg-amber-50 text-amber-700 ring-amber-200' : 'bg-red-50 text-red-700 ring-red-200';
                                    const sourceIcon = { whatsapp: '💬', lead_ad: '📣', web_form: '📋', manual: '✍️', api: '⚙️', other: '❓' }[s.source] ?? '❓';
                                    return (
                                        <tr key={s.source} className="hover:bg-gray-50">
                                            <td className="px-5 py-3">
                                                <span className="inline-flex items-center gap-2 font-semibold text-gray-900">
                                                    <span className="text-lg">{sourceIcon}</span>
                                                    {s.label}
                                                </span>
                                            </td>
                                            <td className="px-5 py-3 text-right tabular-nums font-semibold text-gray-900">{s.total}</td>
                                            <td className="px-5 py-3 text-right tabular-nums text-sky-600">{s.open}</td>
                                            <td className="px-5 py-3 text-right tabular-nums text-emerald-600">{s.won}</td>
                                            <td className="px-5 py-3 text-right tabular-nums text-red-500">{s.lost}</td>
                                            <td className="px-5 py-3 text-right tabular-nums text-gray-700 font-medium">
                                                {new Intl.NumberFormat('es', { style: 'currency', currency: currency || 'USD', maximumFractionDigits: 0 }).format(s.won_value)}
                                            </td>
                                            <td className="px-5 py-3 text-right">
                                                <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold ring-1 ${rateColor}`}>
                                                    {rate}%
                                                </span>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}

                {/* Conversión por canal de marketing (utm_source) */}
                {byUtmSource.length > 0 && (
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="p-5 sm:p-6 border-b border-gray-100">
                            <h3 className="text-base font-bold text-gray-900">Canales de marketing</h3>
                            <p className="text-xs text-gray-400 mt-0.5">Atribución first-touch por <code className="text-[10px] px-1 py-0.5 bg-gray-100 rounded">utm_source</code> (Google, Meta, TikTok, orgánico, email, …). <span className="text-gray-500">(direct)</span> = tráfico directo o sin UTM.</p>
                        </div>
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="bg-gray-50/80">
                                    <th className="text-left px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Canal</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Total</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Abiertos</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Ganados</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Perdidos</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Ingresos</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">% Conv.</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-50">
                                {byUtmSource.map((s) => {
                                    const rate = s.conversion_rate;
                                    const rateColor = rate >= 50 ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : rate >= 25 ? 'bg-amber-50 text-amber-700 ring-amber-200' : 'bg-red-50 text-red-700 ring-red-200';
                                    const sourceIcon = { google: '🔍', google_ads: '🔍', meta: '📣', meta_ads: '📣', facebook: '📣', instagram: '📸', tiktok: '🎵', tiktok_ads: '🎵', linkedin: '💼', email: '📧', bing: '🔎', youtube: '▶️', '(direct)': '🌐' }[s.label?.toLowerCase()] ?? '📊';
                                    return (
                                        <tr key={s.label} className="hover:bg-gray-50">
                                            <td className="px-5 py-3">
                                                <span className="inline-flex items-center gap-2 font-semibold text-gray-900">
                                                    <span className="text-lg">{sourceIcon}</span>
                                                    {s.label}
                                                </span>
                                            </td>
                                            <td className="px-5 py-3 text-right tabular-nums font-semibold text-gray-900">{s.total}</td>
                                            <td className="px-5 py-3 text-right tabular-nums text-sky-600">{s.open}</td>
                                            <td className="px-5 py-3 text-right tabular-nums text-emerald-600">{s.won}</td>
                                            <td className="px-5 py-3 text-right tabular-nums text-red-500">{s.lost}</td>
                                            <td className="px-5 py-3 text-right tabular-nums text-gray-700 font-medium">{money(s.won_value, currency)}</td>
                                            <td className="px-5 py-3 text-right">
                                                <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold ring-1 ${rateColor}`}>
                                                    {rate}%
                                                </span>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}

                {/* Top campañas por utm_campaign */}
                {byUtmCampaign.length > 0 && (
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="p-5 sm:p-6 border-b border-gray-100">
                            <h3 className="text-base font-bold text-gray-900">Top campañas</h3>
                            <p className="text-xs text-gray-400 mt-0.5">Las 10 campañas (<code className="text-[10px] px-1 py-0.5 bg-gray-100 rounded">utm_campaign</code>) con más leads en el período.</p>
                        </div>
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="bg-gray-50/80">
                                    <th className="text-left px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Campaña</th>
                                    <th className="text-left px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Canal</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Total</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Ganados</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Perdidos</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Ingresos</th>
                                    <th className="text-right px-5 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">% Conv.</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-50">
                                {byUtmCampaign.map((c) => {
                                    const rate = c.conversion_rate;
                                    const rateColor = rate >= 50 ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : rate >= 25 ? 'bg-amber-50 text-amber-700 ring-amber-200' : 'bg-red-50 text-red-700 ring-red-200';
                                    return (
                                        <tr key={c.label + c.source} className="hover:bg-gray-50">
                                            <td className="px-5 py-3 font-mono text-xs text-gray-900 max-w-xs truncate" title={c.label}>{c.label}</td>
                                            <td className="px-5 py-3 text-xs text-gray-600">{c.source}</td>
                                            <td className="px-5 py-3 text-right tabular-nums font-semibold text-gray-900">{c.total}</td>
                                            <td className="px-5 py-3 text-right tabular-nums text-emerald-600">{c.won}</td>
                                            <td className="px-5 py-3 text-right tabular-nums text-red-500">{c.lost}</td>
                                            <td className="px-5 py-3 text-right tabular-nums text-gray-700 font-medium">{money(c.won_value, currency)}</td>
                                            <td className="px-5 py-3 text-right">
                                                <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold ring-1 ${rateColor}`}>{rate}%</span>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}

                {/* Ranking del equipo */}
                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div className="p-5 sm:p-6 border-b border-gray-100">
                        <h3 className="text-base font-bold text-gray-900">Equipo este mes</h3>
                        <p className="text-xs text-gray-400 mt-0.5">Ventas ganadas por responsable</p>
                    </div>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-gray-50/80">
                                <th className="text-left px-5 py-3.5 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Vendedor</th>
                                <th className="text-right px-5 py-3.5 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Leads abiertos</th>
                                <th className="text-right px-5 py-3.5 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Ganados</th>
                                <th className="text-right px-5 py-3.5 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Ingresos</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {byUser.map((user, i) => (
                                <tr key={user.name} className="hover:bg-gray-50 transition-colors">
                                    <td className="px-5 py-4">
                                        <div className="flex items-center gap-3">
                                            <span className="text-sm">{i === 0 && user.wonValue > 0 ? '🥇' : i === 1 && user.wonValue > 0 ? '🥈' : i === 2 && user.wonValue > 0 ? '🥉' : ''}</span>
                                            <div className="w-8 h-8 rounded-xl bg-gradient-to-br from-[#045474] to-[#1c486c] flex items-center justify-center text-white text-xs font-bold">
                                                {user.name.charAt(0).toUpperCase()}
                                            </div>
                                            <span className="font-semibold text-gray-900">{user.name}</span>
                                        </div>
                                    </td>
                                    <td className="px-5 py-4 text-right tabular-nums text-gray-600">{user.open}</td>
                                    <td className="px-5 py-4 text-right tabular-nums font-bold text-emerald-600">{user.won}</td>
                                    <td className="px-5 py-4 text-right tabular-nums font-extrabold text-gray-900">{money(user.wonValue, currency)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
