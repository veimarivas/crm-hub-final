import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ServiceWindowBadge from '@/Components/ServiceWindowBadge';
import { Head, Link } from '@inertiajs/react';

function money(value, currency) {
    return new Intl.NumberFormat('es', {
        style: 'currency',
        currency: currency || 'USD',
        maximumFractionDigits: 0,
    }).format(value || 0);
}

function relativeTime(iso) {
    if (!iso) return '';
    const diff = (new Date(iso) - new Date()) / 1000;
    const abs = Math.abs(diff);
    const rtf = new Intl.RelativeTimeFormat('es', { numeric: 'auto' });
    if (abs < 60) return rtf.format(Math.round(diff), 'second');
    if (abs < 3600) return rtf.format(Math.round(diff / 60), 'minute');
    if (abs < 86400) return rtf.format(Math.round(diff / 3600), 'hour');
    return rtf.format(Math.round(diff / 86400), 'day');
}

function Stat({ label, value, sub, gradient, iconPath, href, alert, tone }) {
    const toneRing = alert
        ? 'border-red-200 ring-1 ring-red-100'
        : tone === 'success'
            ? 'border-emerald-100'
            : 'border-gray-100';
    const inner = (
        <div className={`group bg-white rounded-2xl shadow-sm border ${toneRing} p-5 transition-all hover:shadow-lg hover:-translate-y-0.5 h-full flex flex-col`}>
            <div className="flex items-start justify-between">
                <div className={`w-11 h-11 rounded-xl bg-gradient-to-br ${gradient} flex items-center justify-center text-white shadow-lg`}>
                    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
                        <path strokeLinecap="round" strokeLinejoin="round" d={iconPath} />
                    </svg>
                </div>
                {alert && (
                    <span className="inline-flex items-center gap-1 text-[10px] font-bold text-red-600 bg-red-50 ring-1 ring-red-200 rounded-full px-2 py-0.5 uppercase tracking-wide">
                        <span className="w-1.5 h-1.5 rounded-full bg-red-500 animate-pulse" />
                        Atención
                    </span>
                )}
            </div>
            <p className="text-[11px] font-semibold uppercase tracking-wider text-gray-400 mt-4">{label}</p>
            <p className="text-3xl font-extrabold text-gray-900 mt-1 tabular-nums leading-none">{value}</p>
            {sub && <p className="text-xs text-gray-500 mt-2 truncate">{sub}</p>}
            {href && (
                <span className="mt-3 text-[11px] font-semibold text-gray-400 group-hover:text-emerald-600 transition-colors inline-flex items-center gap-1">
                    Ver detalle
                    <svg className="w-3 h-3 transition-transform group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" /></svg>
                </span>
            )}
        </div>
    );
    return href ? <Link href={href} className="block h-full">{inner}</Link> : inner;
}

const STATUS_STYLES = {
    open: 'bg-sky-50 text-sky-700 ring-sky-200',
    won: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    lost: 'bg-red-50 text-red-700 ring-red-200',
};

function waitLabel(mins) {
    if (mins < 60) return `${mins} min`;
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return m > 0 ? `${h}h ${m}m` : `${h}h`;
}

export default function Dashboard({ stats, recentLeads, myTasks, currency, urgentLeads = [], slaMinutes = 30 }) {
    const avgTicket = stats.wonThisMonth > 0 ? stats.wonValueThisMonth / stats.wonThisMonth : 0;

    return (
        <AuthenticatedLayout>
            <Head title="Dashboard" />

            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6 sm:space-y-8">
                {/* Header con acciones rápidas */}
                <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Dashboard</h1>
                        <p className="text-sm text-gray-500 mt-1">Tu embudo de ventas de un vistazo</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Link href={route('leads.index')} className="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold bg-gradient-to-r from-[#045474] to-[#1c486c] text-white shadow-lg shadow-[#045474]/20 hover:opacity-90 transition-all">
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            Nuevo lead
                        </Link>
                        <Link href={route('tasks.index')} className="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold bg-white border border-gray-200 text-gray-700 hover:bg-gray-50 shadow-sm transition-all">
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" /></svg>
                            Agenda
                        </Link>
                        <Link href={route('reports.index')} className="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold bg-white border border-gray-200 text-gray-700 hover:bg-gray-50 shadow-sm transition-all">
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>
                            Reportes
                        </Link>
                    </div>
                </div>

                {/* Widget de urgencia: leads que necesitan respuesta */}
                {urgentLeads.length > 0 && (
                    <div className="bg-gradient-to-br from-red-50 via-white to-orange-50 border-2 border-red-200 rounded-2xl shadow-lg overflow-hidden">
                        <div className="p-5 border-b border-red-100 flex items-center justify-between gap-3 bg-gradient-to-r from-red-500/5 to-transparent">
                            <div className="flex items-center gap-3 min-w-0">
                                <div className="w-11 h-11 rounded-xl bg-gradient-to-br from-red-500 to-rose-600 flex items-center justify-center text-white shadow-lg shadow-red-500/30 shrink-0">
                                    <svg className="w-5 h-5 animate-pulse" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" /></svg>
                                </div>
                                <div className="min-w-0">
                                    <h3 className="text-base font-bold text-gray-900 flex items-center gap-2">
                                        Necesitan respuesta ya
                                        <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold bg-red-600 text-white shadow tabular-nums">
                                            {stats.urgentLeads}
                                        </span>
                                    </h3>
                                    <p className="text-xs text-gray-600 mt-0.5">Leads con mensaje entrante sin respuesta hace más de {slaMinutes} min</p>
                                </div>
                            </div>
                            <Link href={route('inbox', { filter: 'unresponded' })} className="hidden sm:inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold text-white bg-gradient-to-r from-red-600 to-rose-600 hover:opacity-90 shadow-md shadow-red-500/20 shrink-0">
                                Ir al Inbox
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" /></svg>
                            </Link>
                        </div>
                        <ul className="divide-y divide-red-100/60">
                            {urgentLeads.map((l) => (
                                <li key={l.id}>
                                    <Link href={route('leads.show', l.id)} className="flex items-center gap-3 px-5 py-3 hover:bg-red-50/60 transition-colors">
                                        <span className="w-2 h-2 rounded-full bg-red-500 animate-pulse shrink-0" />
                                        <div className="min-w-0 flex-1">
                                            <p className="font-semibold text-sm text-gray-900 truncate">{l.contact?.name || l.contact?.phone || 'Sin contacto'}</p>
                                            <p className="text-[11px] text-gray-500 truncate">
                                                {l.title}
                                                {l.stage && <span className="text-gray-300"> · <span style={{ color: l.stage.color }}>{l.stage.name}</span></span>}
                                            </p>
                                        </div>
                                        <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold text-red-700 bg-red-100 border border-red-200 tabular-nums shrink-0">
                                            <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                            {waitLabel(l.waiting_minutes)}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                        {stats.urgentLeads > urgentLeads.length && (
                            <div className="px-5 py-2.5 bg-red-50/50 border-t border-red-100 text-center">
                                <Link href={route('inbox', { filter: 'unresponded' })} className="text-xs font-bold text-red-700 hover:text-red-900">
                                    Ver los {stats.urgentLeads - urgentLeads.length} restantes en el Inbox →
                                </Link>
                            </div>
                        )}
                    </div>
                )}

                {/* KPIs */}
                <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-5">
                    <Stat
                        label="Leads abiertos"
                        value={stats.openLeads}
                        sub={money(stats.openValue, currency) + ' en juego'}
                        gradient="from-[#045474] to-[#1c486c] shadow-[#045474]/20"
                        iconPath="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22"
                        href={route('leads.index')}
                    />
                    <Stat
                        label="Ganados este mes"
                        value={stats.wonThisMonth}
                        sub={money(stats.wonValueThisMonth, currency) + (avgTicket > 0 ? ` · avg ${money(avgTicket, currency)}` : '')}
                        gradient="from-emerald-500 to-teal-600 shadow-emerald-500/20"
                        iconPath="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.562.562 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z"
                        href={route('leads.index')}
                        tone="success"
                    />
                    <Stat
                        label="Tareas hoy"
                        value={stats.tasksToday}
                        sub={stats.overdueTasks > 0 ? `${stats.overdueTasks} vencidas` : 'ninguna vencida'}
                        gradient="from-amber-400 to-orange-500 shadow-amber-500/20"
                        iconPath="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"
                        href={route('tasks.index')}
                        alert={stats.overdueTasks > 0}
                    />
                    <Stat
                        label="Leads sin tarea"
                        value={stats.leadsWithoutTask}
                        sub="regla Kommo: nunca dejarlo así"
                        gradient="from-purple-500 to-violet-600 shadow-purple-500/20"
                        iconPath="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"
                        href={route('leads.index')}
                        alert={stats.leadsWithoutTask > 0}
                    />
                </div>

                <div className="grid gap-6 lg:grid-cols-5">
                    {/* Leads recientes */}
                    <div className="lg:col-span-3 bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="p-5 border-b border-gray-100 flex items-center justify-between bg-gradient-to-r from-gray-50 to-transparent">
                            <div className="flex items-center gap-3">
                                <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-[#045474] to-[#1c486c] flex items-center justify-center text-white shadow-md">
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                                </div>
                                <div>
                                    <h3 className="text-base font-bold text-gray-900">Leads recientes</h3>
                                    <p className="text-xs text-gray-400 mt-0.5">Últimos que entraron al embudo</p>
                                </div>
                            </div>
                            <Link href={route('leads.index')} className="text-xs font-semibold text-emerald-600 hover:text-emerald-700 inline-flex items-center gap-1">
                                Ver todos
                                <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" /></svg>
                            </Link>
                        </div>
                        <ul className="divide-y divide-gray-50">
                            {recentLeads.map((lead) => {
                                const noTask = !lead.hasPendingTask;
                                return (
                                    <li key={lead.id}>
                                        <Link href={route('leads.show', lead.id)} className="flex items-center gap-3 px-5 py-3.5 hover:bg-gray-50 transition-colors">
                                            <div className="flex flex-col items-center gap-1 shrink-0">
                                                <span className="w-2.5 h-2.5 rounded-full ring-2 ring-white shadow" style={{ backgroundColor: lead.stage?.color ?? '#94a3b8' }} />
                                                {noTask && <span className="w-1.5 h-1.5 rounded-full bg-red-500" title="Sin tarea pendiente" />}
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <p className="font-semibold text-gray-900 text-sm truncate">{lead.title}</p>
                                                <p className="text-xs text-gray-500 truncate flex items-center gap-1.5">
                                                    <span className="truncate">{lead.contact?.name || 'Sin contacto'}</span>
                                                    <span className="text-gray-300">·</span>
                                                    <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold" style={{ backgroundColor: `${lead.stage?.color ?? '#94a3b8'}20`, color: lead.stage?.color ?? '#94a3b8' }}>
                                                        {lead.stage?.name}
                                                    </span>
                                                    <ServiceWindowBadge window={lead.service_window} />
                                                </p>
                                            </div>
                                            <div className="text-right shrink-0 ml-3">
                                                <span className="text-sm font-bold text-gray-900 tabular-nums block">
                                                    {money(lead.value, lead.currency || currency)}
                                                </span>
                                                {lead.created_at && (
                                                    <span className="text-[10px] text-gray-400">{relativeTime(lead.created_at)}</span>
                                                )}
                                            </div>
                                        </Link>
                                    </li>
                                );
                            })}
                            {recentLeads.length === 0 && (
                                <li className="px-5 py-16 text-center">
                                    <div className="w-14 h-14 mx-auto rounded-2xl bg-gray-50 flex items-center justify-center mb-3">
                                        <svg className="w-6 h-6 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                    </div>
                                    <p className="text-sm text-gray-500 font-medium">Sin leads todavía</p>
                                    <p className="text-xs text-gray-400 mt-1">Crea el primero o conecta WhatsApp</p>
                                </li>
                            )}
                        </ul>
                    </div>

                    {/* Mis tareas */}
                    <div className="lg:col-span-2 bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="p-5 border-b border-gray-100 flex items-center justify-between bg-gradient-to-r from-gray-50 to-transparent">
                            <div className="flex items-center gap-3">
                                <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-400 to-orange-500 flex items-center justify-center text-white shadow-md">
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                </div>
                                <div>
                                    <h3 className="text-base font-bold text-gray-900">Mis próximas tareas</h3>
                                    <p className="text-xs text-gray-400 mt-0.5">Por vencimiento</p>
                                </div>
                            </div>
                            <Link href={route('tasks.index')} className="text-xs font-semibold text-emerald-600 hover:text-emerald-700">
                                Agenda →
                            </Link>
                        </div>
                        <ul className="divide-y divide-gray-50">
                            {myTasks.map((task) => {
                                const overdue = !task.completed_at && new Date(task.due_at) < new Date();
                                return (
                                    <li key={task.id} className="px-5 py-3.5 hover:bg-gray-50 transition-colors">
                                        <div className="flex items-start gap-3">
                                            <span className={`mt-1 w-2 h-2 rounded-full shrink-0 ${overdue ? 'bg-red-500 animate-pulse' : 'bg-amber-400'}`} />
                                            <div className="min-w-0 flex-1">
                                                <p className="font-medium text-gray-900 text-sm truncate">{task.text}</p>
                                                {task.lead && (
                                                    <Link href={route('leads.show', task.lead.id)} className="text-[11px] text-gray-500 hover:text-emerald-600 truncate block">
                                                        → {task.lead.title}
                                                    </Link>
                                                )}
                                                <span className={`inline-flex items-center gap-1 text-[10px] font-semibold mt-1 tabular-nums ${overdue ? 'text-red-600' : 'text-gray-500'}`}>
                                                    <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                                    {new Date(task.due_at).toLocaleString('es', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}
                                                    {overdue && <span className="text-red-600 uppercase font-bold">· vencida</span>}
                                                </span>
                                            </div>
                                        </div>
                                    </li>
                                );
                            })}
                            {myTasks.length === 0 && (
                                <li className="px-5 py-16 text-center">
                                    <div className="w-14 h-14 mx-auto rounded-2xl bg-emerald-50 flex items-center justify-center mb-3">
                                        <svg className="w-6 h-6 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                    </div>
                                    <p className="text-sm text-gray-500 font-medium">Sin tareas pendientes</p>
                                    <p className="text-xs text-gray-400 mt-1">¡Al día! 🎉</p>
                                </li>
                            )}
                        </ul>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
