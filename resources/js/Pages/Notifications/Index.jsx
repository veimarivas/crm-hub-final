import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ServiceWindowBadge from '@/Components/ServiceWindowBadge';
import { Head, Link, router } from '@inertiajs/react';

/**
 * Cada tipo de aviso con su color propio: `gradient` para el icono y `badge`
 * para la etiqueta. Antes todas las etiquetas eran del mismo gris, así que
 * había que leerlas una por una para distinguir un lead nuevo de una tarea
 * vencida. El color agrupa por urgencia:
 *   rojo = algo se rompió o venció · ámbar = ojo con esto
 *   verde = entró trabajo nuevo    · azul/violeta = informativo
 */
const TYPE_META = {
    lead_assigned: { icon: '👤', gradient: 'from-[#045474] to-[#1c486c]', label: 'Lead asignado', badge: 'bg-[#045474]/10 text-[#045474] ring-[#045474]/20' },
    lead_created_whatsapp: { icon: '💬', gradient: 'from-emerald-500 to-teal-600', label: 'Lead de WhatsApp', badge: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    lead_created_web_form: { icon: '📋', gradient: 'from-purple-500 to-violet-600', label: 'Lead de formulario', badge: 'bg-purple-50 text-purple-700 ring-purple-200' },
    task_overdue: { icon: '⏰', gradient: 'from-red-500 to-rose-600', label: 'Tarea vencida', badge: 'bg-red-50 text-red-700 ring-red-200' },
    team_note: { icon: '📝', gradient: 'from-sky-500 to-indigo-600', label: 'Nota del admin', badge: 'bg-sky-50 text-sky-700 ring-sky-200' },
    team_reminder: { icon: '🔔', gradient: 'from-amber-500 to-orange-600', label: 'Recordatorio', badge: 'bg-amber-50 text-amber-700 ring-amber-200' },
    lead_fully_paid: { icon: '💰', gradient: 'from-emerald-500 to-green-600', label: 'Lead pagado', badge: 'bg-green-50 text-green-700 ring-green-200' },
    ai_unavailable: { icon: '⚠️', gradient: 'from-red-500 to-rose-600', label: 'La IA no respondió', badge: 'bg-red-50 text-red-700 ring-red-200' },
    ai_limit_reached: { icon: '🤖', gradient: 'from-amber-500 to-orange-600', label: 'Tope de la IA', badge: 'bg-amber-50 text-amber-700 ring-amber-200' },
};

const FALLBACK_TYPE = { icon: '🔔', gradient: 'from-slate-500 to-slate-700', label: 'Aviso', badge: 'bg-gray-100 text-gray-500 ring-gray-200' };

/** Estado del lead al que apunta el aviso: se lee antes que el texto. */
const LEAD_STATUS = {
    open: { label: 'Abierto', icon: '⏳', className: 'bg-sky-50 text-sky-700 ring-sky-200' },
    won: { label: 'Ganado', icon: '🏆', className: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    lost: { label: 'Perdido', icon: '✕', className: 'bg-red-50 text-red-700 ring-red-200' },
};

/**
 * Avatar del contacto con color derivado del nombre. El mismo contacto
 * siempre sale del mismo color, así se lo reconoce recorriendo la lista sin
 * tener que leer.
 */
const AVATAR_COLORS = [
    'from-emerald-500 to-teal-600',
    'from-blue-500 to-indigo-600',
    'from-purple-500 to-pink-600',
    'from-amber-500 to-orange-600',
    'from-rose-500 to-red-600',
    'from-cyan-500 to-sky-600',
];

function ContactAvatar({ name }) {
    const label = (name || '?').trim();
    const initials = label.split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '?';
    let hash = 0;
    for (let i = 0; i < label.length; i++) hash = (hash * 31 + label.charCodeAt(i)) | 0;
    const gradient = AVATAR_COLORS[Math.abs(hash) % AVATAR_COLORS.length];

    return (
        <span className={`w-8 h-8 shrink-0 rounded-full bg-gradient-to-br ${gradient} flex items-center justify-center text-white text-[11px] font-bold shadow-sm`}>
            {initials}
        </span>
    );
}

/** Apartado del aviso. Solo lo llevan los que manda el admin a mano. */
const CATEGORY_META = {
    seguimiento: { label: 'Seguimiento', icon: '🎯', className: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    personal: { label: 'Personal', icon: '👤', className: 'bg-sky-50 text-sky-700 ring-sky-200' },
    marketing: { label: 'Marketing', icon: '📣', className: 'bg-violet-50 text-violet-700 ring-violet-200' },
};

const TAB_META = [
    { key: 'all', label: 'Todas' },
    { key: 'unread', label: 'Nuevas' },
    { key: 'read', label: 'Leídas' },
];

function timeAgo(iso) {
    const diff = (Date.now() - new Date(iso).getTime()) / 1000;
    if (diff < 60) return 'hace un momento';
    if (diff < 3600) return `hace ${Math.floor(diff / 60)} min`;
    if (diff < 86400) return `hace ${Math.floor(diff / 3600)} h`;
    return new Date(iso).toLocaleDateString('es', { day: 'numeric', month: 'short' });
}

function exactTime(iso) {
    return new Date(iso).toLocaleString('es', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });
}

/**
 * Agrupa por cercanía, no por fecha exacta: "Hoy / Ayer / Esta semana /
 * Anteriores" se lee mucho más rápido que una lista de fechas sueltas.
 */
function groupByDate(items) {
    const startOfToday = new Date();
    startOfToday.setHours(0, 0, 0, 0);
    const startOfYesterday = new Date(startOfToday);
    startOfYesterday.setDate(startOfYesterday.getDate() - 1);
    const weekAgo = new Date(startOfToday);
    weekAgo.setDate(weekAgo.getDate() - 7);

    const groups = { today: [], yesterday: [], week: [], earlier: [] };

    items.forEach((n) => {
        const d = new Date(n.created_at);
        if (d >= startOfToday) groups.today.push(n);
        else if (d >= startOfYesterday) groups.yesterday.push(n);
        else if (d >= weekAgo) groups.week.push(n);
        else groups.earlier.push(n);
    });

    return groups;
}

const GROUP_LABELS = {
    today: 'Hoy',
    yesterday: 'Ayer',
    week: 'Esta semana',
    earlier: 'Anteriores',
};

/** Navega manteniendo el resto de filtros; `page` se reinicia a propósito. */
function navigate(params) {
    router.get(route('notifications'), params, { preserveScroll: true, preserveState: false });
}

function NotificationRow({ n }) {
    const meta = TYPE_META[n.type] ?? FALLBACK_TYPE;
    const category = CATEGORY_META[n.category];
    const unread = !n.read_at;

    const status = n.lead ? LEAD_STATUS[n.lead.status] : null;

    return (
        <li className={`group relative grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_18rem_9rem] gap-x-6 gap-y-3 px-5 py-4 transition-colors hover:bg-gray-50/80 ${unread ? 'bg-emerald-50/30' : ''}`}>
            {/* Barra de acento: identifica lo no leído sin teñir toda la fila. */}
            {unread && <span className="absolute left-0 inset-y-0 w-1 bg-emerald-500" />}

            {/* Columna 1: qué pasó */}
            <div className="flex items-start gap-4 min-w-0">
                <div className={`w-10 h-10 shrink-0 rounded-xl bg-gradient-to-br ${meta.gradient} flex items-center justify-center text-white text-lg shadow-sm`}>
                    {meta.icon}
                </div>
                <div className="min-w-0">
                    <p className={`text-sm ${unread ? 'font-bold text-gray-900' : 'font-semibold text-gray-600'}`}>
                        {n.title}
                    </p>
                    <div className="flex flex-wrap items-center gap-1.5 mt-1.5">
                        {category ? (
                            <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-[11px] font-bold ring-1 ${category.className}`}>
                                {category.icon} {category.label}
                            </span>
                        ) : (
                            <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-[11px] font-bold ring-1 ${meta.badge}`}>
                                {meta.icon} {meta.label}
                            </span>
                        )}
                        {n.sender && <span className="text-[11px] text-gray-400">de {n.sender.name}</span>}
                    </div>
                    {n.body && <p className="mt-2 text-sm text-gray-600 leading-relaxed whitespace-pre-line">{n.body}</p>}
                </div>
            </div>

            {/* Columna 2: de quién es. El avatar toma su color del nombre, así
                el mismo contacto se reconoce de un vistazo en toda la lista. */}
            <div className="min-w-0 lg:pl-0 pl-14">
                {n.lead ? (
                    <Link href={route('notifications.go', n.id)} className="flex items-start gap-2.5 group/lead">
                        <ContactAvatar name={n.lead.contact?.name || n.lead.title} />
                        <div className="min-w-0">
                            <p className="text-sm font-semibold text-gray-900 truncate group-hover/lead:text-emerald-700 transition-colors">
                                {n.lead.contact?.name || n.lead.title}
                            </p>
                            <div className="flex flex-wrap items-center gap-1.5 mt-1">
                                {status && (
                                    <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-[11px] font-bold ring-1 ${status.className}`}>
                                        {status.icon} {status.label}
                                    </span>
                                )}
                                {n.lead.stage && (
                                    <span
                                        className="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-[11px] font-semibold"
                                        style={{ backgroundColor: `${n.lead.stage.color ?? '#94a3b8'}1a`, color: n.lead.stage.color ?? '#64748b' }}
                                    >
                                        {n.lead.stage.name}
                                    </span>
                                )}
                            </div>
                            {n.service_window && (
                                <div className="mt-1">
                                    <ServiceWindowBadge window={n.service_window} showOrigin />
                                </div>
                            )}
                        </div>
                    </Link>
                ) : (
                    <span className="text-xs text-gray-300 italic">Sin lead asociado</span>
                )}
            </div>

            {/* Columna 3: cuándo y qué hacer */}
            <div className="flex lg:flex-col lg:items-end items-center gap-2 lg:pl-0 pl-14">
                <span className="text-xs text-gray-400 tabular-nums whitespace-nowrap" title={exactTime(n.created_at)}>
                    {timeAgo(n.created_at)}
                </span>
                <button
                    type="button"
                    onClick={() => router.post(route('notifications.read', n.id), {}, { preserveScroll: true })}
                    className={`text-xs font-semibold transition-colors whitespace-nowrap ${
                        unread ? 'text-emerald-600 hover:text-emerald-800' : 'text-gray-300 hover:text-gray-500'
                    }`}
                >
                    {unread ? '✓ Marcar leída' : 'Marcar nueva'}
                </button>
            </div>
        </li>
    );
}

export default function Index({ notifications, tab, category, counts, categoryCounts }) {
    const groups = groupByDate(notifications.data);
    const hasMore = notifications.prev_page_url || notifications.next_page_url;
    const anyCategory = Object.values(categoryCounts).some((c) => c > 0);

    const emptyMessage = {
        unread: 'No tenés notificaciones sin leer. Todo al día.',
        read: 'Todavía no marcaste ninguna como leída.',
        all: 'Te avisaremos de leads asignados, nuevos leads, tareas vencidas y avisos del equipo.',
    }[tab];

    return (
        <AuthenticatedLayout>
            <Head title="Notificaciones" />

            {/* Ancho completo: cada fila reparte el espacio en columnas
                (qué pasó / de quién es / cuándo), así que aprovecha el ancho
                en vez de estirar una sola línea de texto. */}
            <div className="w-full px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-5">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-[#045474] to-[#1c486c] flex items-center justify-center text-white shadow-lg shadow-[#045474]/20 shrink-0">
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                            </svg>
                        </div>
                        <div>
                            <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Notificaciones</h1>
                            <p className="text-sm text-gray-500 mt-0.5">
                                {counts.unread > 0
                                    ? `${counts.unread} sin leer de ${counts.all}`
                                    : 'Todo al día'}
                            </p>
                        </div>
                    </div>
                    {counts.unread > 0 && (
                        <button
                            onClick={() => router.post(route('notifications.read-all'), {}, { preserveScroll: true })}
                            className="px-4 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 transition-all shadow-lg shadow-emerald-500/20 shrink-0"
                        >
                            ✓ Marcar todas como leídas
                        </button>
                    )}
                </div>

                <div className="space-y-3">
                    <div className="inline-flex bg-white rounded-xl border border-gray-200 p-1 shadow-sm">
                        {TAB_META.map((t) => {
                            const active = tab === t.key;
                            return (
                                <button
                                    key={t.key}
                                    onClick={() => navigate({ tab: t.key, category })}
                                    className={`px-4 py-2 rounded-lg text-sm font-semibold transition-colors flex items-center gap-2 ${
                                        active ? 'bg-[#045474] text-white shadow-sm' : 'text-gray-600 hover:bg-gray-100'
                                    }`}
                                >
                                    {t.label}
                                    <span className={`px-1.5 py-0.5 rounded-md text-[11px] font-bold tabular-nums ${
                                        active ? 'bg-white/20' : t.key === 'unread' && counts.unread > 0 ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500'
                                    }`}>
                                        {counts[t.key]}
                                    </span>
                                </button>
                            );
                        })}
                    </div>

                    {anyCategory && (
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-[11px] font-bold uppercase tracking-wider text-gray-400">Apartado</span>
                            <button
                                onClick={() => navigate({ tab })}
                                className={`px-2.5 py-1 rounded-lg text-xs font-semibold ring-1 transition-colors ${
                                    !category ? 'bg-gray-900 text-white ring-gray-900' : 'bg-white text-gray-500 ring-gray-200 hover:ring-gray-300'
                                }`}
                            >
                                Todos
                            </button>
                            {Object.entries(CATEGORY_META).map(([key, meta]) => (
                                <button
                                    key={key}
                                    onClick={() => navigate({ tab, category: category === key ? undefined : key })}
                                    disabled={categoryCounts[key] === 0 && category !== key}
                                    className={`px-2.5 py-1 rounded-lg text-xs font-semibold ring-1 transition-colors disabled:opacity-40 disabled:cursor-not-allowed ${
                                        category === key ? `${meta.className} ring-2` : 'bg-white text-gray-500 ring-gray-200 hover:ring-gray-300'
                                    }`}
                                >
                                    {meta.icon} {meta.label}
                                    <span className="ml-1 tabular-nums opacity-60">{categoryCounts[key]}</span>
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                {notifications.data.length === 0 ? (
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-12 sm:p-16 text-center">
                        <div className="w-14 h-14 mx-auto rounded-2xl bg-gradient-to-br from-gray-100 to-gray-200 flex items-center justify-center mb-4">
                            <svg className="w-6 h-6 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                            </svg>
                        </div>
                        <p className="text-base font-semibold text-gray-900">Nada por acá</p>
                        <p className="text-sm text-gray-500 mt-1 max-w-sm mx-auto">{emptyMessage}</p>
                    </div>
                ) : (
                    <div className="space-y-5">
                        {Object.entries(groups).map(([key, items]) => items.length > 0 && (
                            <div key={key}>
                                <h3 className="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2 px-1">
                                    {GROUP_LABELS[key]}
                                    <span className="ml-1.5 font-semibold text-gray-300 tabular-nums">{items.length}</span>
                                </h3>
                                <ul className="bg-white rounded-2xl shadow-sm border border-gray-100 divide-y divide-gray-50 overflow-hidden">
                                    {items.map((n) => <NotificationRow key={n.id} n={n} />)}
                                </ul>
                            </div>
                        ))}
                    </div>
                )}

                {hasMore && (
                    <div className="flex items-center justify-between pt-1">
                        <p className="text-sm text-gray-400">
                            {notifications.from}–{notifications.to} de {notifications.total}
                        </p>
                        <div className="flex gap-2">
                            {notifications.prev_page_url && (
                                <Link
                                    href={notifications.prev_page_url}
                                    className="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-all shadow-sm"
                                >
                                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M19 12H5m7-7l-7 7 7 7" /></svg>
                                    Anteriores
                                </Link>
                            )}
                            {notifications.next_page_url && (
                                <Link
                                    href={notifications.next_page_url}
                                    className="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 transition-all shadow-lg shadow-emerald-500/20"
                                >
                                    Siguientes
                                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M5 12h14m-7-7l7 7-7 7" /></svg>
                                </Link>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
