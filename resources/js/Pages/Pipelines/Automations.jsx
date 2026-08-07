import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Digital Pipeline. La página se lee como el recorrido del lead: una
 * etapa detrás de otra, y dentro de cada una las acciones que se
 * disparan al entrar.
 */

const ACTION_META = {
    send_whatsapp: {
        label: 'Enviar WhatsApp',
        help: 'Le llega un mensaje al lead por WhatsApp.',
        icon: '💬',
        gradient: 'from-emerald-500 to-teal-600',
        chip: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    },
    create_task: {
        label: 'Crear tarea',
        help: 'Deja un pendiente con vencimiento para el equipo.',
        icon: '📋',
        gradient: 'from-blue-500 to-indigo-600',
        chip: 'bg-blue-50 text-blue-700 ring-blue-200',
    },
    add_note: {
        label: 'Dejar nota',
        help: 'Escribe una nota en el lead. No le llega nada al cliente.',
        icon: '📝',
        gradient: 'from-amber-500 to-orange-600',
        chip: 'bg-amber-50 text-amber-700 ring-amber-200',
    },
};

const TASK_TYPES = {
    call: '📞 Llamar',
    meet: '🤝 Reunión',
    follow_up: '🔔 Seguimiento',
    email: '✉️ Email',
    other: 'Otra',
};

const STAGE_TYPE_LABEL = {
    open: 'En curso',
    won: 'Ganado',
    lost: 'Perdido',
};

const inputClass = 'w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 transition-all';
const smallInput = 'px-2.5 py-1.5 border border-gray-200 rounded-lg bg-white text-xs focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500';

function humanHours(hours) {
    const h = Math.max(1, Number(hours) || 24);
    if (h < 24) return `${h} ${h === 1 ? 'hora' : 'horas'}`;
    if (h % 24 === 0) {
        const days = h / 24;
        return days % 30 === 0 ? `${days / 30} ${days / 30 === 1 ? 'mes' : 'meses'}` : `${days} ${days === 1 ? 'día' : 'días'}`;
    }
    return `${Math.floor(h / 24)} d ${h % 24} h`;
}

/** Qué le falta a una acción para funcionar de verdad. */
function actionProblem(automation, whatsappEnabled) {
    const text = (automation.config?.text ?? '').trim();
    if (!text) return 'No tiene texto: no haría nada';
    if (automation.action_type === 'send_whatsapp' && !whatsappEnabled) {
        return 'La integración con WhatsApp está inactiva: el mensaje no saldría';
    }
    return null;
}

/* ---------------------------------------------------------------- formulario */

function ActionForm({ pipeline, stageId, automation, members, whatsappEnabled, onDone }) {
    const isEdit = !!automation;

    const { data, setData, post, patch, processing, errors, reset } = useForm({
        stage_id: stageId,
        action_type: automation?.action_type ?? 'create_task',
        config: {
            text: automation?.config?.text ?? '',
            task_type: automation?.config?.task_type ?? 'follow_up',
            due_in_hours: automation?.config?.due_in_hours ?? 24,
            assigned_to: automation?.config?.assigned_to ?? '',
        },
    });

    const setConfig = (patchObj) => setData('config', { ...data.config, ...patchObj });

    const submit = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { reset(); onDone(); } };
        isEdit
            ? patch(route('automations.update', automation.id), options)
            : post(route('pipelines.automations.store', pipeline.id), options);
    };

    const placeholder = {
        send_whatsapp: 'Hola {name}, ya avanzamos con tu solicitud…',
        create_task: 'Llamar a {name} para dar seguimiento',
        add_note: 'El lead llegó a esta etapa por…',
    }[data.action_type];

    return (
        <form onSubmit={submit} className="rounded-xl border-2 border-dashed border-emerald-200 bg-emerald-50/40 p-4 space-y-3">
            <div className="grid gap-2 sm:grid-cols-3">
                {Object.entries(ACTION_META).map(([type, meta]) => {
                    const blocked = type === 'send_whatsapp' && !whatsappEnabled;
                    return (
                        <button
                            key={type}
                            type="button"
                            disabled={blocked}
                            onClick={() => setData('action_type', type)}
                            title={blocked ? 'Activa la integración con WhatsApp primero' : meta.help}
                            className={`px-3 py-2 rounded-xl text-left transition-all disabled:opacity-40 disabled:cursor-not-allowed border ${
                                data.action_type === type
                                    ? 'border-emerald-500 bg-white ring-2 ring-emerald-500/20'
                                    : 'border-gray-200 bg-white hover:border-gray-300'
                            }`}
                        >
                            <p className="text-xs font-bold text-gray-900">{meta.icon} {meta.label}</p>
                            <p className="text-[10px] text-gray-500 leading-snug mt-0.5">{meta.help}</p>
                        </button>
                    );
                })}
            </div>

            <div>
                <textarea
                    rows={2}
                    value={data.config.text}
                    onChange={(e) => setConfig({ text: e.target.value })}
                    required
                    placeholder={placeholder}
                    className={inputClass}
                />
                <div className="flex flex-wrap gap-1.5 mt-1.5">
                    {['{name}', '{title}', '{value}', '{stage}'].map((v) => (
                        <button
                            key={v}
                            type="button"
                            onClick={() => setConfig({ text: `${data.config.text}${v}` })}
                            className="px-2 py-0.5 rounded-md bg-white text-gray-600 text-[11px] font-mono ring-1 ring-gray-200 hover:bg-emerald-100 hover:text-emerald-700 transition-colors"
                            title={`Insertar ${v}`}
                        >
                            {v}
                        </button>
                    ))}
                </div>
                {errors['config.text'] && <p className="text-xs text-red-500 font-medium mt-1">{errors['config.text']}</p>}
            </div>

            {data.action_type === 'create_task' && (
                <div className="flex flex-wrap items-center gap-2 text-xs text-gray-600">
                    <select value={data.config.task_type} onChange={(e) => setConfig({ task_type: e.target.value })} className={smallInput}>
                        {Object.entries(TASK_TYPES).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                    </select>
                    <label className="flex items-center gap-1.5">
                        Vence en
                        <input
                            type="number"
                            min="1"
                            max="720"
                            value={data.config.due_in_hours}
                            onChange={(e) => setConfig({ due_in_hours: Number(e.target.value) })}
                            className={`w-16 text-center ${smallInput}`}
                        />
                        h ({humanHours(data.config.due_in_hours)})
                    </label>
                    <select value={data.config.assigned_to} onChange={(e) => setConfig({ assigned_to: e.target.value })} className={smallInput}>
                        <option value="">Responsable del lead</option>
                        {members.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                    </select>
                </div>
            )}

            <div className="flex justify-end gap-2">
                <button type="button" onClick={onDone} className="px-3 py-2 text-xs font-semibold text-gray-600 hover:text-gray-800">Cancelar</button>
                <button type="submit" disabled={processing} className="px-4 py-2 text-xs font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 disabled:opacity-50 transition-all shadow-lg shadow-emerald-500/20">
                    {isEdit ? 'Guardar cambios' : 'Crear acción'}
                </button>
            </div>
        </form>
    );
}

/* ---------------------------------------------------------------- plantillas */

function RecipePicker({ pipeline, stage, recipes, whatsappEnabled, onDone }) {
    const applicable = recipes.filter((r) => r.stage_types.includes(stage.stage_type));
    const [busy, setBusy] = useState(null);

    const apply = (slug) => {
        setBusy(slug);
        router.post(
            route('pipelines.automations.recipe', pipeline.id),
            { stage_id: stage.id, recipe: slug },
            { preserveScroll: true, onFinish: () => { setBusy(null); onDone(); } },
        );
    };

    if (applicable.length === 0) {
        return (
            <div className="rounded-xl border-2 border-dashed border-gray-200 bg-white p-4 text-center">
                <p className="text-xs text-gray-400">No hay plantillas para etapas de tipo «{STAGE_TYPE_LABEL[stage.stage_type]}».</p>
                <button type="button" onClick={onDone} className="text-xs font-semibold text-gray-600 hover:text-gray-800 mt-2">Cerrar</button>
            </div>
        );
    }

    return (
        <div className="rounded-xl border-2 border-dashed border-sky-200 bg-sky-50/40 p-4 space-y-2">
            <div className="flex items-center justify-between">
                <p className="text-[10px] font-bold uppercase tracking-wider text-sky-700">Plantillas para esta etapa</p>
                <button type="button" onClick={onDone} className="text-xs text-gray-400 hover:text-gray-700">✕</button>
            </div>
            {applicable.map((r) => {
                const blocked = r.needs_whatsapp && !whatsappEnabled;
                return (
                    <button
                        key={r.slug}
                        type="button"
                        disabled={blocked || busy}
                        onClick={() => apply(r.slug)}
                        title={blocked ? 'Requiere la integración con WhatsApp activa' : ''}
                        className="w-full text-left rounded-xl border border-gray-200 bg-white p-3 hover:border-sky-400 hover:bg-sky-50/60 transition-all disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                        <div className="flex items-center justify-between gap-2">
                            <p className="text-xs font-bold text-gray-900">{r.title}</p>
                            <span className="flex gap-1 flex-shrink-0">
                                {r.actions.map((a, i) => <span key={i} className="text-xs">{ACTION_META[a]?.icon}</span>)}
                            </span>
                        </div>
                        <p className="text-[11px] text-gray-500 leading-snug mt-0.5">{r.summary}</p>
                        <p className="text-[10px] text-gray-400 italic mt-1">{r.why}</p>
                    </button>
                );
            })}
        </div>
    );
}

/* ---------------------------------------------------------------- panel de prueba */

const SIM_STATUS = {
    ok: { label: 'Se ejecuta', cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    paused: { label: 'Pausada', cls: 'bg-gray-100 text-gray-500 ring-gray-200' },
    error: { label: 'No haría nada', cls: 'bg-red-50 text-red-700 ring-red-200' },
};

function PreviewPanel({ pipeline, stage, sampleLeads, onClose }) {
    const [leadId, setLeadId] = useState(sampleLeads[0]?.id ?? '');
    const [result, setResult] = useState(null);
    const [running, setRunning] = useState(false);
    const [error, setError] = useState(null);

    const run = async (id) => {
        setRunning(true);
        setError(null);
        try {
            const { data } = await window.axios.post(route('pipelines.automations.simulate', pipeline.id), {
                stage_id: stage.id,
                lead_id: id || null,
            });
            setResult(data);
        } catch (e) {
            setError(e.response?.data?.message ?? 'No se pudo simular.');
        } finally {
            setRunning(false);
        }
    };

    return (
        <div className="rounded-xl border-2 border-dashed border-violet-200 bg-violet-50/40 p-4 space-y-3">
            <div className="flex items-center justify-between">
                <div>
                    <p className="text-[10px] font-bold uppercase tracking-wider text-violet-700">Vista previa</p>
                    <p className="text-[11px] text-gray-500">Qué pasaría si un lead entrara ahora. No se ejecuta nada.</p>
                </div>
                <button type="button" onClick={onClose} className="text-xs text-gray-400 hover:text-gray-700">✕</button>
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <select value={leadId} onChange={(e) => { setLeadId(e.target.value); setResult(null); }} className={`flex-1 min-w-[12rem] ${smallInput}`}>
                    <option value="">Sin lead — no reemplaza {'{name}'}</option>
                    {sampleLeads.map((l) => (
                        <option key={l.id} value={l.id}>{l.title}{l.contact ? ` · ${l.contact}` : ''}</option>
                    ))}
                </select>
                <button
                    type="button"
                    onClick={() => run(leadId)}
                    disabled={running}
                    className="px-3 py-1.5 rounded-lg bg-violet-600 text-white text-xs font-semibold hover:bg-violet-500 disabled:opacity-50 transition-all"
                >
                    {running ? 'Simulando…' : 'Simular entrada'}
                </button>
            </div>

            {error && <p className="text-xs text-red-600 font-medium">{error}</p>}

            {result && result.steps.length === 0 && (
                <p className="text-xs text-gray-500">Esta etapa no tiene acciones: al entrar un lead no pasaría nada.</p>
            )}

            {result?.steps.map((step) => {
                const meta = ACTION_META[step.action_type] ?? ACTION_META.add_note;
                const status = SIM_STATUS[step.status] ?? SIM_STATUS.ok;
                return (
                    <div key={step.id} className={`rounded-xl border border-gray-100 bg-white p-3 ${step.status === 'paused' ? 'opacity-60' : ''}`}>
                        <div className="flex items-center justify-between gap-2">
                            <span className="text-[11px] font-bold text-gray-800">{meta.icon} {meta.label}</span>
                            <span className={`px-1.5 py-0.5 rounded-full text-[9px] font-bold ring-1 ${status.cls}`}>{status.label}</span>
                        </div>
                        {step.detail && (
                            <p className="text-[11px] text-gray-700 mt-1.5 whitespace-pre-wrap break-words bg-gray-50 rounded-lg px-2.5 py-1.5">{step.detail}</p>
                        )}
                        {step.meta && (
                            <p className="text-[10px] text-gray-500 mt-1">
                                {TASK_TYPES[step.meta.task_type] ?? step.meta.task_type} · vence {new Date(step.meta.due_at).toLocaleString()}
                                {step.meta.assignee ? ` · para ${step.meta.assignee}` : ' · sin responsable asignado'}
                            </p>
                        )}
                        {step.note && <p className="text-[10px] text-gray-400 mt-1 leading-snug">{step.note}</p>}
                    </div>
                );
            })}
        </div>
    );
}

/* ---------------------------------------------------------------- etapa */

function StageCard({ pipeline, stage, members, recipes, sampleLeads, whatsappEnabled, isLast }) {
    const [panel, setPanel] = useState(null); // 'new' | 'recipes' | 'preview'
    const [editing, setEditing] = useState(null); // automation id

    const problems = stage.automations.filter((a) => a.is_active && actionProblem(a, whatsappEnabled)).length;

    return (
        <div className="flex flex-col">
            <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div className="px-5 py-3.5 flex flex-wrap items-center justify-between gap-2 text-white" style={{ background: `linear-gradient(135deg, ${stage.color} 0%, ${stage.color}dd 100%)` }}>
                    <div className="min-w-0">
                        <span className="font-bold text-sm flex items-center gap-1.5">
                            {stage.stage_type === 'won' && '🏆'}
                            {stage.stage_type === 'lost' && '✕'}
                            {stage.name}
                        </span>
                        <span className="text-[11px] text-white/80">
                            {stage.leads_count} {stage.leads_count === 1 ? 'lead ahora' : 'leads ahora'}
                        </span>
                    </div>
                    <span className="text-[10px] font-bold bg-white/25 rounded-full px-2 py-0.5 flex-shrink-0">
                        {stage.automations.length === 0
                            ? 'sin acciones'
                            : `${stage.automations.length} ${stage.automations.length === 1 ? 'acción' : 'acciones'}`}
                    </span>
                </div>

                <div className="p-4 space-y-2">
                    {stage.automations.length === 0 && !panel && (
                        <p className="text-xs text-gray-400 text-center py-2">
                            Al entrar un lead a esta etapa no pasa nada todavía.
                        </p>
                    )}

                    {problems > 0 && (
                        <p className="text-[11px] font-semibold text-amber-700 bg-amber-50 rounded-lg px-2.5 py-1.5">
                            {problems} {problems === 1 ? 'acción activa que no haría nada' : 'acciones activas que no harían nada'}.
                        </p>
                    )}

                    {stage.automations.map((automation) => {
                        if (editing === automation.id) {
                            return (
                                <ActionForm
                                    key={automation.id}
                                    pipeline={pipeline}
                                    stageId={stage.id}
                                    automation={automation}
                                    members={members}
                                    whatsappEnabled={whatsappEnabled}
                                    onDone={() => setEditing(null)}
                                />
                            );
                        }

                        const meta = ACTION_META[automation.action_type] ?? ACTION_META.add_note;
                        const problem = automation.is_active ? actionProblem(automation, whatsappEnabled) : null;
                        const config = automation.config ?? {};

                        return (
                            <div
                                key={automation.id}
                                className={`rounded-xl border p-3.5 transition-all ${
                                    problem ? 'border-amber-300 bg-amber-50/30' : automation.is_active ? 'border-gray-100 bg-white' : 'border-gray-100 bg-gray-50 opacity-70'
                                }`}
                            >
                                <div className="flex items-start gap-3">
                                    <div className={`w-9 h-9 shrink-0 rounded-xl bg-gradient-to-br ${meta.gradient} flex items-center justify-center text-white text-sm shadow-sm`}>
                                        {meta.icon}
                                    </div>
                                    <div className="flex-1 min-w-0">
                                        <div className="flex items-center gap-2 flex-wrap">
                                            <p className="text-sm font-semibold text-gray-900">{meta.label}</p>
                                            {automation.action_type === 'create_task' && (
                                                <span className="text-[10px] text-gray-500">
                                                    {TASK_TYPES[config.task_type] ?? 'Seguimiento'} · vence en {humanHours(config.due_in_hours)}
                                                    {config.assigned_to
                                                        ? ` · ${members.find((m) => m.id === config.assigned_to)?.name ?? 'alguien del equipo'}`
                                                        : ' · responsable del lead'}
                                                </span>
                                            )}
                                        </div>
                                        <p className="text-xs text-gray-500 mt-0.5 line-clamp-2">{config.text}</p>
                                        {problem && (
                                            <p className="text-[11px] font-semibold text-amber-600 mt-1">{problem}</p>
                                        )}
                                    </div>
                                    <span className="text-[10px] text-gray-400 shrink-0 tabular-nums" title="Veces que se ejecutó">
                                        {automation.execution_count}×
                                    </span>
                                </div>

                                <div className="flex items-center justify-end gap-1 mt-2 pt-2 border-t border-gray-50">
                                    <button
                                        onClick={() => router.post(route('automations.toggle', automation.id), {}, { preserveScroll: true })}
                                        className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold ring-1 transition-all ${
                                            automation.is_active
                                                ? 'bg-emerald-50 text-emerald-700 ring-emerald-200 hover:bg-emerald-100'
                                                : 'bg-gray-100 text-gray-600 ring-gray-200 hover:bg-gray-200'
                                        }`}
                                    >
                                        <span className={`w-1.5 h-1.5 rounded-full ${automation.is_active ? 'bg-emerald-500' : 'bg-gray-400'}`} />
                                        {automation.is_active ? 'Activa' : 'Pausada'}
                                    </button>
                                    <button
                                        onClick={() => { setEditing(automation.id); setPanel(null); }}
                                        className="px-2.5 py-1 text-[11px] font-semibold text-gray-500 hover:text-emerald-700 hover:bg-emerald-50 rounded-lg transition-colors"
                                    >
                                        Editar
                                    </button>
                                    <button
                                        onClick={() => { if (confirm('¿Eliminar esta acción?')) router.delete(route('automations.destroy', automation.id), { preserveScroll: true }); }}
                                        className="p-1.5 text-gray-300 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                                        title="Eliminar"
                                    >
                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        );
                    })}

                    {panel === 'new' && (
                        <ActionForm pipeline={pipeline} stageId={stage.id} members={members} whatsappEnabled={whatsappEnabled} onDone={() => setPanel(null)} />
                    )}
                    {panel === 'recipes' && (
                        <RecipePicker pipeline={pipeline} stage={stage} recipes={recipes} whatsappEnabled={whatsappEnabled} onDone={() => setPanel(null)} />
                    )}
                    {panel === 'preview' && (
                        <PreviewPanel pipeline={pipeline} stage={stage} sampleLeads={sampleLeads} onClose={() => setPanel(null)} />
                    )}

                    {!panel && !editing && (
                        <div className="flex flex-wrap gap-2">
                            <button
                                onClick={() => setPanel('recipes')}
                                className="flex-1 min-w-[8rem] rounded-xl border-2 border-dashed border-sky-200 py-2 text-xs font-semibold text-sky-600 hover:border-sky-400 hover:bg-sky-50/40 transition-all"
                            >
                                Usar plantilla
                            </button>
                            <button
                                onClick={() => setPanel('new')}
                                className="flex-1 min-w-[8rem] rounded-xl border-2 border-dashed border-gray-200 py-2 text-xs font-semibold text-gray-400 hover:border-emerald-300 hover:text-emerald-600 hover:bg-emerald-50/30 transition-all"
                            >
                                + Acción
                            </button>
                            {stage.automations.length > 0 && (
                                <button
                                    onClick={() => setPanel('preview')}
                                    className="flex-1 min-w-[8rem] rounded-xl border-2 border-dashed border-violet-200 py-2 text-xs font-semibold text-violet-600 hover:border-violet-400 hover:bg-violet-50/40 transition-all"
                                >
                                    Probar
                                </button>
                            )}
                        </div>
                    )}
                </div>
            </div>

            {!isLast && (
                <div className="flex justify-center py-1.5">
                    <svg className="w-4 h-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                    </svg>
                </div>
            )}
        </div>
    );
}

/* ---------------------------------------------------------------- página */

export default function Automations({ pipeline, stages, members, whatsappEnabled, recipes, sampleLeads = [] }) {
    const { flash } = usePage().props;

    const totalActions = stages.reduce((n, s) => n + s.automations.length, 0);
    const activeActions = stages.reduce((n, s) => n + s.automations.filter((a) => a.is_active).length, 0);
    const emptyStages = stages.filter((s) => s.automations.length === 0).length;

    return (
        <AuthenticatedLayout>
            <Head title="Digital Pipeline" />

            <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <div>
                    <Link href={route('leads.index')} className="text-sm text-emerald-600 hover:text-emerald-700 font-medium inline-flex items-center gap-1">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                        </svg>
                        Volver a leads
                    </Link>
                    <h1 className="text-2xl sm:text-3xl font-bold text-gray-900 mt-1">Automatizaciones — {pipeline.name}</h1>
                    <p className="text-sm text-gray-500 mt-1">
                        Cada vez que un lead <strong className="text-gray-700">entra</strong> a una etapa, el CRM ejecuta las acciones que configures acá.
                        Se disparan al mover la tarjeta en el Kanban, no cada rato.
                    </p>
                </div>

                {flash?.success && (
                    <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 shadow-sm">{flash.success}</div>
                )}

                {!whatsappEnabled && (
                    <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                        <strong>La integración con WhatsApp está inactiva.</strong> Las acciones de tipo «Enviar WhatsApp» quedan deshabilitadas
                        — si ya tenías alguna configurada, hoy no está saliendo.
                    </div>
                )}

                <div className="grid grid-cols-3 gap-4">
                    {[
                        { label: 'Acciones activas', value: activeActions, hint: 'corriendo hoy' },
                        { label: 'Pausadas', value: totalActions - activeActions, hint: 'configuradas, sin correr' },
                        { label: 'Etapas sin acciones', value: emptyStages, hint: `de ${stages.length}` },
                    ].map((s) => (
                        <div key={s.label} className="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 sm:p-5">
                            <p className="text-xs font-semibold uppercase tracking-wider text-gray-400">{s.label}</p>
                            <p className="text-2xl sm:text-3xl font-extrabold text-gray-900 mt-1 tabular-nums">{s.value}</p>
                            <p className="text-[11px] text-gray-400 mt-0.5">{s.hint}</p>
                        </div>
                    ))}
                </div>

                <div className="flex flex-col items-center">
                    <span className="px-2.5 py-0.5 rounded-full bg-gray-900 text-white text-[10px] font-bold uppercase tracking-wider">
                        Recorrido del lead
                    </span>
                </div>

                <div>
                    {stages.map((stage, i) => (
                        <StageCard
                            key={stage.id}
                            pipeline={pipeline}
                            stage={stage}
                            members={members}
                            recipes={recipes}
                            sampleLeads={sampleLeads}
                            whatsappEnabled={whatsappEnabled}
                            isLast={i === stages.length - 1}
                        />
                    ))}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
