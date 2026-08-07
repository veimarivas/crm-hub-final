import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ServiceWindowCard } from '@/Components/ServiceWindowBadge';
import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const EVENT_META = {
    created: { icon: '✨', color: 'bg-emerald-100 text-emerald-700 border-emerald-200', label: 'Lead creado', group: 'lead' },
    stage_changed: { icon: '➡️', color: 'bg-blue-100 text-blue-700 border-blue-200', label: 'Cambio de etapa', group: 'lead' },
    won: { icon: '🏆', color: 'bg-emerald-100 text-emerald-700 border-emerald-200', label: 'Ganado', group: 'lead' },
    lost: { icon: '✕', color: 'bg-red-100 text-red-700 border-red-200', label: 'Perdido', group: 'lead' },
    reopened: { icon: '🔄', color: 'bg-sky-100 text-sky-700 border-sky-200', label: 'Reabierto', group: 'lead' },
    note_added: { icon: '📝', color: 'bg-amber-100 text-amber-700 border-amber-200', label: 'Nota', group: 'note' },
    task_created: { icon: '📋', color: 'bg-purple-100 text-purple-700 border-purple-200', label: 'Tarea creada', group: 'task' },
    task_completed: { icon: '✅', color: 'bg-emerald-100 text-emerald-700 border-emerald-200', label: 'Tarea completada', group: 'task' },
    message_in: { icon: '💬', color: 'bg-teal-100 text-teal-700 border-teal-200', label: 'WhatsApp recibido', group: 'message' },
    message_out: { icon: '📤', color: 'bg-[#e6f0f4] text-[#045474] border-[#c7dde5]', label: 'WhatsApp enviado', group: 'message' },
    booking: { icon: '📅', color: 'bg-indigo-100 text-indigo-700 border-indigo-200', label: 'Reunión reservada', group: 'lead' },
};

const FILTERS = [
    { key: 'all', label: 'Todo', icon: '⚡' },
    { key: 'message', label: 'Mensajes', icon: '💬' },
    { key: 'lead', label: 'Leads', icon: '🎯' },
    { key: 'task', label: 'Tareas', icon: '📋' },
    { key: 'note', label: 'Notas', icon: '📝' },
];

function initials(name) {
    return (name || '?').trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase();
}

function money(v, currency) {
    return 'Bs. ' + new Intl.NumberFormat('es', { maximumFractionDigits: 0 }).format(v || 0);
}

function dayLabel(iso) {
    const d = new Date(iso);
    const today = new Date();
    const yesterday = new Date(); yesterday.setDate(today.getDate() - 1);
    const same = (a, b) => a.toDateString() === b.toDateString();
    if (same(d, today)) return 'Hoy';
    if (same(d, yesterday)) return 'Ayer';
    return d.toLocaleDateString('es', { weekday: 'long', day: 'numeric', month: 'long', year: d.getFullYear() !== today.getFullYear() ? 'numeric' : undefined });
}

function groupKindFor(item) {
    if (item.kind === 'task') return 'task';
    if (item.kind === 'note') return 'note';
    const t = item.data.event_type;
    return EVENT_META[t]?.group ?? 'lead';
}

export default function Timeline({ contact, leads, events, tasks, notes, serviceWindow }) {
    const [filter, setFilter] = useState('all');

    const unified = useMemo(() => {
        const items = [];
        events.forEach(e => items.push({ kind: 'event', at: e.created_at, data: e }));
        tasks.forEach(t => items.push({ kind: 'task', at: t.due_at, data: t }));
        notes.forEach(n => items.push({ kind: 'note', at: n.created_at, data: n }));
        return items.sort((a, b) => new Date(b.at) - new Date(a.at));
    }, [events, tasks, notes]);

    const counts = useMemo(() => {
        const c = { all: unified.length, message: 0, lead: 0, task: 0, note: 0 };
        unified.forEach(i => { c[groupKindFor(i)] = (c[groupKindFor(i)] || 0) + 1; });
        return c;
    }, [unified]);

    const filtered = filter === 'all' ? unified : unified.filter(i => groupKindFor(i) === filter);

    // agrupar por día
    const grouped = useMemo(() => {
        const map = new Map();
        filtered.forEach(item => {
            const key = new Date(item.at).toDateString();
            if (!map.has(key)) map.set(key, { label: dayLabel(item.at), items: [] });
            map.get(key).items.push(item);
        });
        return Array.from(map.values());
    }, [filtered]);

    const wonLeads = leads.filter(l => l.status === 'won').length;
    const openLeads = leads.filter(l => l.status === 'open').length;

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-900">Timeline del contacto</h2>}>
            <Head title={contact.name || 'Contacto'} />

            <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <Link href={route('contacts.index')} className="text-sm text-emerald-600 hover:text-emerald-700 font-medium inline-flex items-center gap-1">
                    ← Volver a contactos
                </Link>

                {/* Header del contacto */}
                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div className="bg-gradient-to-r from-[#045474] to-[#1c486c] h-20" />
                    <div className="px-6 pb-6 -mt-10 flex flex-col sm:flex-row sm:items-end gap-5">
                        <div className="w-20 h-20 rounded-2xl bg-gradient-to-br from-[#045474] to-[#1c486c] flex items-center justify-center text-white text-2xl font-bold shadow-xl ring-4 ring-white shrink-0">
                            {initials(contact.name)}
                        </div>
                        <div className="flex-1 min-w-0">
                            <h1 className="text-2xl font-bold text-gray-900 truncate">{contact.name || 'Sin nombre'}</h1>
                            <div className="text-sm text-gray-500 flex flex-wrap gap-x-4 gap-y-1 mt-1">
                                {contact.phone && <span className="inline-flex items-center gap-1.5 font-mono"><svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" /></svg>{contact.phone}</span>}
                                {contact.email && <span className="inline-flex items-center gap-1.5 truncate"><svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg>{contact.email}</span>}
                                {contact.company && <span className="inline-flex items-center gap-1.5"><svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>{contact.company.name}</span>}
                            </div>
                            {contact.tags?.length > 0 && (
                                <div className="flex flex-wrap gap-1.5 mt-3">
                                    {contact.tags.map(t => (
                                        <span key={t.id} className="px-2 py-0.5 rounded-full text-xs font-bold text-white shadow-sm" style={{ backgroundColor: t.color }}>{t.name}</span>
                                    ))}
                                </div>
                            )}
                        </div>
                        <div className="grid grid-cols-3 gap-3 text-center shrink-0">
                            <div className="px-3 py-2 rounded-xl bg-gray-50">
                                <div className="text-xl font-extrabold text-gray-900 tabular-nums">{leads.length}</div>
                                <div className="text-[10px] font-semibold text-gray-500 uppercase tracking-wide">Leads</div>
                            </div>
                            <div className="px-3 py-2 rounded-xl bg-emerald-50">
                                <div className="text-xl font-extrabold text-emerald-700 tabular-nums">{wonLeads}</div>
                                <div className="text-[10px] font-semibold text-emerald-600 uppercase tracking-wide">Ganados</div>
                            </div>
                            <div className="px-3 py-2 rounded-xl bg-sky-50">
                                <div className="text-xl font-extrabold text-sky-700 tabular-nums">{openLeads}</div>
                                <div className="text-[10px] font-semibold text-sky-600 uppercase tracking-wide">Abiertos</div>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Ventana de servicio: de dónde vino y cuánto queda para
                    escribirle sin que Meta cobre. */}
                <div className="max-w-md">
                    <ServiceWindowCard window={serviceWindow} />
                </div>

                {/* Cards de leads del contacto */}
                {leads.length > 0 && (
                    <div>
                        <h3 className="text-sm font-bold text-gray-700 mb-3 flex items-center gap-2">
                            <span className="w-1 h-4 bg-[#045474] rounded-full" />
                            Todos sus leads ({leads.length})
                        </h3>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {leads.map(lead => (
                                <Link key={lead.id} href={route('leads.show', lead.id)} className="bg-white rounded-xl shadow-sm border border-gray-100 p-4 hover:shadow-md hover:-translate-y-0.5 transition-all group">
                                    <div className="flex items-start justify-between gap-2 mb-2">
                                        <span className="font-semibold text-gray-900 text-sm truncate flex-1 group-hover:text-[#045474] transition-colors">{lead.title}</span>
                                        <span className="text-[10px] font-bold px-2 py-0.5 rounded-full text-white shadow-sm shrink-0" style={{ backgroundColor: lead.stage?.color }}>{lead.stage?.name}</span>
                                    </div>
                                    <div className="flex items-center justify-between text-[11px]">
                                        <span className={`inline-flex items-center gap-1 font-semibold ${lead.status === 'won' ? 'text-emerald-600' : lead.status === 'lost' ? 'text-red-500' : 'text-sky-600'}`}>
                                            {lead.status === 'won' ? '🏆 Ganado' : lead.status === 'lost' ? '✕ Perdido' : '⏳ Abierto'}
                                        </span>
                                        {lead.value > 0 && <span className="font-bold text-gray-900 tabular-nums">{money(lead.value, lead.currency)}</span>}
                                    </div>
                                    {lead.responsible && (
                                        <div className="text-[10px] text-gray-400 mt-2 pt-2 border-t border-gray-50 truncate">👤 {lead.responsible.name}</div>
                                    )}
                                </Link>
                            ))}
                        </div>
                    </div>
                )}

                {/* Timeline con filtros y rail */}
                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div className="px-5 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div>
                            <h3 className="text-base font-bold text-gray-900">Actividad completa</h3>
                            <p className="text-xs text-gray-400 mt-0.5">{filtered.length} de {unified.length} eventos · orden cronológico</p>
                        </div>
                        <div className="flex flex-wrap gap-1.5">
                            {FILTERS.map(f => (
                                <button
                                    key={f.key}
                                    onClick={() => setFilter(f.key)}
                                    className={`inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold transition-all ${filter === f.key ? 'bg-[#045474] text-white shadow-md' : 'bg-gray-50 text-gray-600 hover:bg-gray-100'}`}
                                >
                                    <span>{f.icon}</span>
                                    {f.label}
                                    <span className={`px-1.5 py-0 rounded-full text-[10px] ${filter === f.key ? 'bg-white/20' : 'bg-white text-gray-500'}`}>{counts[f.key] || 0}</span>
                                </button>
                            ))}
                        </div>
                    </div>

                    <div className="max-h-[70vh] overflow-y-auto">
                        {grouped.length === 0 && (
                            <div className="p-12 text-center">
                                <div className="w-16 h-16 mx-auto rounded-2xl bg-gray-50 flex items-center justify-center mb-3">
                                    <svg className="w-7 h-7 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                </div>
                                <p className="text-sm text-gray-500 font-medium">Sin actividad</p>
                                <p className="text-xs text-gray-400 mt-1">{filter === 'all' ? 'Este contacto aún no tiene registros' : 'No hay eventos de ese tipo'}</p>
                            </div>
                        )}

                        {grouped.map((group, gi) => (
                            <div key={gi}>
                                <div className="sticky top-0 z-10 px-5 py-2 bg-gradient-to-b from-white via-white/95 to-white/80 backdrop-blur border-b border-gray-100">
                                    <span className="inline-flex items-center gap-2 text-[11px] font-bold uppercase tracking-wider text-gray-500">
                                        <span className="w-1.5 h-1.5 rounded-full bg-gray-300" />
                                        {group.label}
                                        <span className="text-gray-300 font-normal normal-case tracking-normal">· {group.items.length}</span>
                                    </span>
                                </div>
                                <ul className="relative pl-12 pr-4 py-3">
                                    {/* rail vertical */}
                                    <div className="absolute left-[26px] top-3 bottom-3 w-px bg-gradient-to-b from-gray-200 via-gray-100 to-transparent" />
                                    {group.items.map((item, idx) => {
                                        let meta, title, subtitle, extra, time, leadLink;
                                        if (item.kind === 'event') {
                                            const e = item.data;
                                            meta = EVENT_META[e.event_type] ?? { icon: '·', color: 'bg-gray-100 text-gray-600 border-gray-200', label: e.event_type };
                                            const p = e.payload ?? {};
                                            title = meta.label;
                                            subtitle = p.text ? String(p.text).substring(0, 200) + (String(p.text).length > 200 ? '…' : '') : null;
                                            time = new Date(e.created_at).toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' });
                                            leadLink = e.lead ? { id: e.lead.id, title: e.lead.title } : null;
                                            extra = e.actor ? `por ${e.actor.name}` : null;
                                        } else if (item.kind === 'task') {
                                            const t = item.data;
                                            meta = { icon: t.completed_at ? '✅' : '📋', color: t.completed_at ? 'bg-emerald-100 text-emerald-700 border-emerald-200' : 'bg-purple-100 text-purple-700 border-purple-200' };
                                            title = t.completed_at ? 'Tarea completada' : 'Tarea';
                                            subtitle = t.text;
                                            time = new Date(t.due_at).toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' });
                                            leadLink = t.lead ? { id: t.lead.id, title: t.lead.title } : null;
                                            extra = t.assignee?.name;
                                        } else {
                                            const n = item.data;
                                            meta = { icon: '📝', color: 'bg-amber-100 text-amber-700 border-amber-200' };
                                            title = 'Nota';
                                            subtitle = n.text;
                                            time = new Date(n.created_at).toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' });
                                            extra = n.author?.name ? `por ${n.author.name}` : null;
                                        }
                                        return (
                                            <li key={`${item.kind}-${idx}`} className="relative py-2.5 group">
                                                <div className={`absolute -left-[26px] top-3 w-9 h-9 rounded-xl border-2 ${meta.color} flex items-center justify-center text-base shadow-sm ring-4 ring-white`}>
                                                    {meta.icon}
                                                </div>
                                                <div className="rounded-xl border border-gray-100 bg-white hover:bg-gray-50 hover:border-gray-200 transition-all p-3">
                                                    <div className="flex items-center justify-between gap-2">
                                                        <p className="text-sm font-semibold text-gray-900 truncate">{title}</p>
                                                        <span className="text-[11px] text-gray-400 tabular-nums shrink-0">{time}</span>
                                                    </div>
                                                    {subtitle && (
                                                        <p className="text-sm text-gray-600 mt-1 whitespace-pre-wrap break-words">
                                                            {subtitle}
                                                        </p>
                                                    )}
                                                    {(leadLink || extra) && (
                                                        <div className="text-[11px] text-gray-400 mt-2 flex flex-wrap items-center gap-x-2">
                                                            {leadLink && <Link href={route('leads.show', leadLink.id)} className="text-emerald-600 hover:underline font-medium">→ {leadLink.title}</Link>}
                                                            {extra && <span>· {extra}</span>}
                                                        </div>
                                                    )}
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
