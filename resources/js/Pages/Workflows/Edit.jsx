import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import SegmentBuilder, { describe } from '@/Components/SegmentBuilder';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * Constructor de workflows.
 *
 * El lienzo es **vertical**, igual que el de Digital Pipeline: se lee como el
 * recorrido del lead. Un grid de dos columnas en zig-zag no deja ver el orden,
 * que es justo lo único que importa entender de un workflow.
 *
 * Arriba del lienzo va la inscripción con su **conteo en vivo**: es la única
 * defensa real contra activar algo que le escriba a 800 personas. Y activar
 * exige que el servidor no reporte problemas de configuración.
 */

const STEP_ICONS = {
    send_whatsapp: 'M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.068.157 2.148.279 3.238.364.466.037.893.281 1.153.671L12 21l2.652-3.978c.26-.39.687-.634 1.153-.67 1.09-.086 2.17-.208 3.238-.365 1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z',
    create_task: 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    add_note: 'M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z',
    add_tag: 'M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z',
    remove_tag: 'M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z',
    change_stage: 'M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3',
    assign_responsible: 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0z',
    notify_user: 'M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0',
    wait: 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z',
    wait_until: 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5',
    branch: 'M6.115 5.19l.319 1.913A6 6 0 008.11 10.36L9.75 12l-.387.775c-.217.433-.132.956.21 1.298l1.348 1.348c.21.21.329.497.329.795v1.089c0 .426.24.815.622 1.006l.153.076c.433.217.956.132 1.298-.21l.723-.723a8.7 8.7 0 001.412-1.874l.007-.012',
    end: 'M5.25 7.5A2.25 2.25 0 017.5 5.25h9a2.25 2.25 0 012.25 2.25v9a2.25 2.25 0 01-2.25 2.25h-9a2.25 2.25 0 01-2.25-2.25v-9z',
};

const STATUS_STYLES = {
    ok: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    failed: 'bg-rose-50 text-rose-700 ring-rose-200',
    skipped: 'bg-gray-100 text-gray-500 ring-gray-200',
    later: 'bg-amber-50 text-amber-700 ring-amber-200',
};

/** Editor del `config` de un paso, según su tipo. */
function StepConfig({ step, options, catalog, onChange }) {
    const set = (patch) => onChange({ ...step, config: { ...step.config, ...patch } });
    const config = step.config ?? {};
    const input = 'w-full text-sm border-gray-200 rounded-xl focus:ring-[#045474] focus:border-[#045474]';

    const pick = (key, list) => (
        <select value={config[key] ?? ''} onChange={(e) => set({ [key]: e.target.value })} className={input}>
            <option value="">— Elegir —</option>
            {(list ?? []).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
        </select>
    );

    switch (step.step_type) {
        case 'send_whatsapp':
            return (
                <div className="space-y-2">
                    <textarea
                        value={config.text ?? ''}
                        onChange={(e) => set({ text: e.target.value })}
                        rows={3}
                        placeholder="Hola {name}, ¿seguís interesado?"
                        className={input}
                    />
                    <p className="text-[10px] text-gray-400">Tokens: {'{name}'} {'{title}'} {'{stage}'} {'{responsible}'}</p>
                    <label className="block text-[11px] font-semibold text-gray-500">
                        Si está fuera de la ventana de servicio
                        <select value={config.outside_window ?? 'skip'} onChange={(e) => set({ outside_window: e.target.value })} className={`${input} mt-1`}>
                            <option value="skip">No enviar (queda registrado)</option>
                            <option value="task">Crear una tarea para el asesor</option>
                        </select>
                    </label>
                </div>
            );
        case 'create_task':
            return (
                <div className="space-y-2">
                    <input value={config.text ?? ''} onChange={(e) => set({ text: e.target.value })} placeholder="Llamar a {name}" className={input} />
                    <div className="flex gap-2">
                        <select value={config.task_type ?? 'follow_up'} onChange={(e) => set({ task_type: e.target.value })} className={input}>
                            <option value="follow_up">Seguimiento</option>
                            <option value="call">Llamada</option>
                            <option value="meet">Reunión</option>
                        </select>
                        <input
                            type="number" min="1"
                            value={config.due_in_hours ?? 24}
                            onChange={(e) => set({ due_in_hours: Number(e.target.value) })}
                            className={input}
                            title="Horas hasta el vencimiento"
                        />
                    </div>
                </div>
            );
        case 'add_note':
            return <textarea value={config.text ?? ''} onChange={(e) => set({ text: e.target.value })} rows={2} className={input} />;
        case 'add_tag':
        case 'remove_tag':
            return pick('tag_id', options.tag);
        case 'change_stage':
            return pick('stage_id', options.stage);
        case 'assign_responsible':
            return pick('user_id', options.user);
        case 'notify_user':
            return (
                <div className="space-y-2">
                    {pick('user_id', options.user)}
                    <input value={config.title ?? ''} onChange={(e) => set({ title: e.target.value })} placeholder="Título del aviso" className={input} />
                    <p className="text-[10px] text-gray-400">Sin elegir a nadie, avisa al responsable del lead.</p>
                </div>
            );
        case 'wait':
            return (
                <input
                    type="number" min="1"
                    value={config.minutes ?? 60}
                    onChange={(e) => set({ minutes: Number(e.target.value) })}
                    className={input}
                    title="Minutos de espera"
                />
            );
        case 'wait_until':
            return (
                <div className="flex gap-2">
                    <input type="time" value={config.time ?? '09:00'} onChange={(e) => set({ time: e.target.value })} className={input} />
                    <select value={config.weekday ?? ''} onChange={(e) => set({ weekday: e.target.value })} className={input}>
                        <option value="">Cualquier día</option>
                        {['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'].map((d, i) => (
                            <option key={d} value={i + 1}>{d}</option>
                        ))}
                    </select>
                </div>
            );
        case 'branch':
            return (
                <div className="bg-white rounded-xl border border-gray-100 p-3">
                    <SegmentBuilder
                        group={config.filters ?? { version: 2, match: 'all', conditions: [] }}
                        catalog={catalog}
                        options={options}
                        onChange={(filters) => set({ filters: { ...filters, version: 2 } })}
                    />
                </div>
            );
        default:
            return null;
    }
}

function StepNode({ step, index, siblings, stepTypes, options, catalog, onChange, onRemove, onMove, onAddAfter, depth = 0 }) {
    const label = stepTypes.find((t) => t.type === step.step_type)?.label ?? step.step_type;
    const isBranch = step.step_type === 'branch';

    return (
        <div className="relative">
            <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div className="px-4 py-2.5 border-b border-gray-100 flex items-center gap-2 bg-gradient-to-r from-gray-50 to-transparent">
                    <span className="w-7 h-7 rounded-lg bg-gradient-to-br from-[#045474] to-[#1c486c] grid place-items-center text-white shrink-0">
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d={STEP_ICONS[step.step_type] ?? STEP_ICONS.end} />
                        </svg>
                    </span>
                    <span className="text-sm font-bold text-gray-900 min-w-0 truncate">{label}</span>

                    <span className="ml-auto flex items-center gap-0.5">
                        <button type="button" onClick={() => onMove(-1)} disabled={index === 0} className="p-1 text-gray-300 hover:text-gray-700 disabled:opacity-30" title="Subir">
                            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" /></svg>
                        </button>
                        <button type="button" onClick={() => onMove(1)} disabled={index === siblings - 1} className="p-1 text-gray-300 hover:text-gray-700 disabled:opacity-30" title="Bajar">
                            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                        </button>
                        <button type="button" onClick={onRemove} className="p-1 text-gray-300 hover:text-rose-600" title="Quitar paso">
                            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </span>
                </div>

                <div className="p-4">
                    <StepConfig step={step} options={options} catalog={catalog} onChange={onChange} />
                </div>

                {isBranch && (
                    <div className="grid sm:grid-cols-2 gap-3 px-4 pb-4">
                        {['yes', 'no'].map((key) => (
                            <div key={key} className={`rounded-xl border-2 border-dashed p-3 ${key === 'yes' ? 'border-emerald-200 bg-emerald-50/30' : 'border-gray-200 bg-gray-50/50'}`}>
                                <p className={`text-[10px] font-bold uppercase tracking-wider mb-2 ${key === 'yes' ? 'text-emerald-700' : 'text-gray-500'}`}>
                                    {key === 'yes' ? 'Si cumple' : 'Si no cumple'}
                                </p>
                                <StepList
                                    steps={(step.children ?? []).filter((c) => c.branch_key === key)}
                                    stepTypes={stepTypes}
                                    options={options}
                                    catalog={catalog}
                                    depth={depth + 1}
                                    onChange={(branchSteps) => onChange({
                                        ...step,
                                        children: [
                                            ...(step.children ?? []).filter((c) => c.branch_key !== key),
                                            ...branchSteps.map((s) => ({ ...s, branch_key: key })),
                                        ],
                                    })}
                                />
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {/* Conector con «+» entre pares: insertar en el medio, no solo al final. */}
            <div className="flex justify-center py-1.5">
                <button
                    type="button"
                    onClick={onAddAfter}
                    className="w-6 h-6 rounded-full bg-white border border-gray-200 text-gray-400 grid place-items-center hover:border-[#045474] hover:text-[#045474] transition-colors shadow-sm"
                    title="Insertar un paso acá"
                >
                    <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}><path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                </button>
            </div>
        </div>
    );
}

function StepList({ steps = [], stepTypes, options, catalog, onChange, depth = 0 }) {
    const [adding, setAdding] = useState(null);

    const insert = (at, type) => {
        const next = [...steps];
        next.splice(at, 0, { step_type: type, config: {}, children: [] });
        onChange(next);
        setAdding(null);
    };

    const move = (i, delta) => {
        const next = [...steps];
        const target = i + delta;
        if (target < 0 || target >= next.length) return;
        [next[i], next[target]] = [next[target], next[i]];
        onChange(next);
    };

    const picker = (at) => (
        <div className="bg-white rounded-2xl border border-gray-200 shadow-lg p-3 space-y-2">
            {['Acciones', 'Flujo'].map((group) => (
                <div key={group}>
                    <p className="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-1">{group}</p>
                    <div className="flex flex-wrap gap-1.5">
                        {stepTypes.filter((t) => t.group === group).map((t) => (
                            <button
                                key={t.type}
                                type="button"
                                onClick={() => insert(at, t.type)}
                                className="px-2.5 py-1 rounded-lg text-xs font-semibold bg-gray-50 text-gray-700 border border-gray-200 hover:border-[#045474]/40 hover:bg-white"
                            >
                                {t.label}
                            </button>
                        ))}
                    </div>
                </div>
            ))}
            <button type="button" onClick={() => setAdding(null)} className="text-[11px] font-semibold text-gray-400 hover:text-gray-600">
                Cancelar
            </button>
        </div>
    );

    return (
        <div>
            {steps.length === 0 && adding === null && (
                <button
                    type="button"
                    onClick={() => setAdding(0)}
                    className="w-full rounded-2xl border-2 border-dashed border-gray-200 py-6 text-xs font-semibold text-gray-400 hover:border-[#045474]/40 hover:text-[#045474]"
                >
                    + Agregar el primer paso
                </button>
            )}

            {adding === 0 && steps.length === 0 && picker(0)}

            {steps.map((step, i) => (
                <div key={step.id ?? `new-${i}`}>
                    <StepNode
                        step={step}
                        index={i}
                        siblings={steps.length}
                        stepTypes={stepTypes}
                        options={options}
                        catalog={catalog}
                        depth={depth}
                        onChange={(next) => onChange(steps.map((s, j) => (j === i ? next : s)))}
                        onRemove={() => onChange(steps.filter((_, j) => j !== i))}
                        onMove={(delta) => move(i, delta)}
                        onAddAfter={() => setAdding(i + 1)}
                    />
                    {adding === i + 1 && picker(i + 1)}
                </div>
            ))}
        </div>
    );
}

export default function WorkflowEdit({ workflow, tree = [], problems = [], catalog = [], options = {}, stepTypes = [], triggers = [], limits = {}, sampleLeads = [] }) {
    const [steps, setSteps] = useState(tree);
    const [simulation, setSimulation] = useState(null);
    const [sampleLead, setSampleLead] = useState(sampleLeads[0]?.id ?? '');
    const [count, setCount] = useState(null);

    const form = useForm({
        name: workflow.name,
        description: workflow.description ?? '',
        enrollment_type: workflow.enrollment_type,
        enrollment_filters: workflow.enrollment_filters,
        trigger_type: workflow.trigger_type ?? '',
        allow_reenrollment: workflow.allow_reenrollment,
        reenrollment_cooldown_minutes: workflow.reenrollment_cooldown_minutes ?? limits.minCooldown,
        goal_filters: workflow.goal_filters,
        unenroll_when_criteria_lost: workflow.unenroll_when_criteria_lost,
        execution_window: workflow.execution_window ?? null,
    });

    // Conteo en vivo de a cuántos alcanzaría la inscripción.
    const filtersKey = JSON.stringify(form.data.enrollment_filters);
    useEffect(() => {
        if (form.data.enrollment_type !== 'filter') return undefined;

        let cancelled = false;
        const timer = setTimeout(() => {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            fetch(route('workflows.enrollment-count', workflow.id), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ filters: JSON.parse(filtersKey) }),
            })
                .then((r) => (r.ok ? r.json() : null))
                .then((d) => { if (!cancelled) setCount(d); })
                .catch(() => {});
        }, 400);

        return () => { cancelled = true; clearTimeout(timer); };
    }, [filtersKey, form.data.enrollment_type, workflow.id]);

    const saveSettings = (e) => {
        e.preventDefault();
        form.patch(route('workflows.update', workflow.id), { preserveScroll: true });
    };

    const saveSteps = () => router.put(route('workflows.steps', workflow.id), { steps }, { preserveScroll: true });

    const simulate = () => {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        fetch(route('workflows.simulate', workflow.id), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ lead_id: sampleLead, steps }),
        })
            .then((r) => r.json())
            .then(setSimulation)
            .catch(() => setSimulation({ steps: [], error: 'No se pudo simular.' }));
    };

    const toggle = () => router.patch(route('workflows.toggle', workflow.id), {}, { preserveScroll: true });

    return (
        <AuthenticatedLayout>
            <Head title={workflow.name} />

            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
                    <div className="min-w-0">
                        <Link href={route('workflows.index')} className="text-xs font-semibold text-gray-400 hover:text-gray-600">← Workflows</Link>
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900 truncate">{workflow.name}</h1>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button onClick={saveSteps} className="px-4 py-2 rounded-xl text-sm font-semibold bg-white border border-gray-200 text-gray-700 hover:bg-gray-50 shadow-sm">
                            Guardar pasos
                        </button>
                        <button
                            onClick={toggle}
                            className={`px-4 py-2 rounded-xl text-sm font-bold shadow-lg ${
                                workflow.is_active ? 'bg-white border border-rose-200 text-rose-700 hover:bg-rose-50' : 'bg-gradient-to-r from-emerald-600 to-teal-600 text-white hover:opacity-90'
                            }`}
                        >
                            {workflow.is_active ? 'Desactivar' : 'Activar'}
                        </button>
                    </div>
                </div>

                {problems.length > 0 && !workflow.is_active && (
                    <div className="rounded-2xl bg-amber-50 border border-amber-200 px-5 py-4">
                        <p className="text-sm font-bold text-amber-900">Falta antes de poder activar</p>
                        <ul className="mt-1.5 space-y-0.5">
                            {problems.map((p) => <li key={p} className="text-xs text-amber-800">· {p}</li>)}
                        </ul>
                    </div>
                )}

                <div className="grid gap-6 lg:grid-cols-3">
                    {/* Inscripción, meta y ventana */}
                    <form onSubmit={saveSettings} className="lg:col-span-1 space-y-4">
                        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-4">
                            <h2 className="text-sm font-bold text-gray-900">Quién entra</h2>

                            <select
                                value={form.data.enrollment_type}
                                onChange={(e) => form.setData('enrollment_type', e.target.value)}
                                className="w-full text-sm border-gray-200 rounded-xl focus:ring-[#045474] focus:border-[#045474]"
                            >
                                <option value="filter">Por criterios (entra solo al cumplirlos)</option>
                                <option value="event">Por un evento puntual</option>
                            </select>

                            {form.data.enrollment_type === 'filter' ? (
                                <>
                                    <SegmentBuilder
                                        group={form.data.enrollment_filters}
                                        catalog={catalog}
                                        options={options}
                                        onChange={(g) => form.setData('enrollment_filters', { ...g, version: 2 })}
                                    />
                                    {count && (
                                        <p className="text-xs text-gray-600 bg-[#045474]/5 rounded-xl px-3 py-2">
                                            <strong className="text-[#045474]">{count.matching}</strong> leads cumplen hoy
                                            {count.matching > count.firstSweep && (
                                                <span className="text-gray-400"> · entrarían de a {count.firstSweep} por pasada</span>
                                            )}
                                        </p>
                                    )}
                                </>
                            ) : (
                                <select
                                    value={form.data.trigger_type}
                                    onChange={(e) => form.setData('trigger_type', e.target.value)}
                                    className="w-full text-sm border-gray-200 rounded-xl focus:ring-[#045474] focus:border-[#045474]"
                                >
                                    <option value="">— Elegir evento —</option>
                                    {triggers.map((t) => <option key={t} value={t}>{t}</option>)}
                                </select>
                            )}

                            <label className="flex items-center gap-2 text-xs font-semibold text-gray-600">
                                <input
                                    type="checkbox"
                                    checked={form.data.allow_reenrollment}
                                    onChange={(e) => form.setData('allow_reenrollment', e.target.checked)}
                                    className="rounded border-gray-300 text-[#045474] focus:ring-[#045474]"
                                />
                                Permitir que vuelva a entrar
                            </label>

                            {form.data.allow_reenrollment && (
                                <div>
                                    <input
                                        type="number"
                                        min={limits.minCooldown}
                                        value={form.data.reenrollment_cooldown_minutes}
                                        onChange={(e) => form.setData('reenrollment_cooldown_minutes', Number(e.target.value))}
                                        className="w-full text-sm border-gray-200 rounded-xl focus:ring-[#045474] focus:border-[#045474]"
                                    />
                                    <p className="text-[10px] text-gray-400 mt-1">
                                        Minutos de enfriamiento. El mínimo del sistema es {limits.minCooldown} — sin él,
                                        el barredor reinscribiría al mismo lead cada 10 minutos.
                                    </p>
                                </div>
                            )}

                            <label className="flex items-center gap-2 text-xs font-semibold text-gray-600">
                                <input
                                    type="checkbox"
                                    checked={form.data.unenroll_when_criteria_lost}
                                    onChange={(e) => form.setData('unenroll_when_criteria_lost', e.target.checked)}
                                    className="rounded border-gray-300 text-[#045474] focus:ring-[#045474]"
                                />
                                Sacarlo si deja de cumplir
                            </label>
                        </div>

                        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-3">
                            <h2 className="text-sm font-bold text-gray-900">Meta</h2>
                            <p className="text-[11px] text-gray-500">
                                Al cumplirla el lead sale y la corrida cuenta como conversión. Sin meta, alguien que ya
                                compró seguiría recibiendo la secuencia.
                            </p>
                            <SegmentBuilder
                                group={form.data.goal_filters}
                                catalog={catalog}
                                options={options}
                                onChange={(g) => form.setData('goal_filters', { ...g, version: 2 })}
                            />
                        </div>

                        <button type="submit" disabled={form.processing} className="w-full px-4 py-2 rounded-xl text-sm font-semibold bg-gradient-to-r from-[#045474] to-[#1c486c] text-white shadow-lg disabled:opacity-50">
                            {form.processing ? 'Guardando…' : 'Guardar configuración'}
                        </button>
                        {form.errors.is_active && <p className="text-xs text-rose-600">{form.errors.is_active}</p>}
                    </form>

                    {/* Lienzo */}
                    <div className="lg:col-span-2 space-y-4">
                        <div className="bg-gray-50 rounded-2xl border border-gray-100 p-4">
                            <p className="text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-3">Qué pasa</p>
                            <StepList
                                steps={steps}
                                stepTypes={stepTypes}
                                options={options}
                                catalog={catalog}
                                onChange={setSteps}
                            />
                        </div>

                        {/* Simulador: no escribe nada. */}
                        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                            <div className="flex flex-wrap items-center gap-2">
                                <h2 className="text-sm font-bold text-gray-900">Probar con un lead real</h2>
                                <select
                                    value={sampleLead}
                                    onChange={(e) => setSampleLead(e.target.value)}
                                    className="text-sm border-gray-200 rounded-xl py-1.5 focus:ring-[#045474] focus:border-[#045474]"
                                >
                                    {sampleLeads.map((l) => <option key={l.id} value={l.id}>{l.label}</option>)}
                                </select>
                                <button onClick={simulate} disabled={!sampleLead} className="px-3 py-1.5 rounded-xl text-xs font-bold bg-violet-50 text-violet-700 ring-1 ring-violet-200 hover:bg-violet-100 disabled:opacity-40">
                                    Simular
                                </button>
                            </div>
                            <p className="text-[11px] text-gray-400 mt-1">
                                Recorre los pasos que están en pantalla. No envía WhatsApp, no crea tareas ni notas, no etiqueta.
                            </p>

                            {simulation && (
                                <ol className="mt-4 space-y-1.5">
                                    {simulation.steps?.length ? simulation.steps.map((s, i) => (
                                        <li key={i} className={`text-xs px-3 py-2 rounded-xl ring-1 ${STATUS_STYLES[s.status] ?? STATUS_STYLES.skipped}`}>
                                            <strong>{stepTypes.find((t) => t.type === s.type)?.label ?? s.type}</strong>
                                            <span className="opacity-80"> · {s.detail}</span>
                                        </li>
                                    )) : (
                                        <li className="text-xs text-gray-400">Sin pasos que simular.</li>
                                    )}
                                </ol>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
