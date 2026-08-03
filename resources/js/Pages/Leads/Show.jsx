import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { completeTask } from '@/Components/CompleteTaskModal';
import ServiceWindowBadge, { ServiceWindowCard } from '@/Components/ServiceWindowBadge';
import ImageModal from '@/Components/ImageModal';
import Modal from '@/Components/Modal';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import Recorder from 'opus-recorder';
import encoderPath from 'opus-recorder/dist/encoderWorker.min.js?url';

function csrf() { return document.querySelector('meta[name="csrf-token"]')?.content ?? ''; }

/**
 * Aviso en el chat: la IA agotó su tope de respuestas en el wacrm y está en
 * pausa hasta cierta hora, cuando retoma sola.
 *
 * Espejo del mismo aviso del Inbox del wacrm. Sin esto, acá la IA
 * simplemente dejaba de contestar y no había forma de saber por qué. La
 * reactivación manual se hace desde el toggle IA/Humano del header.
 */
function AiPausedNotice({ pausedUntil }) {
    if (!pausedUntil) return null;

    const hasta = new Date(pausedUntil);
    if (hasta <= new Date()) return null; // ya venció: la IA retoma sola

    const minutos = Math.max(1, Math.round((hasta - Date.now()) / 60000));
    const restante = minutos >= 60 ? `${Math.floor(minutos / 60)}h ${minutos % 60}m` : `${minutos}m`;

    return (
        <div className="flex justify-center px-4">
            <div className="max-w-md w-full rounded-2xl border border-amber-200 bg-amber-50/80 px-4 py-3 text-center">
                <p className="text-xs font-bold text-amber-900">⏸ La IA llegó a su límite de respuestas</p>
                <p className="text-[11px] text-amber-800 mt-1 leading-relaxed">
                    Sigue <strong>activa</strong>, pero en pausa para no seguir respondiendo sola.
                    Vuelve a contestar a las <strong>{hasta.toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' })}</strong>
                    {' '}(en {restante}). Mientras tanto, contestá vos.
                </p>
            </div>
        </div>
    );
}

/** Web Speech API: lee texto en voz alta (mismo patrón que wacrm). */
const ttsState = { current: null };
function speakText(text, onEnd) {
    if (!('speechSynthesis' in window)) { onEnd?.(); return; }
    if (ttsState.current) {
        window.speechSynthesis.cancel();
        const prev = ttsState.current;
        ttsState.current = null;
        prev.onEnd?.();
        if (prev.text === text) return;
    }
    const u = new SpeechSynthesisUtterance(text);
    u.lang = 'es-BO'; u.rate = 1.05;
    u.onend = () => { ttsState.current = null; onEnd?.(); };
    u.onerror = () => { ttsState.current = null; onEnd?.(); };
    window.speechSynthesis.speak(u);
    ttsState.current = { text, onEnd };
}

function OriginCard({ lead }) {
    const has = lead.utm_source || lead.utm_medium || lead.utm_campaign || lead.gclid || lead.fbclid || lead.ttclid || lead.msclkid || lead.landing_url || lead.referrer_url;
    if (!has) return null;
    const clickId = lead.gclid ? ['Google (gclid)', lead.gclid] : lead.fbclid ? ['Meta (fbclid)', lead.fbclid] : lead.ttclid ? ['TikTok (ttclid)', lead.ttclid] : lead.msclkid ? ['Bing (msclkid)', lead.msclkid] : null;
    const trunc = (s, n = 60) => (s && s.length > n ? s.slice(0, n) + '…' : s);
    return (
        <div className="bg-gradient-to-br from-indigo-50 to-blue-50 rounded-2xl border border-indigo-100 p-5">
            <h3 className="text-sm font-bold text-gray-900 flex items-center gap-2">📊 Origen del lead</h3>
            <p className="text-[11px] text-gray-500 mt-0.5 mb-3">Atribución first-touch — no cambia si vuelve por otro canal.</p>
            <dl className="space-y-2 text-xs">
                {lead.utm_source && <div className="flex justify-between gap-3"><dt className="text-gray-500">Canal</dt><dd className="font-semibold text-gray-900">{lead.utm_source}</dd></div>}
                {lead.utm_medium && <div className="flex justify-between gap-3"><dt className="text-gray-500">Medio</dt><dd className="font-semibold text-gray-900">{lead.utm_medium}</dd></div>}
                {lead.utm_campaign && <div className="flex justify-between gap-3"><dt className="text-gray-500">Campaña</dt><dd className="font-mono text-gray-900 text-right" title={lead.utm_campaign}>{trunc(lead.utm_campaign, 30)}</dd></div>}
                {lead.utm_content && <div className="flex justify-between gap-3"><dt className="text-gray-500">Contenido</dt><dd className="font-mono text-gray-900 text-right" title={lead.utm_content}>{trunc(lead.utm_content, 30)}</dd></div>}
                {lead.utm_term && <div className="flex justify-between gap-3"><dt className="text-gray-500">Término</dt><dd className="font-mono text-gray-900 text-right">{trunc(lead.utm_term, 30)}</dd></div>}
                {clickId && <div className="flex justify-between gap-3"><dt className="text-gray-500">{clickId[0]}</dt><dd className="font-mono text-[10px] text-gray-700 text-right" title={clickId[1]}>{trunc(clickId[1], 20)}</dd></div>}
                {lead.landing_url && (
                    <div className="pt-2 border-t border-indigo-100">
                        <dt className="text-gray-500 mb-0.5">Landing</dt>
                        <dd className="text-[10px] break-all"><a href={lead.landing_url} target="_blank" rel="noreferrer" className="text-indigo-700 hover:underline">{trunc(lead.landing_url, 80)}</a></dd>
                    </div>
                )}
                {lead.referrer_url && (
                    <div>
                        <dt className="text-gray-500 mb-0.5">Referrer</dt>
                        <dd className="text-[10px] break-all text-gray-700">{trunc(lead.referrer_url, 80)}</dd>
                    </div>
                )}
                {lead.first_touch_at && <div className="flex justify-between gap-3 pt-1 text-[10px] text-gray-400"><dt>Primer toque</dt><dd>{new Date(lead.first_touch_at).toLocaleString('es')}</dd></div>}
            </dl>
        </div>
    );
}

function money(value, currency) {
    return 'Bs. ' + new Intl.NumberFormat('es', { maximumFractionDigits: 0 }).format(value || 0);
}

const EVENT_META = {
    created: { label: 'Lead creado', icon: '✨', color: 'bg-emerald-100 text-emerald-700' },
    stage_changed: { label: 'Cambio de etapa', icon: '➡️', color: 'bg-blue-100 text-blue-700' },
    won: { label: 'Ganado', icon: '🏆', color: 'bg-emerald-100 text-emerald-700' },
    lost: { label: 'Perdido', icon: '✕', color: 'bg-red-100 text-red-700' },
    reopened: { label: 'Reabierto', icon: '🔄', color: 'bg-sky-100 text-sky-700' },
    note_added: { label: 'Nota', icon: '📝', color: 'bg-amber-100 text-amber-700' },
    task_created: { label: 'Tarea creada', icon: '📋', color: 'bg-purple-100 text-purple-700' },
    task_completed: { label: 'Tarea completada', icon: '✅', color: 'bg-emerald-100 text-emerald-700' },
    message_in: { label: 'WhatsApp recibido', icon: '💬', color: 'bg-teal-100 text-teal-700' },
    message_out: { label: 'WhatsApp enviado', icon: '📤', color: 'bg-[#e6f0f4] text-[#045474]' },
    value_changed: { label: 'Valor actualizado', icon: '💰', color: 'bg-amber-100 text-amber-700' },
};

const inputClass = 'w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 focus:bg-white transition-all';

const AVATAR_COLORS = [
    'from-emerald-500 to-teal-600',
    'from-blue-500 to-indigo-600',
    'from-purple-500 to-pink-600',
    'from-amber-500 to-orange-600',
    'from-rose-500 to-red-600',
    'from-cyan-500 to-sky-600',
    'from-lime-500 to-green-600',
    'from-fuchsia-500 to-purple-600',
];

function avatarFor(name) {
    const label = (name || '?').trim();
    const initials = label.split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '?';
    let hash = 0;
    for (let i = 0; i < label.length; i++) hash = (hash * 31 + label.charCodeAt(i)) | 0;
    const gradient = AVATAR_COLORS[Math.abs(hash) % AVATAR_COLORS.length];
    return { initials, gradient };
}

function Avatar({ name, size = 'md' }) {
    const { initials, gradient } = avatarFor(name);
    const sizes = { sm: 'w-8 h-8 text-xs', md: 'w-10 h-10 text-sm', lg: 'w-12 h-12 text-base' };
    return (
        <div className={`${sizes[size]} rounded-full bg-gradient-to-br ${gradient} flex items-center justify-center font-bold text-white shadow-sm shrink-0`}>
            {initials}
        </div>
    );
}

function dayLabel(iso) {
    const d = new Date(iso);
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);
    const same = (a, b) => a.toDateString() === b.toDateString();
    if (same(d, today)) return 'Hoy';
    if (same(d, yesterday)) return 'Ayer';
    return d.toLocaleDateString('es', { weekday: 'long', day: 'numeric', month: 'long' });
}

function DateSeparator({ label }) {
    return (
        <div className="flex items-center gap-3 py-2">
            <div className="flex-1 h-px bg-gray-200" />
            <span className="text-[11px] font-bold uppercase tracking-wider text-gray-400 bg-white px-3 py-1 rounded-full shadow-sm">{label}</span>
            <div className="flex-1 h-px bg-gray-200" />
        </div>
    );
}

function outboundAuthor(p) {
    if (p.sender === 'bot') return { text: '✨ IA', color: 'text-violet-600' };
    const name = p.sender_name || 'Agente';
    const isAdmin = p.sender_role === 'owner' || p.sender_role === 'admin';
    return { text: name + (isAdmin ? ' · Admin' : ''), color: 'text-[#045474]' };
}

const TYPE_META = {
    audio: { icon: '🎙', label: 'Audio' },
    image: { icon: '🖼️', label: 'Imagen' },
    video: { icon: '🎥', label: 'Video' },
    document: { icon: '📄', label: 'Documento' },
    sticker: { icon: '🟪', label: 'Sticker' },
};

function ChatBubble({ event, contactName, onOpenImage }) {
    const isCustomer = event.event_type === 'message_in';
    const p = event.payload ?? {};
    const time = new Date(event.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    const author = !isCustomer ? outboundAuthor(p) : null;
    const [isSpeaking, setIsSpeaking] = useState(false);

    const mediaType = p.type && p.type !== 'text' ? TYPE_META[p.type] : null;
    // El "text" se usa como fallback / caption / transcript
    const displayText = p.text || null;
    const readable = displayText || p.transcript;

    const toggleSpeak = (e) => {
        e.stopPropagation();
        if (!readable) return;
        if (isSpeaking) { window.speechSynthesis.cancel(); setIsSpeaking(false); return; }
        setIsSpeaking(true);
        speakText(readable, () => setIsSpeaking(false));
    };

    return (
        <div className={`group flex items-end gap-2 ${isCustomer ? 'justify-start' : 'justify-end'}`}>
            {isCustomer && <Avatar name={contactName} size="sm" />}
            <div className={`flex flex-col max-w-[75%] ${isCustomer ? 'items-start' : 'items-end'}`}>
                {author && (
                    <span className={`text-[10px] font-bold mb-0.5 mr-2 ${author.color}`}>{author.text}</span>
                )}
                <div
                    className={`rounded-2xl px-3.5 py-2.5 text-sm shadow-sm ${
                        isCustomer
                            ? 'bg-white text-gray-900 rounded-bl-md border border-gray-100'
                            : 'bg-gradient-to-br from-[#045474] to-[#1c486c] text-white rounded-br-md shadow-md shadow-[#045474]/20'
                    }`}
                >
                    {mediaType && (
                        <p className={`text-xs font-semibold mb-1 flex items-center gap-1.5 ${isCustomer ? 'text-gray-500' : 'text-white/80'}`}>
                            <span>{mediaType.icon}</span>
                            <span>{mediaType.label}</span>
                        </p>
                    )}
                    {/* Reproductor real de media: audio/video usan el proxy /leads/media/{id} */}
                    {p.type === 'audio' && p.media_id && (
                        <audio controls src={route('leads.media', p.media_id)} className="w-64 max-w-full my-1" />
                    )}
                    {p.type === 'video' && p.media_id && (
                        <video controls src={route('leads.media', p.media_id)} className="max-h-56 rounded-lg my-1" />
                    )}
                    {p.type === 'image' && p.media_id && (
                        <button
                            type="button"
                            onClick={(e) => { e.stopPropagation(); onOpenImage?.({ src: route('leads.media', p.media_id), alt: 'Imagen' }); }}
                            className="block my-1 cursor-zoom-in"
                        >
                            <img src={route('leads.media', p.media_id)} alt="" className="max-h-56 rounded-lg" />
                        </button>
                    )}
                    {p.type === 'sticker' && p.media_id && (
                        <img src={route('leads.media', p.media_id)} alt="Sticker" className="h-32 w-32 object-contain my-1" />
                    )}
                    {p.type === 'document' && p.media_id && (
                        <a
                            href={route('leads.media', p.media_id)}
                            target="_blank"
                            rel="noreferrer"
                            className={`inline-flex items-center gap-1.5 my-1 underline text-xs ${isCustomer ? 'text-[#045474]' : 'text-white'}`}
                        >
                            📄 Descargar documento
                        </a>
                    )}
                    {displayText && (
                        <p className="whitespace-pre-wrap break-words leading-relaxed">{displayText}</p>
                    )}
                    {!displayText && !mediaType && (
                        <p className="italic opacity-60">[sin contenido]</p>
                    )}
                    {!displayText && mediaType?.label === 'Audio' && (
                        <p className={`italic text-xs ${isCustomer ? 'text-gray-400' : 'text-white/70'}`}>Transcribiendo…</p>
                    )}
                    <div className={`mt-1 flex items-center gap-1.5 text-[10px] ${isCustomer ? 'text-gray-400' : 'text-white/70'}`}>
                        <span>{time}</span>
                        {!isCustomer && <span>✓✓</span>}
                        {readable && (
                            <button
                                type="button"
                                onClick={toggleSpeak}
                                title={isSpeaking ? 'Detener' : 'Leer en voz alta'}
                                className={`ml-1 opacity-0 group-hover:opacity-100 transition-opacity ${isSpeaking ? 'text-emerald-300 animate-pulse' : 'hover:text-inherit'}`}
                            >
                                {isSpeaking ? '⏸' : '🔊'}
                            </button>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

/** Grabador de voz — mismo patrón que wacrm (opus-recorder → ogg/opus). */
function VoiceRecorder({ onSend, disabled }) {
    const [state, setState] = useState('idle');
    const [seconds, setSeconds] = useState(0);
    const [blob, setBlob] = useState(null);
    const recRef = useRef(null);
    const timerRef = useRef(null);

    const start = async () => {
        try {
            const rec = new Recorder({
                encoderPath, encoderApplication: 2049, encoderSampleRate: 48000,
                originalSampleRateOverride: 48000, numberOfChannels: 1, streamPages: false,
            });
            rec.ondataavailable = (data) => {
                setBlob(new Blob([data], { type: 'audio/ogg' }));
                setState('preview');
            };
            await rec.start();
            recRef.current = rec; setState('recording'); setSeconds(0);
            timerRef.current = setInterval(() => setSeconds((s) => s + 1), 1000);
        } catch (e) { setState('idle'); }
    };
    const stop = async () => { clearInterval(timerRef.current); if (recRef.current) { await recRef.current.stop(); recRef.current = null; } };
    const discard = () => { setBlob(null); setSeconds(0); setState('idle'); };
    const send = async () => {
        if (!blob) return;
        setState('sending');
        try { await onSend(new File([blob], `voz-${Date.now()}.ogg`, { type: 'audio/ogg' })); discard(); }
        catch { setState('preview'); }
    };
    useEffect(() => () => { clearInterval(timerRef.current); recRef.current?.stop().catch(() => {}); }, []);
    const mm = String(Math.floor(seconds / 60)).padStart(2, '0');
    const ss = String(seconds % 60).padStart(2, '0');

    if (state === 'idle') return (
        <button type="button" onClick={start} disabled={disabled} title="Grabar audio" className="rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-gray-600 hover:bg-rose-50 hover:border-rose-300 hover:text-rose-600 disabled:opacity-50 shadow-sm">
            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M19 11a7 7 0 01-14 0m7 7v3m-3 0h6M9 5a3 3 0 016 0v6a3 3 0 01-6 0V5z" /></svg>
        </button>
    );
    if (state === 'recording') return (
        <div className="flex items-center gap-2 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2">
            <span className="w-3 h-3 rounded-full bg-rose-500 animate-pulse" />
            <span className="text-xs font-mono font-bold text-rose-700">{mm}:{ss}</span>
            <button type="button" onClick={discard} className="text-xs text-rose-700">Cancelar</button>
            <button type="button" onClick={stop} className="px-3 py-1 text-xs font-semibold bg-rose-600 text-white rounded-lg">Detener</button>
        </div>
    );
    return (
        <div className="flex items-center gap-2 rounded-xl border border-sky-200 bg-sky-50 px-3 py-2">
            {blob && <audio controls src={URL.createObjectURL(blob)} className="h-9" />}
            <button type="button" onClick={discard} disabled={state === 'sending'} className="px-2 py-1 text-xs text-gray-600">Descartar</button>
            <button type="button" onClick={send} disabled={state === 'sending'} className="px-3 py-1 text-xs font-semibold bg-gradient-to-r from-[#045474] to-[#1c486c] text-white rounded-lg disabled:opacity-50">
                {state === 'sending' ? '…' : 'Enviar'}
            </button>
        </div>
    );
}

function SystemEvent({ event }) {
    const meta = EVENT_META[event.event_type] ?? { label: event.event_type, icon: '·', color: 'bg-gray-100 text-gray-600' };
    const p = event.payload ?? {};
    let description = null;
    if (event.event_type === 'stage_changed') description = <>{p.from} → <span className="font-semibold">{p.to}</span></>;
    else if (event.event_type === 'value_changed') description = <>{p.from} → <span className="font-semibold">{p.to}</span></>;
    else if (['note_added', 'task_created', 'task_completed'].includes(event.event_type) && p.text) description = <span className="italic">"{p.text}"{p.result ? ` — ${p.result}` : ''}</span>;

    return (
        <div className="flex items-center justify-center gap-2 py-1">
            <span className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[11px] font-medium ${meta.color}`}>
                <span>{meta.icon}</span>
                <span>{meta.label}</span>
                {description && <span className="text-[10px] opacity-80">· {description}</span>}
            </span>
        </div>
    );
}

function TimelineEvent({ event }) {
    const meta = EVENT_META[event.event_type] ?? { label: event.event_type, icon: '·', color: 'bg-gray-100 text-gray-600' };
    const p = event.payload ?? {};

    return (
        <div className="flex gap-3">
            <div className={`w-8 h-8 shrink-0 rounded-xl flex items-center justify-center text-sm ${meta.color}`}>{meta.icon}</div>
            <div className="flex-1 min-w-0 pb-4 border-b border-gray-50">
                <div className="flex items-center justify-between gap-2">
                    <p className="text-sm font-semibold text-gray-900">{meta.label}</p>
                    <span className="text-[11px] text-gray-400 shrink-0">
                        {new Date(event.created_at).toLocaleString('es', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}
                    </span>
                </div>
                {event.event_type === 'stage_changed' && (
                    <p className="text-xs text-gray-500 mt-0.5">{p.from} → <span className="font-semibold">{p.to}</span></p>
                )}
                {(event.event_type === 'message_in' || event.event_type === 'message_out') && p.text && (
                    <p className={`text-sm mt-1.5 rounded-xl px-3 py-2 ${event.event_type === 'message_in' ? 'bg-gray-50 text-gray-700' : 'bg-emerald-50 text-emerald-900'}`}>
                        {p.text}
                    </p>
                )}
                {(event.event_type === 'note_added' || event.event_type === 'task_created' || event.event_type === 'task_completed') && p.text && (
                    <p className="text-xs text-gray-500 mt-0.5 line-clamp-2">{p.text}{p.result ? ` — ${p.result}` : ''}</p>
                )}
                {event.event_type === 'value_changed' && (
                    <p className="text-xs text-gray-500 mt-0.5">{p.from} → <span className="font-semibold">{p.to}</span></p>
                )}
                {event.actor && <p className="text-[11px] text-gray-300 mt-1">por {event.actor.name}</p>}
            </div>
        </div>
    );
}

export default function Show({ lead, stages, events, tasks, notes, members, contacts, companies, allTags, customFields, customValues, whatsappEnabled, serviceWindow }) {
    const { flash, auth } = usePage().props;
    const isAdmin = auth?.user?.account_role === 'owner' || auth?.user?.account_role === 'admin';
    const [tab, setTab] = useState('chat');
    const [newTag, setNewTag] = useState(null);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const bottomRef = useRef(null);

    const editForm = useForm({
        title: lead.title,
        value: lead.value,
        contact_id: lead.contact_id ?? '',
        company_id: lead.company_id ?? '',
        responsible_user_id: lead.responsible_user_id ?? '',
        custom_values: customValues ?? {},
    });

    const noteForm = useForm({ text: '' });
    const taskForm = useForm({ lead_id: lead.id, task_type: 'call', text: '', due_at: '', assigned_to: '' });
    const waForm = useForm({ text: '' });
    const waInputRef = useRef(null);
    const fileInputRef = useRef(null);
    const [quickReplies, setQuickReplies] = useState([]);
    const [showQuickReplies, setShowQuickReplies] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [lightbox, setLightbox] = useState(null);

    // Cargar plantillas rápidas (delegadas al wacrm)
    useEffect(() => {
        fetch(route('leads.quick-replies'), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((r) => r.json()).then(setQuickReplies).catch(() => {});
    }, []);

    const renderTemplate = (content) => content
        .replaceAll('{name}', lead.contact?.name ?? '')
        .replaceAll('{phone}', lead.contact?.phone ?? '')
        .replaceAll('{email}', lead.contact?.email ?? '');

    const insertQuickReply = (r) => {
        waForm.setData('text', (waForm.data.text ? waForm.data.text + ' ' : '') + renderTemplate(r.content));
        setShowQuickReplies(false);
    };

    const sendWhatsapp = () => {
        if (!whatsappEnabled || !waForm.data.text.trim()) return;
        waForm.post(route('leads.whatsapp', lead.id), {
            preserveScroll: true,
            onSuccess: () => waForm.reset(),
            onFinish: () => requestAnimationFrame(() => waInputRef.current?.focus()),
        });
    };

    const sendFile = async (file) => {
        if (!file || uploading) return;
        setUploading(true);
        try {
            const body = new FormData();
            body.append('file', file);
            const res = await fetch(route('leads.whatsapp-media', lead.id), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
                credentials: 'same-origin',
                body,
            });
            if (!res.ok) throw new Error((await res.json().catch(() => ({})))?.message ?? 'Error');
        } finally {
            setUploading(false);
            if (fileInputRef.current) fileInputRef.current.value = '';
        }
    };

    const saveEdit = (e) => { e.preventDefault(); editForm.patch(route('leads.update', lead.id), { preserveScroll: true }); };
    const moveTo = (stageId) => router.patch(route('leads.move', lead.id), { stage_id: stageId }, { preserveScroll: true });
    const toggleTag = (tagId) => {
        const current = (lead.tags ?? []).map((t) => t.id);
        const next = current.includes(tagId) ? current.filter((id) => id !== tagId) : [...current, tagId];
        router.patch(route('leads.tags', lead.id), { tag_ids: next }, { preserveScroll: true });
    };

    const wonStage = stages.find((s) => s.stage_type === 'won');
    const lostStage = stages.find((s) => s.stage_type === 'lost');
    const pendingTasks = tasks.filter((t) => !t.completed_at);
    const contactName = lead.contact?.name || lead.contact?.phone || 'Contacto';

    // Cronología del chat: eventos en orden ascendente para leer como conversación
    const chatItems = useMemo(() => {
        const arr = [...events].sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
        const out = [];
        let currentDay = null;
        for (const e of arr) {
            const dayKey = new Date(e.created_at).toDateString();
            if (dayKey !== currentDay) {
                out.push({ kind: 'day', id: `d-${dayKey}`, label: dayLabel(e.created_at) });
                currentDay = dayKey;
            }
            if (e.event_type === 'message_in' || e.event_type === 'message_out') {
                out.push({ kind: 'bubble', event: e });
            } else {
                out.push({ kind: 'system', event: e });
            }
        }
        return out;
    }, [events]);

    useEffect(() => { if (tab === 'chat') bottomRef.current?.scrollIntoView({ behavior: 'smooth' }); }, [tab, chatItems.length]);

    // Polling casi en tiempo real (2s) mientras la pestaña Chat esté activa y
    // visible. Solo refetch los props que cambian (events + tasks + notes),
    // preservando scroll y estado local del formulario. 2s da la sensación de
    // "en vivo" sin cargar demasiado al servidor (el request es ligero:
    // solo esos 4 props, no la página completa).
    useEffect(() => {
        if (tab !== 'chat') return;
        const tick = () => {
            if (document.hidden) return; // no consume batería con la pestaña en segundo plano
            router.reload({ only: ['events', 'tasks', 'notes', 'lead'], preserveScroll: true, preserveState: true });
        };
        const id = setInterval(tick, 2000);
        return () => clearInterval(id);
    }, [tab]);

    return (
        <AuthenticatedLayout>
            <Head title={lead.title} />

            {/*
              La ficha reparte el alto disponible: encabezado arriba y panel de
              pestañas abajo, que scrollea por dentro.

              `min-h-full` y no `h-full`: normalmente entra en pantalla, pero en
              monitores bajos el panel conserva su mínimo legible y la página
              scrollea un poco, en vez de aplastar el historial del chat.

              Padding y separaciones al mínimo: cada rem que se lleva el
              encabezado se lo saca al chat, que es donde se trabaja.
            */}
            <div className="mx-auto max-w-[1600px] px-4 sm:px-6 lg:px-8 py-3 flex flex-col gap-3 lg:min-h-full">
                {/* Barra superior: volver + ventana de servicio */}
                <div className="flex items-center justify-between gap-3 shrink-0">
                    <Link href={route('leads.index')} className="text-sm text-emerald-600 hover:text-emerald-700 font-medium inline-flex items-center gap-1">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" /></svg>
                        Volver a leads
                    </Link>
                    {/* La ventana de servicio queda siempre a la vista: decide
                        si escribir ahora sale gratis, y eso no debería exigir
                        cambiar de pestaña. */}
                    <ServiceWindowBadge window={serviceWindow} showOrigin />
                </div>

                {/* Header pro: contacto + título + acciones destacadas.
                    Sticky: cuando el panel supera el alto de pantalla y la
                    página scrollea, el nombre y la etapa tienen que seguir a
                    la vista — son la referencia de dónde estás parado. */}
                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden shrink-0 sticky top-0 z-20">
                    <div className={`h-1.5 ${lead.status === 'won' ? 'bg-gradient-to-r from-emerald-500 to-teal-600' : lead.status === 'lost' ? 'bg-gradient-to-r from-red-400 to-rose-500' : 'bg-gradient-to-r from-sky-500 to-blue-600'}`} />
                    <div className="px-4 sm:px-5 py-2.5">
                        <div className="flex flex-col lg:flex-row lg:items-center gap-2.5">
                            {/* Avatar + info del contacto */}
                            <div className="flex items-center gap-3 min-w-0 flex-1">
                                <Avatar name={contactName} />
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2 mb-0.5">
                                        <h1 className="text-lg sm:text-xl font-bold text-gray-900 truncate">{lead.title}</h1>
                                        {lead.status === 'won' && <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold text-white bg-gradient-to-r from-emerald-500 to-teal-600 shadow-sm">🏆 Ganado</span>}
                                        {lead.status === 'lost' && <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold text-white bg-gradient-to-r from-red-400 to-rose-500 shadow-sm">✕ Perdido</span>}
                                    </div>
                                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-500">
                                        <span className="font-semibold text-gray-700">{contactName}</span>
                                        {lead.contact?.phone && (
                                            <span className="inline-flex items-center gap-1 font-mono text-xs">
                                                <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" /></svg>
                                                {lead.contact.phone}
                                            </span>
                                        )}
                                        <span className="text-gray-300">·</span>
                                        <span className="text-base font-extrabold text-gray-900 tabular-nums">{money(lead.value, lead.currency)}</span>
                                    </div>
                                    {(lead.tags ?? []).length > 0 && (
                                        <div className="flex flex-wrap gap-1 mt-1">
                                            {lead.tags.map((t) => (
                                                <span key={t.id} className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold text-white shadow-sm" style={{ backgroundColor: t.color }}>
                                                    {t.name}
                                                </span>
                                            ))}
                                        </div>
                                    )}
                                    {lead.source_ref && (
                                        <span className="inline-flex items-center gap-1.5 px-2 py-0.5 mt-1.5 rounded-full text-[10px] font-bold ring-1 bg-blue-50 text-blue-800 ring-blue-200">
                                            <span className="w-1.5 h-1.5 rounded-full bg-blue-500" />
                                            Anuncio {lead.source_ref}
                                            {lead.source_url && <a href={lead.source_url} target="_blank" rel="noreferrer" className="underline hover:text-blue-600 font-semibold ml-1">ver ↗</a>}
                                        </span>
                                    )}
                                </div>
                            </div>

                            {/* Acciones destacadas */}
                            {lead.status === 'open' && (
                                <div className="flex items-center gap-2 flex-wrap shrink-0">
                                    {wonStage && (
                                        <button onClick={() => moveTo(wonStage.id)} className="px-3.5 py-2 text-sm font-bold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:opacity-90 transition-all shadow-lg shadow-emerald-500/30 inline-flex items-center gap-1.5">
                                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.562.562 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z" /></svg>
                                            Ganado
                                        </button>
                                    )}
                                    {lostStage && (
                                        <button onClick={() => moveTo(lostStage.id)} className="px-3.5 py-2 text-sm font-semibold text-red-600 bg-white border border-red-200 rounded-xl hover:bg-red-50 transition-all shadow-sm">
                                            Perdido
                                        </button>
                                    )}
                                </div>
                            )}
                        </div>

                        {/* Stage stepper visual — reemplaza el dropdown por breadcrumb clickeable */}
                        <div className="mt-2 pt-2 border-t border-gray-100">
                            <div
                                className="flex items-center gap-1 overflow-x-auto pb-0.5"
                                title={lead.status === 'open'
                                    ? `Pipeline: ${lead.pipeline?.name ?? ''} — click en una etapa para mover el lead`
                                    : `Pipeline: ${lead.pipeline?.name ?? ''}`}
                            >
                                {stages.filter((s) => s.stage_type === 'open').map((s, idx, arr) => {
                                    const isCurrent = s.id === lead.stage_id;
                                    const currentIdx = arr.findIndex((st) => st.id === lead.stage_id);
                                    const isPast = currentIdx >= 0 && idx < currentIdx;
                                    return (
                                        <button
                                            key={s.id}
                                            onClick={() => !isCurrent && moveTo(s.id)}
                                            disabled={isCurrent || lead.status !== 'open'}
                                            className={`group flex-1 min-w-[100px] relative flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-bold transition-all ${
                                                isCurrent
                                                    ? 'text-white shadow-md cursor-default'
                                                    : isPast
                                                        ? 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100'
                                                        : 'bg-gray-50 text-gray-500 hover:bg-gray-100 hover:text-gray-700'
                                            }`}
                                            style={isCurrent ? { backgroundColor: s.color } : {}}
                                        >
                                            <span className={`w-4 h-4 rounded-full flex items-center justify-center text-[10px] font-bold shrink-0 ${
                                                isCurrent ? 'bg-white/25 text-white' : isPast ? 'bg-emerald-500 text-white' : 'bg-gray-200 text-gray-500'
                                            }`}>
                                                {isPast ? '✓' : idx + 1}
                                            </span>
                                            <span className="truncate">{s.name}</span>
                                        </button>
                                    );
                                })}
                            </div>
                            {/* La ayuda pasa al tooltip del stepper: como línea
                                fija se llevaba alto del historial en cada
                                carga, y solo hace falta la primera vez. */}
                        </div>
                    </div>

                    {/* Pestañas. Viven dentro del encabezado sticky para que,
                        cuando la conversación se alarga y la página scrollea,
                        sigan siempre accesibles junto al nombre del contacto —
                        sin tener que subir a la parte superior para cambiar. */}
                    <div className="flex border-b border-gray-100 bg-white overflow-x-auto">
                        {[
                            ['chat', '💬 Chat'],
                            ['datos', '📋 Datos del lead'],
                            ['tasks', `✅ Tareas (${pendingTasks.length})`],
                            ['notes', `📝 Notas (${notes.length})`],
                            ['timeline', `🕑 Timeline (${events.length})`],
                        ].map(([key, label]) => (
                            <button
                                key={key}
                                onClick={() => setTab(key)}
                                className={`px-3.5 sm:px-4 py-2.5 text-sm font-semibold transition-all border-b-2 whitespace-nowrap ${
                                    tab === key
                                        ? 'border-emerald-500 text-emerald-700'
                                        : 'border-transparent text-gray-400 hover:text-gray-600'
                                }`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </div>

                {flash?.success && <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 shadow-sm">{flash.success}</div>}

                {/* Sin grilla: con una sola tarjeta, la fila del grid se
                    dimensionaba por su contenido y la tarjeta desbordaba —
                    por eso al abrir Timeline la barra de pestañas se iba de
                    pantalla. Como hijo flex directo sí toma el alto restante. */}
                {(() => {
                    const panelDatos = (
                    <div className="[column-gap:1.25rem] xl:columns-2 [&>*]:mb-4 [&>*]:break-inside-avoid">
                        {/* Hero card del lead */}
                        {(() => {
                            const daysOpen = Math.max(0, Math.floor((new Date() - new Date(lead.created_at)) / 86400000));
                            const statusInfo = lead.status === 'won'
                                ? { icon: '🏆', label: 'Ganado', color: 'from-emerald-500 to-teal-600', ring: 'ring-emerald-200' }
                                : lead.status === 'lost'
                                    ? { icon: '✕', label: 'Perdido', color: 'from-red-400 to-rose-500', ring: 'ring-red-200' }
                                    : { icon: '⏳', label: 'Abierto', color: 'from-sky-500 to-blue-600', ring: 'ring-sky-200' };
                            return (
                                <div className={`rounded-2xl border border-gray-100 shadow-sm bg-white overflow-hidden`}>
                                    <div className={`h-1.5 bg-gradient-to-r ${statusInfo.color}`} />
                                    <div className="p-5">
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0 flex-1">
                                                <div className="inline-flex items-center gap-2 mb-2">
                                                    <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold text-white bg-gradient-to-r ${statusInfo.color} shadow-sm`}>
                                                        {statusInfo.icon} {statusInfo.label}
                                                    </span>
                                                    {lead.stage && (
                                                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold shadow-sm" style={{ backgroundColor: `${lead.stage.color}15`, color: lead.stage.color, border: `1px solid ${lead.stage.color}40` }}>
                                                            <span className="w-1.5 h-1.5 rounded-full" style={{ backgroundColor: lead.stage.color }} />
                                                            {lead.stage.name}
                                                        </span>
                                                    )}
                                                </div>
                                                <h3 className="text-lg font-bold text-gray-900 leading-snug">{lead.title}</h3>
                                            </div>
                                        </div>

                                        <div className="mt-4 grid grid-cols-3 gap-2 text-center">
                                            <div className="p-2 rounded-lg bg-gray-50">
                                                <div className="text-sm font-extrabold text-gray-900 tabular-nums truncate" title={money(lead.value, lead.currency)}>{money(lead.value, lead.currency)}</div>
                                                <div className="text-[9px] font-semibold text-gray-500 uppercase tracking-wide mt-0.5">Valor</div>
                                            </div>
                                            <div className="p-2 rounded-lg bg-gray-50">
                                                <div className="text-sm font-extrabold text-gray-900 tabular-nums">{daysOpen}d</div>
                                                <div className="text-[9px] font-semibold text-gray-500 uppercase tracking-wide mt-0.5">Antigüedad</div>
                                            </div>
                                            <div className="p-2 rounded-lg bg-gray-50">
                                                <div className="text-sm font-extrabold text-gray-900 tabular-nums">{tasks?.length ?? 0}</div>
                                                <div className="text-[9px] font-semibold text-gray-500 uppercase tracking-wide mt-0.5">Tareas</div>
                                            </div>
                                        </div>

                                        {lead.responsible && (
                                            <div className="mt-4 pt-4 border-t border-gray-100 flex items-center gap-2 text-xs">
                                                <Avatar name={lead.responsible.name} size="sm" />
                                                <div className="min-w-0 flex-1">
                                                    <div className="text-[10px] text-gray-400 uppercase font-semibold tracking-wide">Responsable</div>
                                                    <div className="text-sm font-semibold text-gray-800 truncate">{lead.responsible.name}</div>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            );
                        })()}

                        {/* Próxima acción — regla Kommo destacada */}
                        {lead.status === 'open' && (() => {
                            const nextTask = pendingTasks.slice().sort((a, b) => new Date(a.due_at) - new Date(b.due_at))[0];
                            if (nextTask) {
                                const overdue = new Date(nextTask.due_at) < new Date();
                                const meta = { call: '📞', meet: '🤝', follow_up: '🔔', email: '✉️', other: '📋' }[nextTask.task_type] ?? '📋';
                                return (
                                    <div className={`rounded-2xl border-2 shadow-sm overflow-hidden ${overdue ? 'border-red-300 bg-red-50/50' : 'border-emerald-200 bg-emerald-50/50'}`}>
                                        <div className={`px-4 py-2 text-white text-[10px] font-bold uppercase tracking-wider ${overdue ? 'bg-gradient-to-r from-red-500 to-rose-600' : 'bg-gradient-to-r from-emerald-500 to-teal-600'}`}>
                                            {overdue ? '🚨 Tarea vencida' : '⏭ Próxima acción'}
                                        </div>
                                        <div className="p-4">
                                            <div className="flex items-start gap-3">
                                                <span className="text-2xl shrink-0">{meta}</span>
                                                <div className="min-w-0 flex-1">
                                                    <p className="text-sm font-bold text-gray-900 leading-snug">{nextTask.text}</p>
                                                    <p className={`text-xs mt-1 tabular-nums font-semibold ${overdue ? 'text-red-600' : 'text-emerald-700'}`}>
                                                        {new Date(nextTask.due_at).toLocaleString('es', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}
                                                        {overdue && <span className="ml-1">· vencida</span>}
                                                    </p>
                                                    {nextTask.assignee && <p className="text-[10px] text-gray-500 mt-1">👤 {nextTask.assignee.name}</p>}
                                                </div>
                                            </div>
                                            <button
                                                onClick={() => setTab('tasks')}
                                                className="mt-3 w-full text-xs font-semibold text-emerald-700 bg-white hover:bg-emerald-100 border border-emerald-200 rounded-lg py-1.5 transition-colors"
                                            >
                                                Completar en tareas →
                                            </button>
                                        </div>
                                    </div>
                                );
                            }
                            return (
                                <div className="rounded-2xl border-2 border-amber-200 bg-amber-50/50 shadow-sm overflow-hidden">
                                    <div className="px-4 py-2 text-white text-[10px] font-bold uppercase tracking-wider bg-gradient-to-r from-amber-500 to-orange-500">⚠ Sin próxima acción</div>
                                    <div className="p-4">
                                        <p className="text-sm text-amber-900 leading-snug">Este lead no tiene tareas pendientes — se puede enfriar rápido.</p>
                                        <button
                                            onClick={() => setTab('tasks')}
                                            className="mt-3 w-full text-xs font-bold text-white bg-gradient-to-r from-amber-500 to-orange-600 hover:opacity-90 rounded-lg py-2 shadow shadow-amber-500/20 transition-all"
                                        >
                                            + Agendar siguiente paso
                                        </button>
                                    </div>
                                </div>
                            );
                        })()}

                        {/* Cuánto queda para escribirle sin que Meta cobre. Va
                            arriba del todo: condiciona si conviene responder
                            ahora o esperar. */}
                        <ServiceWindowCard window={serviceWindow} />

                        <OriginCard lead={lead} />

                        <form onSubmit={saveEdit} className="space-y-3">
                            {/* Sección: Datos básicos */}
                            <details open className="group bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                                <summary className="px-5 py-3.5 cursor-pointer list-none flex items-center justify-between border-b border-gray-100 hover:bg-gray-50 transition-colors">
                                    <h3 className="text-sm font-bold text-gray-900 flex items-center gap-2">
                                        <span className="w-1 h-4 bg-[#045474] rounded-full" />
                                        Datos del lead
                                    </h3>
                                    <svg className="w-4 h-4 text-gray-400 transition-transform group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                                </summary>
                                <div className="p-5 space-y-4">
                                    <div>
                                        <label className="block text-xs font-semibold uppercase tracking-wider text-gray-400 mb-1.5">Título</label>
                                        <input value={editForm.data.title} onChange={(e) => editForm.setData('title', e.target.value)} className={inputClass} />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold uppercase tracking-wider text-gray-400 mb-1.5">Valor (Bs.)</label>
                                        <input type="number" step="0.01" min="0" value={editForm.data.value} onChange={(e) => editForm.setData('value', e.target.value)} className={inputClass} />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold uppercase tracking-wider text-gray-400 mb-1.5">Responsable</label>
                                        {isAdmin ? (
                                            <select value={editForm.data.responsible_user_id} onChange={(e) => editForm.setData('responsible_user_id', e.target.value)} className={inputClass}>
                                                <option value="">— Nadie —</option>
                                                {members.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                                            </select>
                                        ) : (
                                            <div className="flex items-center gap-2 px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 text-gray-700">
                                                <svg className="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                                                {lead.responsible?.name || 'Sin asignar'}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </details>

                            {/* Sección: Vinculado con */}
                            <details className="group bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                                <summary className="px-5 py-3.5 cursor-pointer list-none flex items-center justify-between hover:bg-gray-50 transition-colors">
                                    <h3 className="text-sm font-bold text-gray-900 flex items-center gap-2">
                                        <span className="w-1 h-4 bg-emerald-500 rounded-full" />
                                        Vinculado con
                                    </h3>
                                    <svg className="w-4 h-4 text-gray-400 transition-transform group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                                </summary>
                                <div className="px-5 pb-5 space-y-4 border-t border-gray-100 pt-4">
                                    <div>
                                        <label className="block text-xs font-semibold uppercase tracking-wider text-gray-400 mb-1.5">Contacto</label>
                                        <select value={editForm.data.contact_id} onChange={(e) => editForm.setData('contact_id', e.target.value)} className={inputClass}>
                                            <option value="">— Sin contacto —</option>
                                            {contacts.map((c) => <option key={c.id} value={c.id}>{c.name || c.phone}</option>)}
                                        </select>
                                        {lead.contact?.id && (
                                            <a href={route('contacts.timeline', lead.contact.id)} className="mt-1.5 inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-600 hover:text-emerald-700">
                                                Ver timeline 360° del contacto →
                                            </a>
                                        )}
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold uppercase tracking-wider text-gray-400 mb-1.5">Empresa</label>
                                        <select value={editForm.data.company_id} onChange={(e) => editForm.setData('company_id', e.target.value)} className={inputClass}>
                                            <option value="">— Sin empresa —</option>
                                            {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                                        </select>
                                    </div>
                                </div>
                            </details>

                            {/* Sección: Custom fields */}
                            {customFields.length > 0 && (
                                <details className="group bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                                    <summary className="px-5 py-3.5 cursor-pointer list-none flex items-center justify-between hover:bg-gray-50 transition-colors">
                                        <h3 className="text-sm font-bold text-gray-900 flex items-center gap-2">
                                            <span className="w-1 h-4 bg-purple-500 rounded-full" />
                                            Campos personalizados
                                            <span className="text-[10px] font-medium text-gray-400 normal-case">({customFields.length})</span>
                                        </h3>
                                        <svg className="w-4 h-4 text-gray-400 transition-transform group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                                    </summary>
                                    <div className="px-5 pb-5 space-y-4 border-t border-gray-100 pt-4">
                                        {customFields.map((field) => (
                                            <div key={field.id}>
                                                <label className="block text-xs font-semibold uppercase tracking-wider text-gray-400 mb-1.5">{field.name}</label>
                                                {field.field_type === 'select' ? (
                                                    <select
                                                        value={editForm.data.custom_values[field.id] ?? ''}
                                                        onChange={(e) => editForm.setData('custom_values', { ...editForm.data.custom_values, [field.id]: e.target.value })}
                                                        className={inputClass}
                                                    >
                                                        <option value="">—</option>
                                                        {(field.options ?? []).map((opt) => <option key={opt} value={opt}>{opt}</option>)}
                                                    </select>
                                                ) : (
                                                    <input
                                                        type={field.field_type === 'number' ? 'number' : field.field_type === 'date' ? 'date' : 'text'}
                                                        value={editForm.data.custom_values[field.id] ?? ''}
                                                        onChange={(e) => editForm.setData('custom_values', { ...editForm.data.custom_values, [field.id]: e.target.value })}
                                                        className={inputClass}
                                                    />
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                </details>
                            )}

                            {/* Sección: Etiquetas */}
                            <details className="group bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                                <summary className="px-5 py-3.5 cursor-pointer list-none flex items-center justify-between hover:bg-gray-50 transition-colors">
                                    <h3 className="text-sm font-bold text-gray-900 flex items-center gap-2">
                                        <span className="w-1 h-4 bg-amber-500 rounded-full" />
                                        Etiquetas
                                        {(lead.tags?.length ?? 0) > 0 && <span className="text-[10px] font-medium text-gray-400 normal-case">({lead.tags.length})</span>}
                                    </h3>
                                    <svg className="w-4 h-4 text-gray-400 transition-transform group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                                </summary>
                                <div className="px-5 pb-5 border-t border-gray-100 pt-4">
                                    <div className="flex flex-wrap gap-1.5">
                                        {allTags.map((tag) => {
                                            const active = (lead.tags ?? []).some((t) => t.id === tag.id);
                                            return (
                                                <button
                                                    key={tag.id}
                                                    type="button"
                                                    onClick={() => toggleTag(tag.id)}
                                                    className={`rounded-full px-2.5 py-1 text-xs font-medium transition-all ${active ? 'text-white shadow-md scale-105' : 'bg-gray-100 text-gray-500 hover:bg-gray-200'}`}
                                                    style={active ? { backgroundColor: tag.color } : {}}
                                                >
                                                    {tag.name}
                                                </button>
                                            );
                                        })}
                                        {newTag === null ? (
                                            <button type="button" onClick={() => setNewTag('')} className="rounded-full px-2.5 py-1 text-xs font-medium border border-dashed border-gray-300 text-gray-400 hover:border-emerald-400 hover:text-emerald-600 transition-all">
                                                + Nueva
                                            </button>
                                        ) : (
                                            <span className="inline-flex items-center gap-1">
                                                <input
                                                    autoFocus
                                                    value={newTag}
                                                    onChange={(e) => setNewTag(e.target.value)}
                                                    onKeyDown={(e) => {
                                                        if (e.key === 'Enter') {
                                                            e.preventDefault();
                                                            if (newTag.trim()) router.post(route('tags.store'), { name: newTag.trim() }, { preserveScroll: true, onSuccess: () => setNewTag(null) });
                                                        }
                                                        if (e.key === 'Escape') setNewTag(null);
                                                    }}
                                                    placeholder="nombre + Enter"
                                                    className="w-28 px-2 py-1 border border-emerald-300 rounded-full text-xs focus:outline-none focus:ring-2 focus:ring-emerald-500/30"
                                                />
                                            </span>
                                        )}
                                    </div>
                                </div>
                            </details>

                            {/* Acciones */}
                            <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-2">
                                <button type="submit" disabled={editForm.processing} className="w-full px-4 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-[#045474] to-[#1c486c] rounded-xl hover:opacity-90 disabled:opacity-50 transition-all shadow-lg shadow-[#045474]/20 inline-flex items-center justify-center gap-2">
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                    {editForm.processing ? 'Guardando…' : 'Guardar cambios'}
                                </button>
                            </div>

                            {/* Danger zone — solo admin/owner: borrar el lead se
                                lleva el historial de conversación y no se puede
                                deshacer. El servidor lo corta igual con 403. */}
                            {isAdmin && (
                            <details className="group rounded-2xl overflow-hidden bg-red-50/30 border border-red-100">
                                <summary className="px-5 py-2.5 cursor-pointer list-none flex items-center justify-between text-xs font-semibold text-red-500 hover:text-red-700 transition-colors">
                                    <span className="inline-flex items-center gap-1.5">
                                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                                        Zona peligrosa
                                    </span>
                                    <svg className="w-3.5 h-3.5 transition-transform group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                                </summary>
                                <div className="px-5 pb-4 pt-1">
                                    <button type="button" onClick={() => setConfirmDelete(true)} className="w-full px-3 py-2 text-xs font-semibold text-red-600 border border-red-200 rounded-lg hover:bg-red-100 transition-all inline-flex items-center justify-center gap-1.5">
                                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                                        Eliminar lead y todo su historial
                                    </button>
                                </div>
                            </details>
                            )}
                        </form>
                    </div>
                    );

                    return (
                    /* Panel único a pantalla completa. El chat manda —es donde
                       se trabaja— y todo lo demás vive en pestañas, como en
                       cualquier CRM de WhatsApp: nada de una columna lateral
                       que le robe ancho al hilo. */
                    /* Llega justo hasta abajo de la pantalla, a la par del
                       sidebar: sin scroll de página, todo el trabajo pasa
                       dentro del panel. */
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden flex flex-col lg:flex-1 h-[calc(100vh-13rem)] lg:h-auto lg:min-h-[34rem]">
                        {tab === 'datos' && (
                            <div className="flex-1 min-h-0 overflow-y-auto p-4 sm:p-5">
                                {/* Dos columnas en pantallas anchas: el
                                    contenido son fichas cortas y en una sola
                                    columna obligaba a scrollear de más. El
                                    columnado vive en panelDatos y usa todo el
                                    ancho del panel, sin tope centrado que deje
                                    huecos a los lados. */}
                                {panelDatos}
                            </div>
                        )}

                        {tab === 'chat' && (
                            <>
                                {/* Barra de acciones del chat. Sin avatar ni
                                    nombre: ya están en el encabezado de la
                                    ficha, justo arriba, y repetirlos le comía
                                    ~70px al historial. */}
                                <div className="flex items-center justify-end gap-2 px-4 py-2 border-b border-gray-100 bg-gray-50/60">
                                    {lead.contact?.phone && (
                                        <a
                                            href={`https://wa.me/${(lead.contact.phone_normalized ?? lead.contact.phone).replace(/[^\d]/g, '')}`}
                                            target="_blank"
                                            rel="noreferrer"
                                            title="Llamar/abrir chat en WhatsApp"
                                            className="inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-xs font-bold border border-emerald-300 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 transition-all shadow-sm"
                                        >
                                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" /></svg>
                                            Llamar
                                        </a>
                                    )}
                                    {whatsappEnabled && lead.wacrm_conversation_id && (
                                        (isAdmin || !lead.responsible_user_id || lead.responsible_user_id === auth?.user?.id) ? (
                                            <button
                                                type="button"
                                                onClick={() => router.patch(route('leads.ai-mode', lead.id), { ai_enabled: !lead.ai_enabled }, { preserveScroll: true })}
                                                title={lead.ai_enabled ? 'Cambiar a Humano (silenciar IA)' : 'Cambiar a IA (auto-respuesta)'}
                                                className={`inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-xs font-bold border transition-all shadow-sm ${
                                                    lead.ai_enabled
                                                        ? 'border-violet-300 bg-gradient-to-br from-violet-50 to-purple-50 text-violet-700 shadow-violet-500/10'
                                                        : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50'
                                                }`}
                                            >
                                                {lead.ai_enabled ? (
                                                    <>
                                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                                                        </svg>
                                                        IA activa
                                                        <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse" />
                                                    </>
                                                ) : (
                                                    <>
                                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                                        </svg>
                                                        Humano
                                                    </>
                                                )}
                                            </button>
                                        ) : (
                                            <span
                                                title="Solo el responsable o el admin pueden cambiar el modo IA"
                                                className={`inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-xs font-bold border ${
                                                    lead.ai_enabled ? 'border-violet-200 bg-violet-50 text-violet-700' : 'border-gray-200 bg-gray-50 text-gray-600'
                                                }`}
                                            >
                                                {lead.ai_enabled ? '✨ IA activa' : '👤 Humano'}
                                            </span>
                                        )
                                    )}
                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold ring-1 bg-emerald-50 text-emerald-700 ring-emerald-200">
                                        <span className="w-1.5 h-1.5 rounded-full bg-emerald-500" />
                                        {lead.status === 'open' ? 'Activo' : lead.status}
                                    </span>
                                </div>

                                {/* Hilo */}
                                <div className="flex-1 min-h-0 overflow-y-auto px-5 py-4 space-y-2 bg-gradient-to-b from-gray-50 to-gray-100">
                                    {chatItems.length === 0 && (
                                        <p className="py-8 text-center text-sm text-gray-400">Sin conversación todavía. Envía el primer mensaje ↓</p>
                                    )}
                                    {chatItems.map((it) => {
                                        if (it.kind === 'day') return <DateSeparator key={it.id} label={it.label} />;
                                        if (it.kind === 'bubble') return <ChatBubble key={it.event.id} event={it.event} contactName={contactName} onOpenImage={setLightbox} />;
                                        return <SystemEvent key={it.event.id} event={it.event} />;
                                    })}
                                    {lead.ai_pending && (
                                        <div className="flex flex-col items-end">
                                            <span className="text-[10px] font-bold mb-0.5 mr-2 text-violet-600">✨ IA</span>
                                            <div className="rounded-2xl px-4 py-3 text-sm bg-gradient-to-br from-violet-500/90 to-purple-600/90 text-white rounded-br-md shadow-md shadow-violet-500/20 flex items-center gap-2">
                                                <span className="flex items-center gap-1">
                                                    <span className="w-1.5 h-1.5 rounded-full bg-white/80 animate-bounce" style={{ animationDelay: '0ms' }} />
                                                    <span className="w-1.5 h-1.5 rounded-full bg-white/80 animate-bounce" style={{ animationDelay: '150ms' }} />
                                                    <span className="w-1.5 h-1.5 rounded-full bg-white/80 animate-bounce" style={{ animationDelay: '300ms' }} />
                                                </span>
                                                <span className="italic text-white/90">Pensando respuesta…</span>
                                            </div>
                                        </div>
                                    )}
                                    <AiPausedNotice pausedUntil={lead.ai_paused_until} />
                                    <div ref={bottomRef} />
                                </div>

                                {/* Composer */}
                                <form
                                    onSubmit={(e) => { e.preventDefault(); sendWhatsapp(); }}
                                    className="border-t border-gray-100 bg-white p-3"
                                >
                                    {!whatsappEnabled ? (
                                        <p className="text-xs text-gray-400 text-center py-2">
                                            {lead.contact?.phone
                                                ? <>Activa la <Link href={route('settings.integration')} className="text-emerald-600 font-semibold underline">integración con el CRM de WhatsApp</Link> para escribirle desde aquí.</>
                                                : 'Asigna un contacto con teléfono para enviarle WhatsApp.'}
                                        </p>
                                    ) : (
                                        <>
                                            {waForm.errors.text && <p className="mb-2 text-xs text-red-500 font-medium">{waForm.errors.text}</p>}
                                            <div className="flex items-end gap-2 flex-wrap">
                                                <input
                                                    ref={fileInputRef}
                                                    type="file"
                                                    className="hidden"
                                                    accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt"
                                                    onChange={(e) => sendFile(e.target.files[0])}
                                                />
                                                <button
                                                    type="button"
                                                    onClick={() => fileInputRef.current?.click()}
                                                    disabled={uploading}
                                                    title="Adjuntar archivo"
                                                    className="rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-gray-600 hover:bg-gray-50 disabled:opacity-50 shadow-sm"
                                                >
                                                    {uploading ? (
                                                        <svg className="w-4 h-4 animate-spin" viewBox="0 0 24 24"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none"/><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                                                    ) : (
                                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" /></svg>
                                                    )}
                                                </button>
                                                <VoiceRecorder onSend={sendFile} disabled={uploading} />

                                                {/* Plantillas rápidas */}
                                                <div className="relative">
                                                    <button
                                                        type="button"
                                                        onClick={() => setShowQuickReplies(!showQuickReplies)}
                                                        disabled={quickReplies.length === 0}
                                                        title={quickReplies.length === 0 ? 'Sin plantillas (crear en wacrm)' : 'Plantillas'}
                                                        className="rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-gray-600 hover:bg-emerald-50 hover:border-emerald-300 hover:text-emerald-700 disabled:opacity-50 shadow-sm"
                                                    >
                                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg>
                                                    </button>
                                                    {showQuickReplies && (
                                                        <div className="absolute bottom-14 left-0 z-20 w-72 max-h-64 overflow-y-auto bg-white rounded-xl shadow-2xl border border-gray-100 py-2">
                                                            <div className="px-3 py-1.5 flex items-center justify-between border-b border-gray-100">
                                                                <span className="text-[10px] font-bold uppercase tracking-wider text-gray-500">Plantillas</span>
                                                                <button type="button" onClick={() => setShowQuickReplies(false)} className="text-gray-400 hover:text-gray-600">×</button>
                                                            </div>
                                                            {quickReplies.map((r) => (
                                                                <button key={r.id} type="button" onClick={() => insertQuickReply(r)} className="w-full text-left px-3 py-2 hover:bg-emerald-50">
                                                                    <code className="inline-block px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-800 text-[10px] font-bold font-mono">/{r.shortcut}</code>
                                                                    <p className="text-xs text-gray-600 mt-1 truncate">{r.content}</p>
                                                                </button>
                                                            ))}
                                                        </div>
                                                    )}
                                                </div>

                                                <textarea
                                                    ref={waInputRef}
                                                    rows={1}
                                                    value={waForm.data.text}
                                                    onChange={(e) => waForm.setData('text', e.target.value)}
                                                    onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendWhatsapp(); } }}
                                                    placeholder={`Mensaje para ${contactName}…`}
                                                    className="flex-1 min-w-[200px] resize-none px-4 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-[#045474]/20 focus:border-[#045474] focus:bg-white max-h-32"
                                                />
                                                <button type="submit" disabled={waForm.processing || !waForm.data.text.trim()} className="rounded-xl px-4 py-2.5 text-sm font-semibold text-white bg-gradient-to-br from-[#045474] to-[#1c486c] hover:opacity-90 disabled:opacity-50 shadow-lg shadow-[#045474]/20 flex items-center gap-1.5">
                                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" /></svg>
                                                    {waForm.processing ? '…' : 'Enviar'}
                                                </button>
                                            </div>
                                        </>
                                    )}
                                </form>
                            </>
                        )}

                        {tab === 'tasks' && (
                            <div className="flex-1 min-h-0 overflow-y-auto p-5 space-y-4">
                                <form
                                    onSubmit={(e) => { e.preventDefault(); taskForm.post(route('tasks.store'), { preserveScroll: true, onSuccess: () => taskForm.reset('text', 'due_at') }); }}
                                    className="rounded-xl bg-gray-50 border border-gray-100 p-4 space-y-3"
                                >
                                    <p className="text-xs font-bold uppercase tracking-wider text-gray-500">Nueva tarea</p>
                                    <div className="grid sm:grid-cols-3 gap-3">
                                        <select value={taskForm.data.task_type} onChange={(e) => taskForm.setData('task_type', e.target.value)} className={inputClass.replace('bg-gray-50', 'bg-white')}>
                                            <option value="call">📞 Llamar</option>
                                            <option value="meet">🤝 Reunión</option>
                                            <option value="follow_up">🔔 Seguimiento</option>
                                            <option value="email">✉️ Email</option>
                                            <option value="other">Otra</option>
                                        </select>
                                        <input type="datetime-local" value={taskForm.data.due_at} onChange={(e) => taskForm.setData('due_at', e.target.value)} required className={inputClass.replace('bg-gray-50', 'bg-white')} />
                                        <select value={taskForm.data.assigned_to} onChange={(e) => taskForm.setData('assigned_to', e.target.value)} className={inputClass.replace('bg-gray-50', 'bg-white')}>
                                            <option value="">Yo</option>
                                            {members.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                                        </select>
                                    </div>
                                    <div className="flex gap-2">
                                        <input value={taskForm.data.text} onChange={(e) => taskForm.setData('text', e.target.value)} placeholder="¿Qué hay que hacer?" required className={inputClass.replace('bg-gray-50', 'bg-white')} />
                                        <button type="submit" disabled={taskForm.processing} className="shrink-0 px-4 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 disabled:opacity-50 transition-all shadow-lg shadow-emerald-500/20">
                                            Crear
                                        </button>
                                    </div>
                                </form>

                                <ul className="space-y-2">
                                    {tasks.map((task) => {
                                        const overdue = !task.completed_at && new Date(task.due_at) < new Date();
                                        return (
                                            <li key={task.id} className={`flex items-center gap-3 rounded-xl border p-3.5 ${task.completed_at ? 'border-gray-100 bg-gray-50/50 opacity-60' : overdue ? 'border-red-200 bg-red-50/50' : 'border-gray-100 bg-white'}`}>
                                                {!task.completed_at ? (
                                                    <button
                                                        onClick={() => completeTask(task)}
                                                        className="w-5 h-5 shrink-0 rounded-full border-2 border-gray-300 hover:border-emerald-500 hover:bg-emerald-50 transition-all"
                                                        title="Completar"
                                                    />
                                                ) : (
                                                    <span className="w-5 h-5 shrink-0 rounded-full bg-emerald-500 text-white flex items-center justify-center text-[10px]">✓</span>
                                                )}
                                                <div className="flex-1 min-w-0">
                                                    <p className={`text-sm font-medium ${task.completed_at ? 'line-through text-gray-400' : 'text-gray-900'}`}>{task.text}</p>
                                                    <p className="text-xs text-gray-400">
                                                        {task.assignee?.name ?? '—'} · {new Date(task.due_at).toLocaleString('es', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}
                                                        {overdue && <span className="text-red-500 font-semibold ml-1">· vencida</span>}
                                                        {task.result_note && <span className="ml-1">· {task.result_note}</span>}
                                                    </p>
                                                </div>
                                            </li>
                                        );
                                    })}
                                    {tasks.length === 0 && <p className="py-6 text-center text-sm text-gray-400">Sin tareas</p>}
                                </ul>
                            </div>
                        )}

                        {tab === 'notes' && (
                            <div className="flex-1 min-h-0 overflow-y-auto p-5 space-y-4">
                                <form
                                    onSubmit={(e) => { e.preventDefault(); noteForm.post(route('leads.notes.add', lead.id), { preserveScroll: true, onSuccess: () => noteForm.reset() }); }}
                                    className="flex gap-2"
                                >
                                    <input value={noteForm.data.text} onChange={(e) => noteForm.setData('text', e.target.value)} placeholder="Escribe una nota interna…" required className={inputClass} />
                                    <button type="submit" disabled={noteForm.processing} className="shrink-0 px-4 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-amber-500 to-orange-500 rounded-xl hover:from-amber-400 hover:to-orange-400 disabled:opacity-50 transition-all shadow-lg shadow-amber-500/20">
                                        Añadir
                                    </button>
                                </form>
                                <ul className="space-y-2">
                                    {notes.map((note) => (
                                        <li key={note.id} className="rounded-xl bg-amber-50/60 border border-amber-100 p-3.5">
                                            <p className="text-sm text-gray-800 whitespace-pre-wrap">{note.text}</p>
                                            <p className="text-[11px] text-gray-400 mt-1.5">{note.author?.name ?? '—'} · {new Date(note.created_at).toLocaleString('es', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</p>
                                        </li>
                                    ))}
                                    {notes.length === 0 && <p className="py-6 text-center text-sm text-gray-400">Sin notas</p>}
                                </ul>
                            </div>
                        )}

                        {tab === 'timeline' && (
                            <div className="flex-1 min-h-0 overflow-y-auto p-5 space-y-4">
                                {events.map((event) => <TimelineEvent key={event.id} event={event} />)}
                                {events.length === 0 && <p className="py-8 text-center text-sm text-gray-400">Sin actividad todavía</p>}
                            </div>
                        )}
                    </div>
                    );
                    })()}
            </div>

            <DeleteLeadModal
                lead={lead}
                show={confirmDelete}
                onClose={() => setConfirmDelete(false)}
            />
            <ImageModal src={lightbox?.src} alt={lightbox?.alt} onClose={() => setLightbox(null)} />
        </AuthenticatedLayout>
    );
}

/**
 * Confirmación de borrado del lead.
 *
 * Obliga a escribir el nombre del contacto: es una acción irreversible que se
 * lleva por delante todo el historial de conversación, y un `confirm()` del
 * navegador se acepta por reflejo sin leerlo.
 */
function DeleteLeadModal({ lead, show, onClose }) {
    const [typed, setTyped] = useState('');
    const [deleting, setDeleting] = useState(false);

    const expected = lead.contact?.name || lead.title;
    const matches = typed.trim().toLowerCase() === expected.trim().toLowerCase();

    useEffect(() => { if (show) setTyped(''); }, [show]);

    const submit = (e) => {
        e.preventDefault();
        if (!matches || deleting) return;

        setDeleting(true);
        router.delete(route('leads.destroy', lead.id), {
            onFinish: () => setDeleting(false),
        });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="lg">
            <form onSubmit={submit} className="p-6">
                <div className="flex items-start gap-3">
                    <div className="w-10 h-10 shrink-0 rounded-xl bg-gradient-to-br from-red-500 to-rose-600 flex items-center justify-center text-white shadow-md">
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                    </div>
                    <div className="min-w-0">
                        <h2 className="text-lg font-bold text-gray-900">Eliminar «{lead.title}»</h2>
                        <p className="text-sm text-gray-500 mt-0.5">Esto no se puede deshacer.</p>
                    </div>
                </div>

                <ul className="mt-4 space-y-1.5 text-sm text-gray-600 bg-red-50/60 border border-red-100 rounded-xl p-4">
                    <li className="flex gap-2"><span className="text-red-500">•</span> Se borra <strong>todo el historial de conversación</strong> del lead.</li>
                    <li className="flex gap-2"><span className="text-red-500">•</span> Se pierden sus tareas, notas y el timeline.</li>
                    <li className="flex gap-2"><span className="text-red-500">•</span> El contacto se conserva, pero deja de tener este lead.</li>
                </ul>

                <div className="mt-5">
                    <label htmlFor="confirm_name" className="block text-xs font-semibold uppercase tracking-wider text-gray-400 mb-1.5">
                        Escribí <span className="font-mono normal-case tracking-normal text-gray-700">{expected}</span> para confirmar
                    </label>
                    <input
                        id="confirm_name"
                        value={typed}
                        onChange={(e) => setTyped(e.target.value)}
                        autoComplete="off"
                        className="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-red-500/30 focus:border-red-500 focus:bg-white transition-all"
                    />
                </div>

                <div className="mt-6 flex justify-end gap-2">
                    <button type="button" onClick={onClose} className="px-4 py-2 rounded-xl text-sm font-semibold text-gray-600 hover:bg-gray-100 transition-colors">
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        disabled={!matches || deleting}
                        className="px-4 py-2 rounded-xl text-sm font-bold text-white bg-red-600 hover:bg-red-700 shadow-sm disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                    >
                        {deleting ? 'Eliminando…' : 'Eliminar definitivamente'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}
