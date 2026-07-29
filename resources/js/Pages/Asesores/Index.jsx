import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

function money(value, currency) {
    return new Intl.NumberFormat('es', { style: 'currency', currency: currency || 'USD', maximumFractionDigits: 0 }).format(value || 0);
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

function AgentCard({ agent, currency }) {
    const [expanded, setExpanded] = useState(false);
    const conversionRate = agent.total_leads > 0 ? Math.round((agent.won_leads / agent.total_leads) * 100) : 0;
    const openRate = agent.total_leads > 0 ? Math.round((agent.open_leads / agent.total_leads) * 100) : 0;
    const hasLeads = agent.total_leads > 0;

    const roleBadge = {
        owner: 'bg-amber-100 text-amber-700 ring-amber-200',
        admin: 'bg-purple-100 text-purple-700 ring-purple-200',
        agent: 'bg-blue-100 text-blue-700 ring-blue-200',
    }[agent.role] || 'bg-gray-100 text-gray-600 ring-gray-200';

    const roleLabel = { owner: 'Dueño', admin: 'Admin', agent: 'Agente' }[agent.role] || agent.role;

    return (
        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden hover:shadow-md transition-all">
            <button onClick={() => setExpanded(!expanded)} className="w-full text-left p-5 sm:p-6 flex items-center justify-between gap-4 hover:bg-gray-50/50 transition-colors">
                <div className="flex items-center gap-4 min-w-0">
                    <div className="w-12 h-12 rounded-xl bg-gradient-to-br from-[#045474] to-[#1c486c] flex items-center justify-center text-white text-lg font-bold shadow-md shrink-0">
                        {agent.initial}
                    </div>
                    <div className="min-w-0">
                        <div className="flex items-center gap-2 flex-wrap">
                            <span className="font-bold text-gray-900 truncate">{agent.name}</span>
                            <span className={`inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-bold ring-1 ${roleBadge}`}>{roleLabel}</span>
                        </div>
                        <p className="text-xs text-gray-500 mt-0.5">{agent.email}</p>
                    </div>
                </div>
                <div className="flex items-center gap-6 shrink-0">
                    <div className="hidden sm:flex items-center gap-5 text-center">
                        <div>
                            <p className="text-lg font-extrabold text-gray-900 tabular-nums">{agent.total_leads}</p>
                            <p className="text-[10px] font-semibold text-gray-400 uppercase tracking-wider">Total</p>
                        </div>
                        <div>
                            <p className="text-lg font-extrabold text-emerald-600 tabular-nums">{agent.won_leads}</p>
                            <p className="text-[10px] font-semibold text-gray-400 uppercase tracking-wider">Ganados</p>
                        </div>
                        <div>
                            <p className="text-lg font-extrabold text-gray-900 tabular-nums">{money(agent.won_value, currency)}</p>
                            <p className="text-[10px] font-semibold text-gray-400 uppercase tracking-wider">Ingresos</p>
                        </div>
                    </div>
                    <svg className={`w-5 h-5 text-gray-400 transition-transform ${expanded ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                    </svg>
                </div>
            </button>

            {expanded && (
                <div className="px-5 sm:px-6 pb-5 sm:pb-6 border-t border-gray-100 pt-4 space-y-5">
                    <div className="grid grid-cols-3 sm:grid-cols-5 gap-3">
                        <div className="bg-gray-50 rounded-xl p-3 text-center">
                            <p className="text-lg font-extrabold text-gray-900 tabular-nums">{agent.total_leads}</p>
                            <p className="text-[10px] font-semibold text-gray-400 uppercase mt-0.5">Total</p>
                        </div>
                        <div className="bg-emerald-50 rounded-xl p-3 text-center">
                            <p className="text-lg font-extrabold text-emerald-600 tabular-nums">{agent.won_leads}</p>
                            <p className="text-[10px] font-semibold text-emerald-500 uppercase mt-0.5">Ganados</p>
                        </div>
                        <div className="bg-red-50 rounded-xl p-3 text-center">
                            <p className="text-lg font-extrabold text-red-500 tabular-nums">{agent.lost_leads}</p>
                            <p className="text-[10px] font-semibold text-red-400 uppercase mt-0.5">Perdidos</p>
                        </div>
                        <div className="bg-amber-50 rounded-xl p-3 text-center">
                            <p className="text-lg font-extrabold text-amber-600 tabular-nums">{agent.open_leads}</p>
                            <p className="text-[10px] font-semibold text-amber-500 uppercase mt-0.5">Abiertos</p>
                        </div>
                        <div className="bg-blue-50 rounded-xl p-3 text-center col-span-3 sm:col-span-1">
                            <p className="text-lg font-extrabold text-[#045474] tabular-nums">{money(agent.won_value, currency)}</p>
                            <p className="text-[10px] font-semibold text-[#045474]/60 uppercase mt-0.5">Ingresos</p>
                        </div>
                    </div>

                    {hasLeads && conversionRate > 0 && (
                        <div>
                            <div className="flex items-center justify-between text-sm mb-1.5">
                                <span className="font-semibold text-gray-700">Conversión</span>
                                <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold ring-1 tabular-nums ${
                                    conversionRate >= 50 ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' :
                                    conversionRate >= 25 ? 'bg-amber-50 text-amber-700 ring-amber-200' :
                                    'bg-red-50 text-red-700 ring-red-200'
                                }`}>
                                    {conversionRate}%
                                </span>
                            </div>
                            <div className="h-2 bg-gray-100 rounded-full overflow-hidden">
                                <div className={`h-full rounded-full transition-all ${
                                    conversionRate >= 50 ? 'bg-gradient-to-r from-emerald-500 to-teal-600' :
                                    conversionRate >= 25 ? 'bg-gradient-to-r from-amber-400 to-orange-500' :
                                    'bg-gradient-to-r from-red-400 to-rose-500'
                                }`} style={{ width: `${Math.min(conversionRate, 100)}%` }} />
                            </div>
                        </div>
                    )}

                    {agent.by_pipeline.map((pipeline) => {
                        if (pipeline.total === 0) return null;
                        const maxStageCount = Math.max(1, ...pipeline.stages.map(s => s.count));
                        return (
                            <div key={pipeline.id}>
                                <h4 className="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3 flex items-center gap-2">
                                    <span className="w-1.5 h-1.5 rounded-full bg-[#045474]" />
                                    {pipeline.name}
                                    <span className="text-gray-400 font-normal normal-case">({pipeline.total} leads)</span>
                                </h4>
                                <div className="space-y-2.5">
                                    {pipeline.stages.map((stage) => {
                                        if (stage.count === 0) return null;
                                        const pct = Math.max((stage.count / maxStageCount) * 100, 8);
                                        const isTerminal = stage.stage_type !== 'open';
                                        return (
                                            <div key={stage.id}>
                                                <div className="flex items-center justify-between text-sm mb-1">
                                                    <span className="inline-flex items-center gap-1.5 font-medium text-gray-700">
                                                        <span className="w-2 h-2 rounded-full" style={{ backgroundColor: stage.color }} />
                                                        {stage.name}
                                                        {isTerminal && (
                                                            <span className={`text-[10px] font-bold px-1.5 py-0.5 rounded-full ${
                                                                stage.stage_type === 'won' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-600'
                                                            }`}>
                                                                {stage.stage_type === 'won' ? 'Ganado' : 'Perdido'}
                                                            </span>
                                                        )}
                                                    </span>
                                                    <span className="text-xs text-gray-500 tabular-nums">
                                                        <span className="font-bold text-gray-800">{stage.count}</span>
                                                        {stage.value > 0 && <span className="text-gray-400"> · {money(stage.value, currency)}</span>}
                                                    </span>
                                                </div>
                                                <div className="h-6 bg-gray-50 rounded-lg overflow-hidden">
                                                    <div
                                                        className="h-full rounded-lg flex items-center px-2 shadow-inner transition-all"
                                                        style={{
                                                            width: `${pct}%`,
                                                            background: `linear-gradient(90deg, ${stage.color}, ${stage.color}cc)`,
                                                        }}
                                                    >
                                                        <span className="text-[10px] font-bold text-white drop-shadow">{stage.count}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        );
                    })}

                    {!hasLeads && (
                        <div className="py-6 text-center text-sm text-gray-400">
                            Este asesor no tiene leads asignados actualmente.
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

export default function Index({ agents, pipelines, totals, isAdmin, currency }) {
    const totalLeads = totals.total_leads;
    const totalWon = totals.won_leads;
    const totalValue = totals.won_value;
    const globalConversion = totalLeads > 0 ? Math.round((totalWon / totalLeads) * 100) : 0;

    return (
        <AuthenticatedLayout>
            <Head title="Asesores" />

            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <div>
                    <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Asesores</h1>
                    <p className="text-sm text-gray-500 mt-1">Desempeño individual con desglose por pipeline</p>
                </div>

                <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-5">
                    <KpiCard
                        label="Asesores"
                        value={totals.agents}
                        gradient="from-[#045474] to-[#1c486c]"
                        iconPath="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"
                    />
                    <KpiCard
                        label="Total leads"
                        value={totals.total_leads}
                        gradient="from-blue-400 to-indigo-500"
                        iconPath="M2.25 2.25a.75.75 0 000 1.5H3v10.5a3 3 0 003 3h1.21l-1.172 3.153a.75.75 0 001.424.474l1.828-4.127h4.242l1.828 4.127a.75.75 0 001.424-.474L16.79 17.25H18a3 3 0 003-3V3.75h.75a.75.75 0 000-1.5H2.25z"
                    />
                    <KpiCard
                        label="Ganados"
                        value={totals.won_leads}
                        sub={money(totalValue, currency)}
                        gradient="from-emerald-500 to-teal-600"
                        iconPath="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.562.562 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z"
                        extra={globalConversion > 0 && (
                            <div className="mt-3 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                                <div className={`h-full rounded-full ${globalConversion >= 50 ? 'bg-gradient-to-r from-emerald-500 to-teal-600' : globalConversion >= 25 ? 'bg-gradient-to-r from-amber-400 to-orange-500' : 'bg-gradient-to-r from-red-400 to-rose-500'}`} style={{ width: `${globalConversion}%` }} />
                            </div>
                        )}
                    />
                    <KpiCard
                        label="Conversión global"
                        value={`${globalConversion}%`}
                        sub={`${totals.total_leads - totals.won_leads} abiertos/perdidos`}
                        gradient={globalConversion >= 50 ? 'from-emerald-500 to-teal-600' : globalConversion >= 25 ? 'from-amber-400 to-orange-500' : 'from-red-400 to-rose-500'}
                        iconPath="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"
                    />
                </div>

                <div className="space-y-4">
                    {agents.map((agent) => (
                        <AgentCard key={agent.id} agent={agent} currency={currency} />
                    ))}
                    {agents.length === 0 && (
                        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-10 text-center">
                            <p className="text-gray-400">No hay asesores en la cuenta.</p>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}