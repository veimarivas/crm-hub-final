import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const AVATAR_COLORS = [
    'from-emerald-500 to-teal-600',
    'from-blue-500 to-indigo-600',
    'from-purple-500 to-pink-600',
    'from-amber-500 to-orange-600',
    'from-rose-500 to-red-600',
    'from-cyan-500 to-sky-600',
];

function avatarFor(name) {
    const label = (name || '?').trim();
    const initials = label.split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '?';
    let hash = 0;
    for (let i = 0; i < label.length; i++) hash = (hash * 31 + label.charCodeAt(i)) | 0;
    return { initials, gradient: AVATAR_COLORS[Math.abs(hash) % AVATAR_COLORS.length] };
}

function Avatar({ name }) {
    const { initials, gradient } = avatarFor(name);
    return (
        <div className={`w-11 h-11 rounded-full bg-gradient-to-br ${gradient} flex items-center justify-center text-white text-sm font-bold shadow-sm shrink-0`}>
            {initials}
        </div>
    );
}

function relativeTime(iso) {
    if (!iso) return '';
    const diff = (new Date(iso) - new Date()) / 1000;
    const abs = Math.abs(diff);
    const rtf = new Intl.RelativeTimeFormat('es', { numeric: 'auto' });
    if (abs < 60) return 'ahora';
    if (abs < 3600) return rtf.format(Math.round(diff / 60), 'minute');
    if (abs < 86400) return rtf.format(Math.round(diff / 3600), 'hour');
    if (abs < 604800) return rtf.format(Math.round(diff / 86400), 'day');
    return new Date(iso).toLocaleDateString('es', { day: 'numeric', month: 'short' });
}

function waitLabel(mins) {
    if (mins < 60) return `${mins} min`;
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return m > 0 ? `${h}h ${m}m` : `${h}h`;
}

export default function InboxIndex({ items, counts, filter, q, isAdmin, slaMinutes }) {
    const [query, setQuery] = useState(q || '');

    // debounce búsqueda
    useEffect(() => {
        if (query === (q || '')) return;
        const t = setTimeout(() => {
            router.get(route('inbox'), { filter, q: query }, { preserveState: true, preserveScroll: true, replace: true });
        }, 300);
        return () => clearTimeout(t);
    }, [query]);

    // poll ligero cada 15s para mantener la bandeja fresca
    useEffect(() => {
        const id = setInterval(() => {
            if (document.hidden) return;
            router.reload({ only: ['items', 'counts'] });
        }, 15000);
        return () => clearInterval(id);
    }, []);

    // El agente solo tiene sus propias conversaciones: las pestañas de leads
    // sin asignar y de todo el equipo son del admin, que es quien reparte.
    const filters = [
        { key: 'mine', label: 'Mías', icon: '👤', tone: 'emerald' },
        ...(isAdmin ? [{ key: 'unassigned', label: 'Sin asignar', icon: '🆕', tone: 'sky' }] : []),
        { key: 'unresponded', label: `Sin responder ${slaMinutes}m+`, icon: '🚨', tone: 'red' },
        ...(isAdmin ? [{ key: 'all', label: 'Todo el equipo', icon: '🌐', tone: 'slate' }] : []),
    ];

    return (
        <AuthenticatedLayout>
            <Head title="Inbox" />

            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8">
                {/* Header */}
                <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4 mb-6">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Inbox</h1>
                        <p className="text-sm text-gray-500 mt-1">Todas tus conversaciones activas en un solo lugar</p>
                    </div>
                    <div className="relative w-full sm:w-72">
                        <svg className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
                        <input
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="Buscar por nombre, teléfono, título…"
                            className="w-full pl-9 pr-3 py-2.5 bg-white border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 shadow-sm"
                        />
                        {query && (
                            <button onClick={() => setQuery('')} className="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 p-1">
                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                            </button>
                        )}
                    </div>
                </div>

                {/* Filtros */}
                <div className="flex flex-wrap gap-2 mb-5">
                    {filters.map((f) => {
                        const active = filter === f.key;
                        const count = counts[f.key] ?? 0;
                        const toneActive = {
                            emerald: 'bg-emerald-600 text-white shadow-lg shadow-emerald-500/20',
                            sky: 'bg-sky-600 text-white shadow-lg shadow-sky-500/20',
                            red: 'bg-red-600 text-white shadow-lg shadow-red-500/20',
                            slate: 'bg-slate-800 text-white shadow-lg shadow-slate-500/20',
                        }[f.tone];
                        return (
                            <Link
                                key={f.key}
                                href={route('inbox', { filter: f.key, q: query })}
                                preserveScroll
                                className={`inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-sm font-semibold transition-all ${active ? toneActive : 'bg-white text-gray-700 border border-gray-200 hover:bg-gray-50'}`}
                            >
                                <span>{f.icon}</span>
                                {f.label}
                                <span className={`px-1.5 py-0 rounded-full text-[11px] tabular-nums ${active ? 'bg-white/20' : count > 0 && f.tone === 'red' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600'}`}>
                                    {count}
                                </span>
                            </Link>
                        );
                    })}
                </div>

                {/* Lista de conversaciones */}
                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    {items.length === 0 ? (
                        <div className="p-16 text-center">
                            <div className="w-16 h-16 mx-auto rounded-2xl bg-gray-50 flex items-center justify-center mb-3">
                                <svg className="w-7 h-7 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" /></svg>
                            </div>
                            <p className="text-sm text-gray-500 font-medium">
                                {query ? 'Ninguna conversación coincide' : 'Sin conversaciones en esta bandeja'}
                            </p>
                            <p className="text-xs text-gray-400 mt-1">
                                {filter === 'unresponded' && '¡Al día! Ningún lead esperando respuesta.'}
                                {filter === 'unassigned' && 'Todos los leads tienen responsable asignado.'}
                                {filter === 'mine' && 'No tenés conversaciones activas asignadas.'}
                            </p>
                        </div>
                    ) : (
                        <ul className="divide-y divide-gray-50">
                            {items.map((item) => {
                                const contactName = item.contact?.name || item.contact?.phone || 'Sin contacto';
                                const isIn = item.last_message?.direction === 'in';
                                return (
                                    <li key={item.id}>
                                        <Link
                                            href={route('leads.show', item.id)}
                                            className={`flex items-start gap-3 px-4 sm:px-5 py-4 hover:bg-gray-50 transition-colors ${item.waiting_sla ? 'bg-red-50/40 border-l-4 border-red-500' : ''}`}
                                        >
                                            <Avatar name={contactName} />
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center justify-between gap-2 mb-0.5">
                                                    <div className="flex items-center gap-2 min-w-0">
                                                        <p className="font-bold text-gray-900 text-sm truncate">{contactName}</p>
                                                        {item.ai_enabled && (
                                                            <span className="inline-flex items-center gap-0.5 text-[9px] font-bold px-1.5 py-0.5 rounded-full bg-violet-100 text-violet-700" title="IA activa">
                                                                ✨IA
                                                            </span>
                                                        )}
                                                    </div>
                                                    <span className="text-[11px] text-gray-400 tabular-nums shrink-0">
                                                        {relativeTime(item.last_message?.at || item.last_activity_at)}
                                                    </span>
                                                </div>
                                                <div className="flex items-center gap-2 mb-1.5">
                                                    <span className="text-[11px] text-gray-500 font-mono truncate">{item.contact?.phone || '—'}</span>
                                                    <span className="text-gray-300">·</span>
                                                    <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold truncate" style={{ backgroundColor: `${item.stage?.color ?? '#94a3b8'}20`, color: item.stage?.color ?? '#94a3b8' }}>
                                                        {item.stage?.name || '—'}
                                                    </span>
                                                    <span className="text-gray-300">·</span>
                                                    <span className="text-[11px] text-gray-600 truncate font-medium max-w-[35%]">{item.title}</span>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    {isIn ? (
                                                        <svg className="w-3.5 h-3.5 text-teal-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M8.25 6.75L4.5 10.5m0 0l3.75 3.75M4.5 10.5H15a4.5 4.5 0 110 9h-1.5" /></svg>
                                                    ) : item.last_message ? (
                                                        <svg className="w-3.5 h-3.5 text-[#045474] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M15.75 6.75L19.5 10.5m0 0l-3.75 3.75M19.5 10.5H9a4.5 4.5 0 100 9h1.5" /></svg>
                                                    ) : null}
                                                    <p className={`text-sm truncate flex-1 ${isIn ? 'font-semibold text-gray-800' : 'text-gray-500'}`}>
                                                        {item.last_message?.preview || <span className="italic text-gray-400">Sin mensajes aún</span>}
                                                    </p>
                                                </div>
                                            </div>
                                            <div className="flex flex-col items-end gap-1.5 shrink-0">
                                                {item.waiting_sla && (
                                                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-red-100 text-red-700 border border-red-200">
                                                        <span className="w-1.5 h-1.5 rounded-full bg-red-500 animate-pulse" />
                                                        {waitLabel(item.waiting_minutes)}
                                                    </span>
                                                )}
                                                {!item.waiting_sla && item.waiting_minutes > 0 && (
                                                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-50 text-amber-700 border border-amber-200">
                                                        ⏱ {waitLabel(item.waiting_minutes)}
                                                    </span>
                                                )}
                                                {item.responsible ? (
                                                    <span className="text-[10px] text-gray-500 max-w-[100px] truncate" title={item.responsible.name}>
                                                        👤 {item.responsible.name.split(' ')[0]}
                                                    </span>
                                                ) : (
                                                    <span className="text-[10px] font-semibold text-sky-600 bg-sky-50 border border-sky-200 rounded px-1.5 py-0.5">
                                                        Sin asignar
                                                    </span>
                                                )}
                                                {item.pending_tasks > 0 && (
                                                    <span className="text-[10px] text-purple-600" title="Tareas pendientes">
                                                        📋 {item.pending_tasks}
                                                    </span>
                                                )}
                                            </div>
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>

                {items.length > 0 && (
                    <p className="text-xs text-gray-400 mt-3 text-center">
                        {items.length} conversación{items.length !== 1 ? 'es' : ''} · se actualiza automáticamente cada 15 s
                    </p>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
