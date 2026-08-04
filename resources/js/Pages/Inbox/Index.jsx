import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ServiceWindowBadge, { ServiceWindowCard } from '@/Components/ServiceWindowBadge';
import ImageModal from '@/Components/ImageModal';
import { Avatar, ChatBubble, DateSeparator, SystemEvent, VoiceRecorder, buildChatItems } from '@/Components/Chat';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

function csrf() { return document.querySelector('meta[name="csrf-token"]')?.content ?? ''; }

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

const money = (v) => 'Bs. ' + new Intl.NumberFormat('es', { maximumFractionDigits: 0 }).format(v || 0);

/** Fila de la lista de conversaciones. */
function ConversationRow({ item, active, onSelect }) {
    const contactName = item.contact?.name || item.contact?.phone || 'Sin contacto';
    const isIn = item.last_message?.direction === 'in';

    return (
        <button
            type="button"
            onClick={() => onSelect(item.id)}
            className={`w-full text-left flex items-start gap-2.5 px-3 py-3 border-l-4 transition-colors ${
                active
                    ? 'bg-emerald-50/70 border-emerald-500'
                    : item.waiting_sla
                    ? 'bg-red-50/40 border-red-400 hover:bg-red-50/70'
                    : 'border-transparent hover:bg-gray-50'
            }`}
        >
            <Avatar name={contactName} size="sm" />
            <div className="min-w-0 flex-1">
                <div className="flex items-center justify-between gap-2">
                    <p className="font-bold text-gray-900 text-[13px] truncate">{contactName}</p>
                    <span className="text-[10px] text-gray-400 tabular-nums shrink-0">
                        {relativeTime(item.last_message?.at || item.last_activity_at)}
                    </span>
                </div>
                <p className={`text-xs truncate mt-0.5 ${isIn ? 'font-semibold text-gray-800' : 'text-gray-500'}`}>
                    {isIn ? '' : '↩ '}
                    {item.last_message?.preview || <span className="italic text-gray-400">Sin mensajes aún</span>}
                </p>
                <div className="flex items-center gap-1.5 mt-1 flex-wrap">
                    <ServiceWindowBadge window={item.service_window} />
                    {item.waiting_sla && (
                        <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[9px] font-bold bg-red-100 text-red-700">
                            <span className="w-1 h-1 rounded-full bg-red-500 animate-pulse" />
                            {waitLabel(item.waiting_minutes)}
                        </span>
                    )}
                    {item.ai_enabled && (
                        <span className="text-[9px] font-bold px-1.5 py-0.5 rounded-full bg-violet-100 text-violet-700">✨IA</span>
                    )}
                    {item.responsible && (
                        <span className="text-[9px] text-gray-500 truncate">👤 {item.responsible.name.split(' ')[0]}</span>
                    )}
                </div>
            </div>
        </button>
    );
}

/** Panel derecho: quién es y en qué está. */
function LeadPanel({ conv, isAdmin }) {
    const lead = conv.lead;
    const phone = lead.contact?.phone_normalized || lead.contact?.phone;

    return (
        <div className="h-full overflow-y-auto p-4 space-y-4">
            <div className="text-center">
                <div className="flex justify-center mb-2"><Avatar name={lead.contact?.name || phone} size="lg" /></div>
                <p className="font-bold text-gray-900">{lead.contact?.name || 'Sin nombre'}</p>
                <p className="text-xs text-gray-500 font-mono">{lead.contact?.phone || '—'}</p>
                {lead.contact?.email && <p className="text-[11px] text-gray-400 truncate">{lead.contact.email}</p>}
            </div>

            <div className="flex flex-wrap justify-center gap-1.5">
                {lead.tags.map((t) => (
                    <span key={t.id} className="text-[10px] font-bold px-2 py-0.5 rounded-full text-white" style={{ backgroundColor: t.color }}>{t.name}</span>
                ))}
            </div>

            <ServiceWindowCard window={conv.service_window} />

            <dl className="bg-white rounded-xl border border-gray-100 p-3 space-y-2 text-xs">
                <div className="flex justify-between gap-2">
                    <dt className="text-gray-500">Lead</dt>
                    <dd className="font-semibold text-gray-900 text-right truncate">{lead.title}</dd>
                </div>
                <div className="flex justify-between gap-2">
                    <dt className="text-gray-500">Etapa</dt>
                    <dd className="font-semibold text-right" style={{ color: lead.stage?.color }}>{lead.stage?.name ?? '—'}</dd>
                </div>
                <div className="flex justify-between gap-2">
                    <dt className="text-gray-500">Valor</dt>
                    <dd className="font-bold text-gray-900 tabular-nums">{money(lead.value)}</dd>
                </div>
                <div className="flex justify-between gap-2">
                    <dt className="text-gray-500">Responsable</dt>
                    <dd className="font-semibold text-gray-900 text-right truncate">{lead.responsible?.name ?? 'Sin asignar'}</dd>
                </div>
            </dl>

            <div className="space-y-2">
                <Link
                    href={route('leads.show', lead.id)}
                    className="block w-full text-center px-3 py-2.5 rounded-xl text-sm font-semibold text-white bg-gradient-to-r from-[#045474] to-[#1c486c] shadow-lg shadow-[#045474]/20 hover:opacity-90"
                >
                    Abrir ficha completa
                </Link>
                {phone && (
                    <a
                        href={`https://wa.me/${phone.replace(/[^\d]/g, '')}`}
                        target="_blank"
                        rel="noreferrer"
                        className="block w-full text-center px-3 py-2 rounded-xl text-xs font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 hover:bg-emerald-100"
                    >
                        📞 Abrir en WhatsApp
                    </a>
                )}
                {!isAdmin && (
                    <p className="text-[10px] text-gray-400 text-center leading-relaxed">
                        Ves y contestás las conversaciones que tenés asignadas.
                    </p>
                )}
            </div>
        </div>
    );
}

export default function InboxIndex({ items, counts, filter, q, isAdmin, slaMinutes, conversation }) {
    const { auth } = usePage().props;
    const [query, setQuery] = useState(q || '');
    const [text, setText] = useState('');
    const [sending, setSending] = useState(false);
    const [error, setError] = useState(null);
    const [image, setImage] = useState(null);
    const [listOpen, setListOpen] = useState(true);
    const [quickReplies, setQuickReplies] = useState(null);
    const [showQuick, setShowQuick] = useState(false);
    const fileRef = useRef(null);
    const threadRef = useRef(null);

    const selectedId = conversation?.lead?.id ?? null;

    // debounce búsqueda
    useEffect(() => {
        if (query === (q || '')) return;
        const t = setTimeout(() => {
            router.get(route('inbox'), { filter, q: query }, { preserveState: true, preserveScroll: true, replace: true });
        }, 300);

        return () => clearTimeout(t);
    }, [query]);

    // Refresco en vivo. Sin esto habría que recargar para ver una respuesta,
    // que es justo lo que este rediseño vino a evitar.
    useEffect(() => {
        const id = setInterval(() => {
            if (document.hidden) return;
            router.reload({ only: ['items', 'counts', 'conversation'], preserveScroll: true, preserveState: true });
        }, 5000);

        return () => clearInterval(id);
    }, []);

    // Bajar al último mensaje al abrir una conversación o al llegar uno nuevo.
    const lastEventId = conversation?.events?.at(-1)?.id;
    useEffect(() => {
        threadRef.current?.scrollTo({ top: threadRef.current.scrollHeight, behavior: 'smooth' });
    }, [selectedId, lastEventId]);

    const seleccionar = (leadId) => {
        setText('');
        setError(null);
        router.get(route('inbox'), { filter, q: query, lead: leadId }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['conversation'],
        });
    };

    const enviar = (e) => {
        e?.preventDefault();
        if (!text.trim() || !selectedId || sending) return;
        setSending(true);
        setError(null);
        // El endpoint valida el campo `text` (mismo que usa la ficha del lead).
        router.post(route('leads.whatsapp', selectedId), { text: text.trim() }, {
            preserveScroll: true,
            preserveState: true,
            only: ['conversation', 'items', 'counts', 'flash', 'errors'],
            onSuccess: () => setText(''),
            onError: (errs) => setError(Object.values(errs)[0] ?? 'No se pudo enviar.'),
            onFinish: () => setSending(false),
        });
    };

    // Adjuntos y audios van por el mismo endpoint que la ficha del lead.
    const enviarArchivo = async (file) => {
        if (!file || !selectedId) return;
        setSending(true);
        setError(null);
        const fd = new FormData();
        fd.append('file', file);
        if (text.trim()) fd.append('caption', text.trim());
        try {
            const res = await fetch(route('leads.whatsapp-media', selectedId), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
                credentials: 'same-origin',
                body: fd,
            });
            if (!res.ok) throw new Error('El envío falló');
            setText('');
            router.reload({ only: ['conversation', 'items'], preserveScroll: true, preserveState: true });
        } catch (err) {
            setError(err.message);
            throw err;
        } finally {
            setSending(false);
        }
    };

    const cargarQuickReplies = () => {
        setShowQuick((v) => !v);
        if (quickReplies !== null) return;
        fetch(route('leads.quick-replies'), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((d) => setQuickReplies(d.data ?? d ?? []))
            .catch(() => setQuickReplies([]));
    };

    const chatItems = useMemo(() => buildChatItems(conversation?.events ?? []), [conversation?.events]);

    const filters = [
        { key: 'mine', label: 'Mías', icon: '👤', tone: 'emerald' },
        ...(isAdmin ? [{ key: 'unassigned', label: 'Sin asignar', icon: '🆕', tone: 'sky' }] : []),
        { key: 'unresponded', label: `Sin responder`, icon: '🚨', tone: 'red' },
        ...(isAdmin ? [{ key: 'all', label: 'Todo el equipo', icon: '🌐', tone: 'slate' }] : []),
    ];

    const contactName = conversation?.lead?.contact?.name || conversation?.lead?.contact?.phone || 'Sin contacto';
    const ventanaCerrada = conversation && !conversation.service_window?.is_open;

    return (
        <AuthenticatedLayout>
            <Head title="Inbox" />

            <div className="px-2 sm:px-4 lg:px-6 py-4">
                <div className="flex rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden" style={{ height: 'calc(100vh - 7rem)' }}>

                    {/* ── Columna 1: conversaciones ───────────────────────── */}
                    {listOpen ? (
                        <aside className="w-full sm:w-80 shrink-0 border-r border-gray-100 flex flex-col bg-gray-50/50">
                            <div className="p-3 border-b border-gray-100 space-y-2.5 bg-white">
                                <div className="flex items-center justify-between">
                                    <h1 className="text-base font-bold text-gray-900">Conversaciones</h1>
                                    <button onClick={() => setListOpen(false)} className="p-1 rounded-lg text-gray-400 hover:bg-gray-100" title="Ocultar lista">
                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
                                    </button>
                                </div>
                                <input
                                    value={query}
                                    onChange={(e) => setQuery(e.target.value)}
                                    placeholder="Buscar nombre o teléfono…"
                                    className="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:bg-white"
                                />
                                <div className="grid grid-cols-2 gap-1.5">
                                    {filters.map((f) => {
                                        const active = filter === f.key;
                                        const tone = {
                                            emerald: 'bg-emerald-600 text-white',
                                            sky: 'bg-sky-600 text-white',
                                            red: 'bg-red-600 text-white',
                                            slate: 'bg-slate-800 text-white',
                                        }[f.tone];

                                        return (
                                            <Link
                                                key={f.key}
                                                href={route('inbox', { filter: f.key, q: query })}
                                                preserveScroll
                                                className={`flex flex-col items-center justify-center px-2 py-1.5 rounded-lg text-[11px] font-bold transition-all ${active ? tone : 'bg-gray-100 text-gray-600 hover:bg-gray-200'}`}
                                            >
                                                <span className="truncate">{f.icon} {f.label}</span>
                                                <span className={`text-[10px] tabular-nums ${active ? 'text-white/80' : 'text-gray-500'}`}>{counts[f.key] ?? 0}</span>
                                            </Link>
                                        );
                                    })}
                                </div>
                            </div>

                            <div className="flex-1 overflow-y-auto divide-y divide-gray-100">
                                {items.length === 0 ? (
                                    <div className="p-10 text-center">
                                        <p className="text-sm text-gray-500 font-medium">
                                            {query ? 'Ninguna conversación coincide' : 'Bandeja vacía'}
                                        </p>
                                        <p className="text-xs text-gray-400 mt-1">
                                            {filter === 'unresponded' && '¡Al día! Nadie esperando respuesta.'}
                                            {filter === 'unassigned' && 'Todos los leads tienen responsable.'}
                                            {filter === 'mine' && 'No tenés conversaciones asignadas.'}
                                        </p>
                                    </div>
                                ) : (
                                    items.map((item) => (
                                        <ConversationRow key={item.id} item={item} active={item.id === selectedId} onSelect={seleccionar} />
                                    ))
                                )}
                            </div>
                        </aside>
                    ) : (
                        <button
                            onClick={() => setListOpen(true)}
                            className="w-10 shrink-0 border-r border-gray-100 bg-gray-50 hover:bg-gray-100 flex flex-col items-center pt-3 gap-2"
                            title="Mostrar conversaciones"
                        >
                            <svg className="w-4 h-4 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                            {counts.mine > 0 && <span className="text-[10px] font-bold text-emerald-700 bg-emerald-100 rounded-full px-1">{counts.mine}</span>}
                        </button>
                    )}

                    {/* ── Columna 2: el hilo ──────────────────────────────── */}
                    <section className={`flex-1 flex flex-col min-w-0 ${listOpen ? 'hidden sm:flex' : 'flex'}`}>
                        {!conversation ? (
                            <div className="flex-1 flex flex-col items-center justify-center text-center p-10 bg-gray-50/60">
                                <div className="w-16 h-16 rounded-2xl bg-white shadow-sm flex items-center justify-center mb-3">
                                    <svg className="w-7 h-7 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" /></svg>
                                </div>
                                <p className="text-sm font-semibold text-gray-600">Elegí una conversación</p>
                                <p className="text-xs text-gray-400 mt-1">Se abre acá mismo: leés y contestás sin salir de la bandeja.</p>
                            </div>
                        ) : (
                            <>
                                {/* Encabezado del chat */}
                                <header className="flex items-center gap-3 px-4 py-2.5 border-b border-gray-100 bg-white">
                                    <button onClick={() => setListOpen((v) => !v)} className="sm:hidden p-1.5 rounded-lg text-gray-500 hover:bg-gray-100">☰</button>
                                    <Avatar name={contactName} size="sm" />
                                    <div className="min-w-0 flex-1">
                                        <p className="font-bold text-gray-900 text-sm truncate">{contactName}</p>
                                        <p className="text-[11px] text-gray-500 font-mono truncate">{conversation.lead.contact?.phone}</p>
                                    </div>
                                    <ServiceWindowBadge window={conversation.service_window} />
                                    {conversation.lead.stage && (
                                        <span className="hidden md:inline-flex px-2 py-0.5 rounded text-[10px] font-bold" style={{ backgroundColor: `${conversation.lead.stage.color}20`, color: conversation.lead.stage.color }}>
                                            {conversation.lead.stage.name}
                                        </span>
                                    )}
                                    {conversation.lead.ai_enabled && (
                                        <span className="hidden md:inline-flex text-[10px] font-bold px-2 py-0.5 rounded-full bg-violet-100 text-violet-700">✨ IA</span>
                                    )}
                                </header>

                                {/* Hilo */}
                                <div ref={threadRef} className="flex-1 overflow-y-auto px-4 py-3 space-y-2 bg-gray-50/60">
                                    {chatItems.length === 0 && (
                                        <p className="text-center text-xs text-gray-400 py-10">Sin mensajes todavía.</p>
                                    )}
                                    {chatItems.map((it) =>
                                        it.kind === 'day' ? <DateSeparator key={it.key} label={it.label} />
                                            : it.kind === 'msg' ? <ChatBubble key={it.key} event={it.event} contactName={contactName} onOpenImage={setImage} />
                                            : <SystemEvent key={it.key} event={it.event} />
                                    )}
                                    {conversation.lead.ai_pending && (
                                        <div className="flex justify-end">
                                            <div className="rounded-2xl px-3.5 py-2.5 bg-gradient-to-br from-violet-500 to-purple-600 text-white text-xs font-semibold shadow-md inline-flex items-center gap-2">
                                                <span className="flex gap-0.5">
                                                    <span className="w-1.5 h-1.5 rounded-full bg-white/90 animate-bounce" style={{ animationDelay: '0ms' }} />
                                                    <span className="w-1.5 h-1.5 rounded-full bg-white/90 animate-bounce" style={{ animationDelay: '150ms' }} />
                                                    <span className="w-1.5 h-1.5 rounded-full bg-white/90 animate-bounce" style={{ animationDelay: '300ms' }} />
                                                </span>
                                                Pensando respuesta…
                                            </div>
                                        </div>
                                    )}
                                </div>

                                {/* Composer */}
                                <div className="border-t border-gray-100 bg-white p-3 space-y-2">
                                    {ventanaCerrada && (
                                        <p className="text-[11px] text-red-700 bg-red-50 border border-red-200 rounded-lg px-3 py-2">
                                            ⚠ La ventana de servicio está cerrada: Meta no entrega texto libre. Hace falta una plantilla aprobada.
                                        </p>
                                    )}
                                    {error && (
                                        <p className="text-[11px] font-semibold text-red-700 bg-red-50 border border-red-200 rounded-lg px-3 py-2">{error}</p>
                                    )}

                                    {showQuick && (
                                        <div className="max-h-40 overflow-y-auto rounded-xl border border-gray-200 divide-y divide-gray-50">
                                            {quickReplies === null && <p className="px-3 py-2 text-xs text-gray-400">Cargando…</p>}
                                            {quickReplies?.length === 0 && <p className="px-3 py-2 text-xs text-gray-400">Sin plantillas cargadas en el CRM de WhatsApp.</p>}
                                            {quickReplies?.map((qr) => (
                                                <button
                                                    key={qr.id ?? qr.shortcut}
                                                    type="button"
                                                    onClick={() => {
                                                        const nombre = conversation.lead.contact?.name ?? '';
                                                        setText((qr.content ?? '').replace(/\{name\}/g, nombre).replace(/\{phone\}/g, conversation.lead.contact?.phone ?? ''));
                                                        setShowQuick(false);
                                                    }}
                                                    className="w-full text-left px-3 py-2 hover:bg-gray-50"
                                                >
                                                    <span className="text-[10px] font-bold text-emerald-600">/{qr.shortcut}</span>
                                                    <span className="block text-xs text-gray-700 truncate">{qr.content}</span>
                                                </button>
                                            ))}
                                        </div>
                                    )}

                                    <form onSubmit={enviar} className="flex items-end gap-2">
                                        <input
                                            ref={fileRef}
                                            type="file"
                                            className="hidden"
                                            accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt"
                                            onChange={(e) => { const f = e.target.files?.[0]; if (f) enviarArchivo(f).catch(() => {}); e.target.value = ''; }}
                                        />
                                        <button type="button" onClick={() => fileRef.current?.click()} disabled={sending} title="Adjuntar" className="rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-gray-600 hover:bg-gray-50 disabled:opacity-50 shadow-sm">
                                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13" /></svg>
                                        </button>
                                        <button type="button" onClick={cargarQuickReplies} disabled={sending} title="Plantillas rápidas" className="rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-gray-600 hover:bg-gray-50 disabled:opacity-50 shadow-sm">
                                            📋
                                        </button>
                                        <VoiceRecorder onSend={enviarArchivo} disabled={sending} />
                                        <textarea
                                            value={text}
                                            onChange={(e) => setText(e.target.value)}
                                            onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); enviar(); } }}
                                            rows={1}
                                            placeholder="Escribí un mensaje… (Enter envía, Shift+Enter salta línea)"
                                            className="flex-1 resize-none px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:bg-white max-h-32"
                                        />
                                        <button
                                            type="submit"
                                            disabled={sending || !text.trim()}
                                            className="rounded-xl px-4 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-[#045474] to-[#1c486c] shadow-lg shadow-[#045474]/20 disabled:opacity-40"
                                        >
                                            {sending ? '…' : 'Enviar'}
                                        </button>
                                    </form>
                                </div>
                            </>
                        )}
                    </section>

                    {/* ── Columna 3: el lead ──────────────────────────────── */}
                    {conversation && (
                        <aside className="hidden lg:block w-72 shrink-0 border-l border-gray-100 bg-gray-50/50">
                            <LeadPanel conv={conversation} isAdmin={isAdmin} />
                        </aside>
                    )}
                </div>
            </div>

            <ImageModal src={image?.src} alt={image?.alt} onClose={() => setImage(null)} />
        </AuthenticatedLayout>
    );
}
