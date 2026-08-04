import { useEffect, useRef, useState } from 'react';
import Recorder from 'opus-recorder';
import encoderPath from 'opus-recorder/dist/encoderWorker.min.js?url';

/**
 * Piezas del chat de WhatsApp, compartidas por la ficha del lead y el Inbox.
 *
 * Estaban dentro de `Pages/Leads/Show.jsx`. Al llevar el chat también al
 * Inbox había dos caminos: duplicarlas —y que dentro de un mes las burbujas
 * se vean distinto según por dónde entraste— o sacarlas acá. Todo lo que
 * pinta un mensaje vive en este archivo y en ninguna otra parte.
 */

export const AVATAR_COLORS = [
    'from-emerald-500 to-teal-600',
    'from-blue-500 to-indigo-600',
    'from-purple-500 to-pink-600',
    'from-amber-500 to-orange-600',
    'from-rose-500 to-red-600',
    'from-cyan-500 to-sky-600',
    'from-lime-500 to-green-600',
    'from-fuchsia-500 to-purple-600',
];

export function avatarFor(name) {
    const label = (name || '?').trim();
    const initials = label.split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '?';
    let hash = 0;
    for (let i = 0; i < label.length; i++) hash = (hash * 31 + label.charCodeAt(i)) | 0;
    const gradient = AVATAR_COLORS[Math.abs(hash) % AVATAR_COLORS.length];

    return { initials, gradient };
}

export function Avatar({ name, size = 'md' }) {
    const { initials, gradient } = avatarFor(name);
    const sizes = { sm: 'w-8 h-8 text-xs', md: 'w-10 h-10 text-sm', lg: 'w-12 h-12 text-base' };

    return (
        <div className={`${sizes[size]} rounded-full bg-gradient-to-br ${gradient} flex items-center justify-center font-bold text-white shadow-sm shrink-0`}>
            {initials}
        </div>
    );
}

export function dayLabel(iso) {
    const d = new Date(iso);
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);
    const same = (a, b) => a.toDateString() === b.toDateString();
    if (same(d, today)) return 'Hoy';
    if (same(d, yesterday)) return 'Ayer';

    return d.toLocaleDateString('es', { weekday: 'long', day: 'numeric', month: 'long' });
}

export function DateSeparator({ label }) {
    return (
        <div className="flex items-center gap-3 py-2">
            <div className="flex-1 h-px bg-gray-200" />
            <span className="text-[11px] font-bold uppercase tracking-wider text-gray-400 bg-white px-3 py-1 rounded-full shadow-sm">{label}</span>
            <div className="flex-1 h-px bg-gray-200" />
        </div>
    );
}

/** Un solo lector a la vez: dos voces encimadas no se entienden. */
const ttsState = { current: null };

export function speakText(text, onEnd) {
    if (!('speechSynthesis' in window)) { onEnd?.(); return; }
    if (ttsState.current) {
        window.speechSynthesis.cancel();
        const prev = ttsState.current;
        ttsState.current = null;
        prev.onEnd?.();
        if (prev.text === text) return;
    }
    const u = new SpeechSynthesisUtterance(text);
    u.lang = 'es-BO';
    u.rate = 1.05;
    u.onend = () => { ttsState.current = null; onEnd?.(); };
    u.onerror = () => { ttsState.current = null; onEnd?.(); };
    window.speechSynthesis.speak(u);
    ttsState.current = { text, onEnd };
}

export const EVENT_META = {
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
    quote_created: { label: 'Cotización', icon: '🧾', color: 'bg-indigo-100 text-indigo-700' },
};

export function outboundAuthor(p) {
    if (p.sender === 'bot') return { text: '✨ IA', color: 'text-violet-600' };
    const name = p.sender_name || 'Agente';
    const isAdmin = p.sender_role === 'owner' || p.sender_role === 'admin';

    return { text: name + (isAdmin ? ' · Admin' : ''), color: 'text-[#045474]' };
}

export const TYPE_META = {
    audio: { icon: '🎙', label: 'Audio' },
    image: { icon: '🖼️', label: 'Imagen' },
    video: { icon: '🎥', label: 'Video' },
    document: { icon: '📄', label: 'Documento' },
    sticker: { icon: '🟪', label: 'Sticker' },
};

export function ChatBubble({ event, contactName, onOpenImage }) {
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

export function SystemEvent({ event }) {
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

/** Grabador de voz — mismo patrón que wacrm (opus-recorder → ogg/opus). */
export function VoiceRecorder({ onSend, disabled }) {
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

/**
 * Arma la lista del hilo: mensajes y eventos de sistema en orden cronológico,
 * con un separador por día.
 */
export function buildChatItems(events = []) {
    const ordered = [...events].sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
    const items = [];
    let lastDay = null;

    ordered.forEach((ev) => {
        const label = dayLabel(ev.created_at);
        if (label !== lastDay) {
            items.push({ kind: 'day', key: `day-${ev.id}`, label });
            lastDay = label;
        }
        items.push({
            kind: ['message_in', 'message_out'].includes(ev.event_type) ? 'msg' : 'sys',
            key: ev.id,
            event: ev,
        });
    });

    return items;
}
