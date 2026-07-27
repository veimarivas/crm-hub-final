import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ServiceWindowBadge from '@/Components/ServiceWindowBadge';
import Modal from '@/Components/Modal';
import { showUndo } from '@/Components/UndoToast';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

function money(value, currency) {
    return new Intl.NumberFormat('es', { style: 'currency', currency: currency || 'USD', maximumFractionDigits: 0 }).format(value || 0);
}

function waitLabel(mins) {
    if (mins < 60) return `${mins}m`;
    const h = Math.floor(mins / 60);
    return h < 24 ? `${h}h` : `${Math.floor(h / 24)}d`;
}

const AVATAR_COLORS = ['from-emerald-500 to-teal-600', 'from-blue-500 to-indigo-600', 'from-purple-500 to-pink-600', 'from-amber-500 to-orange-600', 'from-rose-500 to-red-600', 'from-cyan-500 to-sky-600'];
function avatarFor(name) {
    const label = (name || '?').trim();
    const initials = label.split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '?';
    let hash = 0;
    for (let i = 0; i < label.length; i++) hash = (hash * 31 + label.charCodeAt(i)) | 0;
    return { initials, gradient: AVATAR_COLORS[Math.abs(hash) % AVATAR_COLORS.length] };
}

const SOURCE_META = {
    whatsapp: { icon: '💬', label: 'WhatsApp' },
    lead_ad: { icon: '📣', label: 'Ad' },
    web_form: { icon: '📋', label: 'Form' },
    manual: { icon: '✍️', label: 'Manual' },
    api: { icon: '⚙️', label: 'API' },
    other: { icon: '❓', label: 'Otro' },
};

function LeadCard({ lead, currency, slaMinutes, selected, onToggleSelect, anySelected }) {
    const contactName = lead.contact?.name || lead.contact?.phone || 'Sin contacto';
    const av = avatarFor(contactName);
    const src = SOURCE_META[lead.source] ?? SOURCE_META.other;
    const isUrgent = lead.waiting_minutes >= slaMinutes && lead.last_message_direction === 'in';
    const phone = lead.contact?.phone_normalized || lead.contact?.phone;

    return (
        <div
            draggable={!anySelected}
            onDragStart={(e) => { e.dataTransfer.setData('text/lead-id', lead.id); e.dataTransfer.effectAllowed = 'move'; }}
            className={`group relative rounded-xl border bg-white p-3 shadow-sm hover:shadow-md transition-all ${anySelected ? '' : 'cursor-grab active:cursor-grabbing'} ${selected ? 'border-emerald-400 ring-2 ring-emerald-200 bg-emerald-50/30' : isUrgent ? 'border-red-300 ring-1 ring-red-200' : 'border-gray-100 hover:border-gray-300'}`}
        >
            <div className={`absolute top-2 left-2 z-10 transition-opacity ${selected || anySelected ? 'opacity-100' : 'opacity-0 group-hover:opacity-100'}`}>
                <input
                    type="checkbox"
                    checked={selected}
                    onChange={(e) => { e.stopPropagation(); onToggleSelect(lead.id); }}
                    onClick={(e) => e.stopPropagation()}
                    className="w-4 h-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 shadow"
                />
            </div>
            <Link href={route('leads.show', lead.id)} className="block" onClick={(e) => { if (anySelected) { e.preventDefault(); onToggleSelect(lead.id); } }}>
                {/* Header: avatar + contacto + fuente */}
                <div className="flex items-center gap-2 mb-2 pl-5">
                    <div className={`w-7 h-7 rounded-full bg-gradient-to-br ${av.gradient} flex items-center justify-center text-white text-[10px] font-bold shrink-0 shadow-sm`}>
                        {av.initials}
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="text-xs font-semibold text-gray-800 truncate">{contactName}</p>
                    </div>
                    <span title={src.label} className="text-sm">{src.icon}</span>
                </div>

                {/* Título */}
                <p className="text-sm font-bold text-gray-900 group-hover:text-emerald-700 transition-colors line-clamp-2 leading-snug">
                    {lead.title}
                </p>

                {/* Valor */}
                {Number(lead.value) > 0 && (
                    <p className="text-base font-extrabold text-gray-900 tabular-nums mt-1">
                        {money(lead.value, lead.currency || currency)}
                    </p>
                )}

                {/* Tags */}
                {lead.tags?.length > 0 && (
                    <div className="flex flex-wrap gap-1 mt-2">
                        {lead.tags.slice(0, 3).map((t) => (
                            <span key={t.id} className="text-[9px] font-bold px-1.5 py-0.5 rounded-full text-white shadow-sm" style={{ backgroundColor: t.color }}>{t.name}</span>
                        ))}
                        {lead.tags.length > 3 && <span className="text-[9px] text-gray-400 self-center">+{lead.tags.length - 3}</span>}
                    </div>
                )}

                {/* Footer: SLA + tareas + responsable */}
                <div className="flex items-center justify-between mt-2 pt-2 border-t border-gray-50 gap-2">
                    <div className="flex items-center gap-1.5 min-w-0">
                        <ServiceWindowBadge window={lead.service_window} />
                        {isUrgent ? (
                            <span className="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full text-[9px] font-bold bg-red-100 text-red-700 border border-red-200 tabular-nums">
                                <span className="w-1 h-1 rounded-full bg-red-500 animate-pulse" />
                                {waitLabel(lead.waiting_minutes)}
                            </span>
                        ) : lead.pending_tasks_count === 0 ? (
                            <span title="Sin tarea pendiente" className="w-2 h-2 rounded-full bg-red-400 ring-2 ring-red-100" />
                        ) : (
                            <span title={`${lead.pending_tasks_count} tareas`} className="w-2 h-2 rounded-full bg-emerald-400 ring-2 ring-emerald-100" />
                        )}
                        {lead.responsible && (
                            <span className="text-[10px] text-gray-500 truncate">{lead.responsible.name.split(' ')[0]}</span>
                        )}
                    </div>
                    {phone && (
                        <a
                            href={`https://wa.me/${phone.replace(/[^\d]/g, '')}`}
                            target="_blank"
                            rel="noreferrer"
                            onClick={(e) => e.stopPropagation()}
                            title="Abrir en WhatsApp"
                            className="p-1 rounded-lg text-emerald-600 hover:bg-emerald-50 opacity-0 group-hover:opacity-100 transition-opacity shrink-0"
                        >
                            <svg className="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/></svg>
                        </a>
                    )}
                </div>
            </Link>
        </div>
    );
}

function LeadRow({ lead, currency, slaMinutes, selected, onToggleSelect, anySelected }) {
    const contactName = lead.contact?.name || lead.contact?.phone || 'Sin contacto';
    const av = avatarFor(contactName);
    const src = SOURCE_META[lead.source] ?? SOURCE_META.other;
    const isUrgent = lead.waiting_minutes >= slaMinutes && lead.last_message_direction === 'in';

    return (
        <div className={`flex items-center gap-3 px-4 py-3 hover:bg-gray-50 transition-colors border-l-4 ${selected ? 'border-emerald-500 bg-emerald-50/40' : isUrgent ? 'border-red-500 bg-red-50/40' : 'border-transparent'}`}>
            <input
                type="checkbox"
                checked={selected}
                onChange={() => onToggleSelect(lead.id)}
                className="w-4 h-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 shrink-0"
            />
            <Link href={route('leads.show', lead.id)} onClick={(e) => { if (anySelected) { e.preventDefault(); onToggleSelect(lead.id); } }} className="flex items-center gap-3 flex-1 min-w-0">
            <div className={`w-9 h-9 rounded-full bg-gradient-to-br ${av.gradient} flex items-center justify-center text-white text-xs font-bold shrink-0 shadow-sm`}>
                {av.initials}
            </div>
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2 mb-0.5">
                    <p className="font-semibold text-sm text-gray-900 truncate">{contactName}</p>
                    <span title={src.label} className="text-sm shrink-0">{src.icon}</span>
                </div>
                <p className="text-xs text-gray-600 truncate">
                    {lead.title}
                    {lead.stage && <span> · <span className="inline-flex items-center gap-1"><span className="w-1.5 h-1.5 rounded-full" style={{ backgroundColor: lead.stage.color }} /><span style={{ color: lead.stage.color }} className="font-semibold">{lead.stage.name}</span></span></span>}
                </p>
                {lead.tags?.length > 0 && (
                    <div className="flex gap-1 mt-1 flex-wrap">
                        {lead.tags.slice(0, 4).map((t) => (
                            <span key={t.id} className="text-[9px] font-bold px-1.5 py-0.5 rounded-full text-white" style={{ backgroundColor: t.color }}>{t.name}</span>
                        ))}
                    </div>
                )}
            </div>
            {isUrgent && (
                <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-red-100 text-red-700 border border-red-200 tabular-nums shrink-0">
                    <span className="w-1.5 h-1.5 rounded-full bg-red-500 animate-pulse" />
                    {waitLabel(lead.waiting_minutes)}
                </span>
            )}
            {Number(lead.value) > 0 && (
                <span className="text-sm font-bold text-gray-900 tabular-nums shrink-0 hidden sm:inline">
                    {money(lead.value, lead.currency || currency)}
                </span>
            )}
            <div className="hidden sm:flex items-center gap-1.5 text-[11px] text-gray-500 shrink-0 w-24 truncate">
                {lead.responsible ? <>👤 {lead.responsible.name.split(' ')[0]}</> : <span className="text-sky-600 font-semibold">Sin asignar</span>}
            </div>
            </Link>
        </div>
    );
}

function BulkBar({ count, onClear, pipeline, members, allTags, isAdmin }) {
    const [mode, setMode] = useState(null); // 'move' | 'assign' | 'tag' | null

    const perform = (action, payload) => {
        router.post(route('leads.bulk'), { ids: Array.from(count.ids), action, ...payload }, {
            preserveScroll: true,
            onSuccess: () => { onClear(); setMode(null); },
        });
    };

    return (
        <div className="fixed inset-x-0 bottom-4 z-40 pointer-events-none flex justify-center">
            <div className="pointer-events-auto bg-slate-900 text-white rounded-2xl shadow-2xl border border-slate-700 px-4 py-3 flex flex-wrap items-center gap-2 max-w-3xl mx-4">
                <div className="flex items-center gap-2">
                    <span className="w-7 h-7 rounded-lg bg-emerald-500 flex items-center justify-center text-xs font-bold">{count.n}</span>
                    <span className="text-sm font-semibold">seleccionados</span>
                </div>
                <div className="h-6 w-px bg-slate-700 mx-1" />
                {mode === null ? (
                    <>
                        <button onClick={() => setMode('move')} className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-800 hover:bg-slate-700 transition-colors">
                            ➡ Mover a etapa
                        </button>
                        {isAdmin && (
                            <button onClick={() => setMode('assign')} className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-800 hover:bg-slate-700 transition-colors">
                                👤 Reasignar
                            </button>
                        )}
                        <button onClick={() => setMode('tag')} className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-800 hover:bg-slate-700 transition-colors">
                            🏷 Etiquetar
                        </button>
                        {isAdmin && (
                            <button
                                onClick={() => { if (confirm(`¿Eliminar ${count.n} leads y todo su historial?`)) perform('delete', {}); }}
                                className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-semibold bg-red-700 hover:bg-red-600 transition-colors"
                            >
                                🗑 Eliminar
                            </button>
                        )}
                    </>
                ) : mode === 'move' ? (
                    <>
                        <select
                            onChange={(e) => e.target.value && perform('move', { stage_id: e.target.value })}
                            defaultValue=""
                            className="px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-800 border border-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                        >
                            <option value="" disabled>Elegir etapa…</option>
                            {pipeline?.stages?.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </select>
                        <button onClick={() => setMode(null)} className="text-xs text-slate-400 hover:text-white px-2">← Volver</button>
                    </>
                ) : mode === 'assign' ? (
                    <>
                        <select
                            onChange={(e) => e.target.value && perform('assign', { responsible_user_id: e.target.value })}
                            defaultValue=""
                            className="px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-800 border border-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                        >
                            <option value="" disabled>Elegir responsable…</option>
                            {members.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                        </select>
                        <button onClick={() => setMode(null)} className="text-xs text-slate-400 hover:text-white px-2">← Volver</button>
                    </>
                ) : mode === 'tag' ? (
                    <>
                        <select
                            id="bulk-tag-select"
                            defaultValue=""
                            className="px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-800 border border-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                        >
                            <option value="" disabled>Elegir etiqueta…</option>
                            {allTags.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                        </select>
                        <button
                            onClick={() => {
                                const v = document.getElementById('bulk-tag-select').value;
                                if (v) perform('tag', { tag_id: v, tag_mode: 'add' });
                            }}
                            className="px-3 py-1.5 rounded-lg text-xs font-bold bg-emerald-600 hover:bg-emerald-500"
                        >
                            + Agregar
                        </button>
                        <button
                            onClick={() => {
                                const v = document.getElementById('bulk-tag-select').value;
                                if (v) perform('tag', { tag_id: v, tag_mode: 'remove' });
                            }}
                            className="px-3 py-1.5 rounded-lg text-xs font-bold bg-slate-700 hover:bg-slate-600"
                        >
                            − Quitar
                        </button>
                        <button onClick={() => setMode(null)} className="text-xs text-slate-400 hover:text-white px-2">← Volver</button>
                    </>
                ) : null}
                <div className="h-6 w-px bg-slate-700 mx-1" />
                <button onClick={onClear} className="text-xs text-slate-400 hover:text-white px-2">Cancelar</button>
            </div>
        </div>
    );
}

function NewLeadModal({ open, onClose, pipeline, contacts, members }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        pipeline_id: pipeline?.id ?? '',
        stage_id: '',
        title: '',
        value: '',
        contact_id: '',
        responsible_user_id: '',
    });

    const close = () => { reset(); onClose(); };
    const submit = (e) => { e.preventDefault(); post(route('leads.store'), { onSuccess: close }); };
    const inputClass = 'w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 focus:bg-white transition-all';

    return (
        <Modal show={open} onClose={close}>
            <form onSubmit={submit}>
                <div className="px-6 pt-6 pb-4 border-b border-gray-100 flex items-center gap-3">
                    <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center text-white shadow-lg shadow-emerald-500/20">
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    </div>
                    <div>
                        <h2 className="text-base font-bold text-gray-900">Nuevo lead</h2>
                        <p className="text-xs text-gray-400 mt-0.5">Entra en la primera etapa del pipeline</p>
                    </div>
                </div>
                <div className="px-6 py-5 space-y-4">
                    <div>
                        <label className="block text-sm font-semibold text-gray-700 mb-1.5">Título <span className="text-red-500">*</span></label>
                        <input value={data.title} onChange={(e) => setData('title', e.target.value)} required className={inputClass} placeholder="ej. Cotización sistema web" />
                        {errors.title && <p className="mt-1 text-xs text-red-500 font-medium">{errors.title}</p>}
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-semibold text-gray-700 mb-1.5">Valor</label>
                            <input type="number" step="0.01" min="0" value={data.value} onChange={(e) => setData('value', e.target.value)} className={inputClass} />
                        </div>
                        <div>
                            <label className="block text-sm font-semibold text-gray-700 mb-1.5">Etapa</label>
                            <select value={data.stage_id} onChange={(e) => setData('stage_id', e.target.value)} className={inputClass}>
                                <option value="">Primera etapa</option>
                                {pipeline?.stages?.filter((s) => s.stage_type === 'open').map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                            </select>
                        </div>
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-semibold text-gray-700 mb-1.5">Contacto</label>
                            <select value={data.contact_id} onChange={(e) => setData('contact_id', e.target.value)} className={inputClass}>
                                <option value="">— Sin contacto —</option>
                                {contacts.map((c) => <option key={c.id} value={c.id}>{c.name || c.phone}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="block text-sm font-semibold text-gray-700 mb-1.5">Responsable</label>
                            <select value={data.responsible_user_id} onChange={(e) => setData('responsible_user_id', e.target.value)} className={inputClass}>
                                <option value="">Yo</option>
                                {members.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                            </select>
                        </div>
                    </div>
                </div>
                <div className="px-6 py-4 bg-gray-50/80 border-t border-gray-100 rounded-b-2xl flex justify-end gap-3">
                    <button type="button" onClick={close} className="px-4 py-2.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 shadow-sm">Cancelar</button>
                    <button type="submit" disabled={processing} className="px-5 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:opacity-90 disabled:opacity-50 shadow-lg shadow-emerald-500/20">Crear lead</button>
                </div>
            </form>
        </Modal>
    );
}

export default function Index({ pipelines, pipeline, leads, members, contacts, allTags = [], filters, segments = [], currency, slaMinutes = 30, isAdmin = false }) {
    const { flash, auth } = usePage().props;
    // `isAdmin` llega como prop del controlador: la fuente de verdad del rol
    // es el servidor, que es el mismo que decide qué leads devuelve.
    const [showNew, setShowNew] = useState(false);
    const [dragOver, setDragOver] = useState(null);
    const [view, setView] = useState(() => localStorage.getItem('leads.view') || 'kanban');
    const [query, setQuery] = useState(filters?.q || '');
    const [selectedIds, setSelectedIds] = useState(() => new Set());

    const toggleSelect = (id) => setSelectedIds((prev) => {
        const next = new Set(prev);
        if (next.has(id)) next.delete(id); else next.add(id);
        return next;
    });
    const clearSelection = () => setSelectedIds(new Set());
    const anySelected = selectedIds.size > 0;

    useEffect(() => { localStorage.setItem('leads.view', view); }, [view]);

    // Debounce búsqueda
    useEffect(() => {
        if (query === (filters?.q || '')) return;
        const t = setTimeout(() => {
            router.get(route('leads.index'), { ...filters, pipeline: pipeline?.id, q: query }, { preserveState: true, preserveScroll: true, replace: true });
        }, 350);
        return () => clearTimeout(t);
    }, [query]);

    const applyFilter = (patch) => {
        router.get(route('leads.index'), { ...filters, pipeline: pipeline?.id, ...patch }, { preserveState: true, preserveScroll: true, replace: true });
    };

    const clearFilters = () => {
        setQuery('');
        router.get(route('leads.index'), { pipeline: pipeline?.id }, { preserveState: false });
    };

    const openLeads = leads.filter((l) => l.status === 'open');
    const totalOpen = openLeads.reduce((sum, l) => sum + Number(l.value || 0), 0);
    const urgentCount = openLeads.filter((l) => l.waiting_minutes >= slaMinutes && l.last_message_direction === 'in').length;
    const hasActiveFilters = filters?.responsible || filters?.tag || filters?.source || filters?.no_task || (filters?.q && filters.q.length > 0);

    const dropOnStage = (e, stageId) => {
        e.preventDefault();
        setDragOver(null);
        const leadId = e.dataTransfer.getData('text/lead-id');
        const lead = leads.find((l) => l.id === leadId);
        if (lead && lead.stage_id !== stageId) {
            const previousStageId = lead.stage_id;
            const previousStageName = pipeline?.stages?.find((s) => s.id === previousStageId)?.name;
            const newStageName = pipeline?.stages?.find((s) => s.id === stageId)?.name;
            router.patch(route('leads.move', leadId), { stage_id: stageId }, {
                preserveScroll: true,
                onSuccess: () => showUndo({
                    message: `«${lead.title}» → ${newStageName}`,
                    onUndo: () => router.patch(route('leads.move', leadId), { stage_id: previousStageId }, { preserveScroll: true }),
                }),
            });
        }
    };

    return (
        <AuthenticatedLayout>
            <Head title="Leads" />

            <div className="mx-auto max-w-full px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-5">
                {/* Header */}
                <div className="flex flex-col lg:flex-row lg:items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Leads</h1>
                        <p className="text-sm text-gray-500 mt-1 flex flex-wrap gap-x-3 gap-y-1 items-center">
                            <span><strong className="text-gray-800">{openLeads.length}</strong> abiertos</span>
                            <span className="text-gray-300">·</span>
                            <span><strong className="text-gray-800">{money(totalOpen, currency)}</strong> en juego</span>
                            {urgentCount > 0 && (
                                <>
                                    <span className="text-gray-300">·</span>
                                    <Link href={route('inbox', { filter: 'unresponded' })} className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-bold bg-red-100 text-red-700 border border-red-200 hover:bg-red-200 transition-colors">
                                        <span className="w-1.5 h-1.5 rounded-full bg-red-500 animate-pulse" />
                                        {urgentCount} sin respuesta
                                    </Link>
                                </>
                            )}
                        </p>
                    </div>
                    <div className="flex items-center gap-2 flex-wrap">
                        {/* Toggle vista */}
                        <div className="inline-flex bg-white border border-gray-200 rounded-xl shadow-sm p-0.5">
                            <button
                                onClick={() => setView('kanban')}
                                className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-all inline-flex items-center gap-1 ${view === 'kanban' ? 'bg-gradient-to-r from-[#045474] to-[#1c486c] text-white shadow' : 'text-gray-600'}`}
                            >
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" /></svg>
                                Kanban
                            </button>
                            <button
                                onClick={() => setView('list')}
                                className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-all inline-flex items-center gap-1 ${view === 'list' ? 'bg-gradient-to-r from-[#045474] to-[#1c486c] text-white shadow' : 'text-gray-600'}`}
                            >
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 12h.007v.008H3.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 17.25h.007v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" /></svg>
                                Lista
                            </button>
                        </div>
                        {pipelines.length > 1 && (
                            <select
                                value={pipeline?.id ?? ''}
                                onChange={(e) => router.get(route('leads.index'), { pipeline: e.target.value })}
                                className="px-3 py-2 border border-gray-200 rounded-xl text-sm font-medium bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/30 shadow-sm"
                            >
                                {pipelines.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                            </select>
                        )}
                        <a
                            href={route('leads.export', { pipeline: pipeline?.id, ...filters })}
                            className="px-3 py-2 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 shadow-sm inline-flex items-center gap-1.5"
                            title="Exportar leads del pipeline actual (respetando filtros) a CSV"
                        >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" /></svg>
                            <span className="hidden sm:inline">Exportar</span>
                        </a>
                        {pipeline && (
                            <Link href={route('pipelines.automations', pipeline.id)} className="px-3 py-2 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 shadow-sm flex items-center gap-1.5" title="Digital Pipeline">
                                ⚡ <span className="hidden sm:inline">Automatizar</span>
                            </Link>
                        )}
                        <button onClick={() => setShowNew(true)} className="px-4 py-2 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:opacity-90 shadow-lg shadow-emerald-500/20 flex items-center gap-1.5">
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            Nuevo
                        </button>
                    </div>
                </div>

                {/* Segments guardados */}
                {segments.length > 0 && (
                    <div className="flex flex-wrap gap-1.5 items-center">
                        <span className="text-[10px] font-bold uppercase tracking-wider text-gray-400 pr-1">Mis listas:</span>
                        {segments.map((s) => {
                            const isOwn = s.user_id === auth?.user?.id;
                            return (
                                <div key={s.id} className="inline-flex items-center rounded-lg bg-white border border-gray-200 shadow-sm overflow-hidden group">
                                    <button
                                        onClick={() => router.get(route('leads.index'), { pipeline: pipeline?.id, ...s.filters }, { preserveState: false })}
                                        className="px-2.5 py-1 text-xs font-semibold text-gray-700 hover:bg-emerald-50 hover:text-emerald-700"
                                    >
                                        📋 {s.name}
                                        {s.is_shared && <span className="ml-1 text-[9px] text-gray-400">·compartida</span>}
                                    </button>
                                    {isOwn && (
                                        <button
                                            onClick={() => { if (confirm(`¿Borrar la lista "${s.name}"?`)) router.delete(route('segments.destroy', s.id), { preserveScroll: true }); }}
                                            className="px-1.5 py-1 text-gray-300 hover:text-red-600 hover:bg-red-50 border-l border-gray-100"
                                            title="Borrar lista"
                                        >
                                            <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                        </button>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                )}

                {/* Barra de filtros */}
                <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-3 flex flex-wrap gap-2 items-center">
                    <div className="relative flex-1 min-w-[200px] max-w-xs">
                        <svg className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
                        <input
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="Buscar por nombre, título, teléfono…"
                            className="w-full pl-9 pr-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:bg-white"
                        />
                    </div>
                    {/* Elegir asesor es del admin: el agente solo ve los suyos,
                        asi que el desplegable no tendria nada que filtrar. */}
                    {isAdmin ? (
                        <select value={filters?.responsible || ''} onChange={(e) => applyFilter({ responsible: e.target.value || null })} className="px-3 py-2 border border-gray-200 rounded-lg text-sm bg-gray-50 focus:bg-white focus:ring-2 focus:ring-emerald-500/30">
                            <option value="">Todos los responsables</option>
                            <option value="none">Sin asignar</option>
                            {members.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                        </select>
                    ) : (
                        <span className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200">
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                            Tus leads
                        </span>
                    )}
                    <select value={filters?.source || ''} onChange={(e) => applyFilter({ source: e.target.value || null })} className="px-3 py-2 border border-gray-200 rounded-lg text-sm bg-gray-50 focus:bg-white focus:ring-2 focus:ring-emerald-500/30">
                        <option value="">Todas las fuentes</option>
                        {Object.entries(SOURCE_META).map(([k, v]) => <option key={k} value={k}>{v.icon} {v.label}</option>)}
                    </select>
                    <select value={filters?.tag || ''} onChange={(e) => applyFilter({ tag: e.target.value || null })} className="px-3 py-2 border border-gray-200 rounded-lg text-sm bg-gray-50 focus:bg-white focus:ring-2 focus:ring-emerald-500/30">
                        <option value="">Todas las etiquetas</option>
                        {allTags.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                    </select>
                    <button
                        onClick={() => applyFilter({ no_task: filters?.no_task ? null : 1 })}
                        className={`px-3 py-2 rounded-lg text-xs font-bold transition-all inline-flex items-center gap-1.5 ${filters?.no_task ? 'bg-red-600 text-white shadow-md' : 'bg-gray-50 text-gray-700 border border-gray-200 hover:bg-gray-100'}`}
                        title="Regla Kommo: nunca dejarlo así"
                    >
                        <span className="w-1.5 h-1.5 rounded-full bg-current" />
                        Sin tarea
                    </button>
                    {hasActiveFilters && (
                        <>
                            <button
                                onClick={() => {
                                    const name = prompt('Nombre de la lista:', 'Mi filtro');
                                    if (!name?.trim()) return;
                                    const share = confirm('¿Compartir con todo el equipo? (Cancelar = solo para vos)');
                                    router.post(route('segments.store'), {
                                        name: name.trim(),
                                        filters: { responsible: filters?.responsible, tag: filters?.tag, source: filters?.source, no_task: filters?.no_task ? 1 : 0, q: filters?.q },
                                        is_shared: share,
                                    }, { preserveScroll: true });
                                }}
                                className="text-xs font-semibold text-emerald-600 hover:text-emerald-700 px-2 inline-flex items-center gap-1"
                                title="Guardar estos filtros como lista"
                            >
                                💾 Guardar
                            </button>
                            <button onClick={clearFilters} className="text-xs font-semibold text-gray-500 hover:text-gray-700 px-2 underline">
                                Limpiar
                            </button>
                        </>
                    )}
                </div>

                {flash?.success && (
                    <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 shadow-sm">{flash.success}</div>
                )}

                {!pipeline ? (
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-16 text-center text-sm text-gray-400">No hay pipeline configurado.</div>
                ) : view === 'kanban' ? (
                    <div className="flex gap-4 overflow-x-auto pb-4 -mx-4 px-4 sm:mx-0 sm:px-0">
                        {pipeline.stages.map((stage) => {
                            const stageLeads = leads.filter((l) => l.stage_id === stage.id);
                            const stageTotal = stageLeads.reduce((sum, l) => sum + Number(l.value || 0), 0);
                            const stageUrgent = stageLeads.filter((l) => l.waiting_minutes >= slaMinutes && l.last_message_direction === 'in').length;
                            const isTerminal = stage.stage_type !== 'open';

                            return (
                                <div
                                    key={stage.id}
                                    onDragOver={(e) => { e.preventDefault(); setDragOver(stage.id); }}
                                    onDragLeave={() => setDragOver(null)}
                                    onDrop={(e) => dropOnStage(e, stage.id)}
                                    className={`flex w-72 shrink-0 flex-col rounded-2xl border-2 transition-all ${isTerminal ? 'bg-gray-50/70' : 'bg-gray-50'} ${dragOver === stage.id ? 'border-emerald-400 bg-emerald-50/50 scale-[1.02]' : 'border-transparent'}`}
                                >
                                    <div className="rounded-t-2xl px-4 py-3 text-white relative" style={{ background: `linear-gradient(135deg, ${stage.color} 0%, ${stage.color}dd 100%)` }}>
                                        <div className="flex items-center justify-between">
                                            <span className="font-bold text-sm flex items-center gap-1.5">
                                                {stage.stage_type === 'won' && '🏆'}
                                                {stage.stage_type === 'lost' && '✕'}
                                                {stage.name}
                                            </span>
                                            <div className="flex items-center gap-1">
                                                {stageUrgent > 0 && (
                                                    <span title={`${stageUrgent} sin respuesta`} className="text-[10px] font-bold bg-red-500 text-white rounded-full px-1.5 py-0.5 border border-white/40">
                                                        🚨 {stageUrgent}
                                                    </span>
                                                )}
                                                <span className="text-[10px] font-bold bg-white/25 rounded-full px-2 py-0.5">{stageLeads.length}</span>
                                            </div>
                                        </div>
                                        <p className="text-xs font-medium text-white/85 mt-1 tabular-nums">{money(stageTotal, currency)}</p>
                                    </div>
                                    <div className="flex flex-1 flex-col gap-2 p-2.5 min-h-[180px]">
                                        {stageLeads.map((lead) => <LeadCard key={lead.id} lead={lead} currency={currency} slaMinutes={slaMinutes} selected={selectedIds.has(lead.id)} onToggleSelect={toggleSelect} anySelected={anySelected} />)}
                                        {stageLeads.length === 0 && (
                                            <p className="py-8 text-center text-xs text-gray-400 font-medium">{isTerminal ? '—' : 'Arrastra leads aquí'}</p>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                ) : (
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        {leads.length === 0 ? (
                            <div className="p-14 text-center text-sm text-gray-400">Sin leads con estos filtros</div>
                        ) : (
                            <ul className="divide-y divide-gray-50">
                                {leads.map((l) => <li key={l.id}><LeadRow lead={l} currency={currency} slaMinutes={slaMinutes} selected={selectedIds.has(l.id)} onToggleSelect={toggleSelect} anySelected={anySelected} /></li>)}
                            </ul>
                        )}
                    </div>
                )}
            </div>

            <NewLeadModal open={showNew} onClose={() => setShowNew(false)} pipeline={pipeline} contacts={contacts} members={members} />

            {anySelected && (
                <BulkBar
                    count={{ n: selectedIds.size, ids: selectedIds }}
                    onClear={clearSelection}
                    pipeline={pipeline}
                    members={members}
                    allTags={allTags}
                    isAdmin={isAdmin}
                />
            )}
        </AuthenticatedLayout>
    );
}
