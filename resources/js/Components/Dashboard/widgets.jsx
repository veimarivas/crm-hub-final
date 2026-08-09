/**
 * Los widgets del dashboard, uno por export.
 *
 * Cada uno recibe SOLO su payload (`data`), nunca el objeto entero del
 * dashboard: así agregar un widget no obliga a tocar los demás, y un widget
 * apagado no puede romper a otro por leer un prop que no llegó.
 */

import { Link } from '@inertiajs/react';
import ServiceWindowBadge from '@/Components/ServiceWindowBadge';

export function money(value, currency = 'BOB') {
    const n = Number(value ?? 0);

    return new Intl.NumberFormat('es-BO', { style: 'currency', currency, maximumFractionDigits: 0 }).format(n);
}

function relativeTime(iso) {
    if (!iso) return '';
    const mins = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000));
    if (mins < 60) return `hace ${mins}m`;
    if (mins < 1440) return `hace ${Math.round(mins / 60)}h`;

    return `hace ${Math.round(mins / 1440)}d`;
}

function waitLabel(mins) {
    if (mins < 60) return `${mins} min`;
    if (mins < 1440) return `${Math.round(mins / 60)} h`;

    return `${Math.round(mins / 1440)} d`;
}

/** Card base. Todos los widgets comparten marco para que la grilla se lea pareja. */
export function WidgetCard({ title, subtitle, action, accent = 'from-[#045474] to-[#1c486c]', iconPath, children, className = '' }) {
    return (
        <div className={`bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden flex flex-col ${className}`}>
            {title && (
                <div className="p-5 border-b border-gray-100 flex items-center justify-between gap-3 bg-gradient-to-r from-gray-50 to-transparent">
                    <div className="flex items-center gap-3 min-w-0">
                        {iconPath && (
                            <div className={`w-9 h-9 rounded-xl bg-gradient-to-br ${accent} flex items-center justify-center text-white shadow-md shrink-0`}>
                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d={iconPath} />
                                </svg>
                            </div>
                        )}
                        <div className="min-w-0">
                            <h3 className="text-base font-bold text-gray-900 truncate">{title}</h3>
                            {subtitle && <p className="text-xs text-gray-400 mt-0.5 truncate">{subtitle}</p>}
                        </div>
                    </div>
                    {action}
                </div>
            )}
            <div className="flex-1 min-h-0">{children}</div>
        </div>
    );
}

function Empty({ children }) {
    return <p className="px-5 py-10 text-center text-sm text-gray-400">{children}</p>;
}

function Delta({ value, invert = false }) {
    // `null` = no hay base con la que comparar. Un 0% mentiría.
    if (value === null || value === undefined) return null;
    const positive = invert ? value < 0 : value > 0;
    const neutral = value === 0;
    const tone = neutral ? 'text-gray-400' : positive ? 'text-emerald-600' : 'text-rose-600';

    return (
        <span className={`inline-flex items-center gap-0.5 text-[11px] font-bold ${tone}`}>
            {!neutral && (
                <svg className={`w-3 h-3 ${value > 0 ? '' : 'rotate-180'}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 19V5m0 0l-7 7m7-7l7 7" />
                </svg>
            )}
            {Math.abs(value)}%
        </span>
    );
}

function Stat({ label, value, sub, gradient, iconPath, href, alert, delta, deltaInvert, deltaLabel }) {
    const body = (
        <div className={`bg-white rounded-2xl shadow-sm border p-5 relative overflow-hidden h-full transition-all hover:shadow-md ${alert ? 'border-red-200' : 'border-gray-100'}`}>
            <div className={`absolute -top-4 -right-4 w-24 h-24 rounded-full opacity-10 bg-gradient-to-br ${gradient}`} />
            <div className={`relative w-10 h-10 rounded-xl bg-gradient-to-br ${gradient} flex items-center justify-center text-white shadow-md mb-3`}>
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d={iconPath} />
                </svg>
            </div>
            <p className="text-[11px] font-semibold uppercase tracking-wider text-gray-400">{label}</p>
            <div className="flex items-baseline gap-2 mt-1">
                <p className="text-3xl font-extrabold text-gray-900 tabular-nums leading-none">{value}</p>
                <Delta value={delta} invert={deltaInvert} />
            </div>
            {sub && <p className="text-xs text-gray-500 mt-2 truncate">{sub}</p>}
            {delta !== null && delta !== undefined && deltaLabel && (
                <p className="text-[10px] text-gray-300 mt-0.5 truncate">{deltaLabel}</p>
            )}
        </div>
    );

    return href ? <Link href={href} className="block h-full">{body}</Link> : body;
}

export function KpisWidget({ data }) {
    const { stats, deltas = {}, currency } = data;
    const avgTicket = stats.wonThisMonth > 0 ? stats.wonValueThisMonth / stats.wonThisMonth : 0;

    return (
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-5">
            <Stat
                label="Leads abiertos" value={stats.openLeads}
                sub={`${money(stats.openValue, currency)} en juego`}
                gradient="from-[#045474] to-[#1c486c]"
                iconPath="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22"
                href={route('leads.index')} delta={deltas.openLeads} deltaLabel="vs. hace un mes"
            />
            <Stat
                label="Ganados este mes" value={stats.wonThisMonth}
                sub={money(stats.wonValueThisMonth, currency) + (avgTicket > 0 ? ` · avg ${money(avgTicket, currency)}` : '')}
                gradient="from-emerald-500 to-teal-600"
                iconPath="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.562.562 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z"
                href={route('leads.index')} delta={deltas.wonThisMonth} deltaLabel="vs. el mismo tramo del mes pasado"
            />
            <Stat
                label="Tareas hoy" value={stats.tasksToday}
                sub={stats.overdueTasks > 0 ? `${stats.overdueTasks} vencidas` : 'ninguna vencida'}
                gradient="from-amber-400 to-orange-500"
                iconPath="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"
                href={route('tasks.index')} alert={stats.overdueTasks > 0}
                delta={deltas.tasksToday} deltaInvert deltaLabel="vs. las que vencían ayer"
            />
            <Stat
                label="Leads sin tarea" value={stats.leadsWithoutTask}
                sub="regla Kommo: nunca dejarlo así"
                gradient="from-purple-500 to-violet-600"
                iconPath="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"
                href={route('leads.index', { no_task: 1 })} alert={stats.leadsWithoutTask > 0}
            />
        </div>
    );
}

export function UrgentLeadsWidget({ data }) {
    if (!data.count) {
        return (
            <WidgetCard title="Necesitan respuesta ya" subtitle="Nadie esperando" iconPath="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" accent="from-emerald-500 to-teal-600">
                <Empty>Nadie quedó esperando respuesta. 👌</Empty>
            </WidgetCard>
        );
    }

    return (
        <div className="bg-gradient-to-br from-red-50 via-white to-orange-50 border-2 border-red-200 rounded-2xl shadow-lg overflow-hidden">
            <div className="p-5 border-b border-red-100 flex items-center justify-between gap-3">
                <div className="flex items-center gap-3 min-w-0">
                    <div className="w-11 h-11 rounded-xl bg-gradient-to-br from-red-500 to-rose-600 flex items-center justify-center text-white shadow-lg shadow-red-500/30 shrink-0">
                        <svg className="w-5 h-5 animate-pulse" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" /></svg>
                    </div>
                    <div className="min-w-0">
                        <h3 className="text-base font-bold text-gray-900 flex items-center gap-2">
                            Necesitan respuesta ya
                            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold bg-red-600 text-white shadow tabular-nums">{data.count}</span>
                        </h3>
                        <p className="text-xs text-gray-600 mt-0.5">Sin respuesta hace más de {data.slaMinutes} min</p>
                    </div>
                </div>
                <Link href={route('inbox', { filter: 'unresponded' })} className="hidden sm:inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold text-white bg-gradient-to-r from-red-600 to-rose-600 hover:opacity-90 shadow-md shrink-0">
                    Ir al Inbox
                </Link>
            </div>
            <ul className="divide-y divide-red-100/60">
                {data.items.map((l) => (
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
                                {waitLabel(l.waiting_minutes)}
                            </span>
                        </Link>
                    </li>
                ))}
            </ul>
        </div>
    );
}

const BAND_STYLES = {
    caliente: 'bg-rose-50 text-rose-700 ring-rose-200',
    tibio: 'bg-amber-50 text-amber-700 ring-amber-200',
    frio: 'bg-sky-50 text-sky-700 ring-sky-200',
};

export function CopilotPrioritiesWidget({ data }) {
    return (
        <WidgetCard
            title="Prioridades del copiloto"
            subtitle="Alto puntaje y algo pendiente"
            accent="from-[#045474] to-[#1c486c]"
            iconPath="M13 10V3L4 14h7v7l9-11h-7z"
        >
            {data.items.length === 0 ? (
                <Empty>
                    {data.scored
                        ? 'Ningún lead prioritario tiene algo pendiente ahora.'
                        : 'Todavía sin puntuar. El copiloto calcula cada noche.'}
                </Empty>
            ) : (
                <ul className="divide-y divide-gray-50">
                    {data.items.map((l) => (
                        <li key={l.id}>
                            <Link href={route('leads.show', l.id)} className="flex items-center gap-3 px-5 py-3 hover:bg-gray-50 transition-colors">
                                <span className={`shrink-0 w-9 h-9 rounded-xl grid place-items-center text-xs font-extrabold tabular-nums ring-1 ${BAND_STYLES[l.band] ?? 'bg-gray-50 text-gray-600 ring-gray-200'}`}>
                                    {l.score}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="font-semibold text-sm text-gray-900 truncate">{l.contact || l.title}</p>
                                    {/* El motivo, no solo la acción: es lo que hace
                                        que la sugerencia se ejecute en vez de leerse. */}
                                    <p className="text-[11px] text-gray-500 truncate">
                                        <span className="font-bold text-gray-700">{l.action.label}</span>
                                        <span className="text-gray-300"> · </span>
                                        {l.action.reason}
                                    </p>
                                </div>
                                {l.stage && (
                                    <span className="hidden sm:inline-flex shrink-0 items-center px-2 py-0.5 rounded-full text-[10px] font-bold" style={{ color: l.stage.color, background: `${l.stage.color}15` }}>
                                        {l.stage.name}
                                    </span>
                                )}
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </WidgetCard>
    );
}

export function ForgottenLeadsWidget({ data }) {
    return (
        <WidgetCard
            title="Los más olvidados"
            subtitle="Abiertos sin una sola tarea"
            accent="from-purple-500 to-violet-600"
            iconPath="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"
            action={data.total > 0 && (
                <Link href={route('leads.index', { no_task: 1 })} className="shrink-0 text-[11px] font-bold text-purple-700 hover:text-purple-900">
                    Ver los {data.total} →
                </Link>
            )}
        >
            {data.items.length === 0 ? (
                <Empty>Todos los leads abiertos tienen una tarea agendada.</Empty>
            ) : (
                <ul className="divide-y divide-gray-100">
                    {data.items.map((lead) => (
                        <li key={lead.id}>
                            <Link href={route('leads.show', lead.id)} className="flex items-center gap-3 px-5 py-3 hover:bg-gray-50 transition-colors">
                                <span className="min-w-0 flex-1">
                                    <span className="block text-sm font-semibold text-gray-900 truncate">{lead.contact || lead.title}</span>
                                    <span className="block text-[11px] text-gray-400 truncate">{lead.title}</span>
                                </span>
                                <span className="shrink-0 text-xs font-semibold text-gray-500 tabular-nums">{money(lead.value, lead.currency)}</span>
                                <span className="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 tabular-nums">
                                    {lead.days_open} d
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </WidgetCard>
    );
}

export function RecentLeadsWidget({ data }) {
    return (
        <WidgetCard
            title="Leads recientes"
            subtitle="Últimos que entraron al embudo"
            iconPath="M13 10V3L4 14h7v7l9-11h-7z"
            action={<Link href={route('leads.index')} className="text-xs font-semibold text-emerald-600 hover:text-emerald-700 shrink-0">Ver todos</Link>}
        >
            {data.items.length === 0 ? (
                <Empty>Sin leads todavía. Crea el primero o conecta WhatsApp.</Empty>
            ) : (
                <ul className="divide-y divide-gray-50">
                    {data.items.map((lead) => (
                        <li key={lead.id}>
                            <Link href={route('leads.show', lead.id)} className="flex items-center gap-3 px-5 py-3.5 hover:bg-gray-50 transition-colors">
                                <div className="flex flex-col items-center gap-1 shrink-0">
                                    <span className="w-2.5 h-2.5 rounded-full ring-2 ring-white shadow" style={{ backgroundColor: lead.stage?.color ?? '#94a3b8' }} />
                                    {!lead.hasPendingTask && <span className="w-1.5 h-1.5 rounded-full bg-red-500" title="Sin tarea pendiente" />}
                                </div>
                                <div className="min-w-0 flex-1">
                                    <p className="font-semibold text-gray-900 text-sm truncate">{lead.title}</p>
                                    <p className="text-xs text-gray-500 truncate flex items-center gap-1.5">
                                        <span className="truncate">{lead.contact?.name || 'Sin contacto'}</span>
                                        <span className="text-gray-300">·</span>
                                        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold" style={{ backgroundColor: `${lead.stage?.color ?? '#94a3b8'}20`, color: lead.stage?.color ?? '#94a3b8' }}>
                                            {lead.stage?.name}
                                        </span>
                                        <ServiceWindowBadge window={lead.service_window} />
                                    </p>
                                </div>
                                <div className="text-right shrink-0 ml-3">
                                    <span className="text-sm font-bold text-gray-900 tabular-nums block">{money(lead.value, lead.currency || data.currency)}</span>
                                    {lead.created_at && <span className="text-[10px] text-gray-400">{relativeTime(lead.created_at)}</span>}
                                </div>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </WidgetCard>
    );
}

export function MyTasksWidget({ data }) {
    return (
        <WidgetCard
            title="Mis próximas tareas"
            subtitle="Por vencimiento"
            accent="from-amber-400 to-orange-500"
            iconPath="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"
            action={<Link href={route('tasks.index')} className="text-xs font-semibold text-emerald-600 hover:text-emerald-700 shrink-0">Agenda →</Link>}
        >
            {data.items.length === 0 ? (
                <Empty>Sin tareas pendientes.</Empty>
            ) : (
                <ul className="divide-y divide-gray-50">
                    {data.items.map((task) => {
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
                                            {new Date(task.due_at).toLocaleString('es', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}
                                            {overdue && <span className="uppercase font-bold">· vencida</span>}
                                        </span>
                                    </div>
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
        </WidgetCard>
    );
}

export function PipelineFunnelWidget({ data }) {
    const max = Math.max(1, ...data.steps.map((s) => s.count));

    return (
        <WidgetCard
            title="Embudo actual"
            subtitle="Leads abiertos por etapa, ahora"
            iconPath="M3 4.5h18M6.75 9.75h10.5M10.5 15h3"
        >
            {data.steps.length === 0 ? (
                <Empty>Sin etapas configuradas.</Empty>
            ) : (
                <div className="p-5 space-y-3">
                    {data.steps.map((s) => (
                        <Link key={s.id} href={route('leads.index', { stage_id: s.id })} className="block group">
                            <div className="flex items-baseline justify-between gap-2 mb-1">
                                <span className="text-xs font-semibold text-gray-600 truncate group-hover:text-gray-900">{s.name}</span>
                                <span className="text-xs tabular-nums text-gray-400 shrink-0">
                                    {s.count} · {money(s.value, data.currency)}
                                </span>
                            </div>
                            <div className="h-2 rounded-full bg-gray-100 overflow-hidden">
                                <div className="h-full rounded-full transition-all" style={{ width: `${(s.count / max) * 100}%`, background: s.color }} />
                            </div>
                        </Link>
                    ))}
                </div>
            )}
        </WidgetCard>
    );
}

export function TeamRankingWidget({ data }) {
    return (
        <WidgetCard
            title="Equipo este mes"
            subtitle="Ganados y valor cerrado"
            accent="from-emerald-500 to-teal-600"
            iconPath="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0z"
        >
            {data.items.length === 0 ? (
                <Empty>Nadie cerró todavía este mes.</Empty>
            ) : (
                <ul className="divide-y divide-gray-50">
                    {data.items.map((u, i) => (
                        <li key={u.id}>
                            <Link href={route('supervision.agent', u.id)} className="flex items-center gap-3 px-5 py-3 hover:bg-gray-50 transition-colors">
                                <span className="shrink-0 w-6 h-6 rounded-lg bg-gray-100 grid place-items-center text-[11px] font-bold text-gray-500 tabular-nums">{i + 1}</span>
                                <span className="min-w-0 flex-1 text-sm font-semibold text-gray-900 truncate">{u.name}</span>
                                <span className="shrink-0 text-xs text-gray-400 tabular-nums">{u.won} ganados</span>
                                <span className="shrink-0 text-sm font-bold text-emerald-600 tabular-nums">{money(u.value, data.currency)}</span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </WidgetCard>
    );
}

/** Mapa key → componente. El registro del servidor manda; esto solo pinta. */
export const WIDGETS = {
    kpis: KpisWidget,
    urgent_leads: UrgentLeadsWidget,
    copilot_priorities: CopilotPrioritiesWidget,
    forgotten_leads: ForgottenLeadsWidget,
    recent_leads: RecentLeadsWidget,
    my_tasks: MyTasksWidget,
    pipeline_funnel: PipelineFunnelWidget,
    team_ranking: TeamRankingWidget,
};

/** Tamaño → columnas en la grilla de 6. */
export const SPAN = {
    sm: 'lg:col-span-2',
    md: 'lg:col-span-3',
    lg: 'lg:col-span-4',
    full: 'lg:col-span-6',
};
