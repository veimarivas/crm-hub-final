import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const ACTION_META = {
    send_whatsapp: { label: 'Enviar WhatsApp', icon: '💬', gradient: 'from-emerald-500 to-teal-600' },
    create_task: { label: 'Crear tarea', icon: '📋', gradient: 'from-blue-500 to-indigo-600' },
    add_note: { label: 'Dejar nota', icon: '📝', gradient: 'from-amber-500 to-orange-600' },
};

const inputClass = 'w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 transition-all';

function NewAutomationForm({ pipeline, stage, members, whatsappEnabled, onDone }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        stage_id: stage.id,
        action_type: 'create_task',
        config: { text: '', task_type: 'follow_up', due_in_hours: 24, assigned_to: '' },
    });

    const setConfig = (patch) => setData('config', { ...data.config, ...patch });

    const submit = (e) => {
        e.preventDefault();
        post(route('pipelines.automations.store', pipeline.id), {
            preserveScroll: true,
            onSuccess: () => { reset(); onDone(); },
        });
    };

    return (
        <form onSubmit={submit} className="rounded-xl border-2 border-dashed border-emerald-200 bg-emerald-50/40 p-4 space-y-3">
            <div className="flex flex-wrap gap-2">
                {Object.entries(ACTION_META).map(([type, meta]) => (
                    <button
                        key={type}
                        type="button"
                        disabled={type === 'send_whatsapp' && !whatsappEnabled}
                        onClick={() => setData('action_type', type)}
                        title={type === 'send_whatsapp' && !whatsappEnabled ? 'Activa la integración con el wacrm primero' : ''}
                        className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-all disabled:opacity-40 disabled:cursor-not-allowed ${
                            data.action_type === type
                                ? 'bg-gradient-to-r ' + meta.gradient + ' text-white shadow-md'
                                : 'bg-white text-gray-600 ring-1 ring-gray-200 hover:ring-gray-300'
                        }`}
                    >
                        {meta.icon} {meta.label}
                    </button>
                ))}
            </div>

            <textarea
                rows={2}
                value={data.config.text}
                onChange={(e) => setConfig({ text: e.target.value })}
                required
                placeholder={
                    data.action_type === 'send_whatsapp'
                        ? 'Mensaje — admite {name}, {title}, {value}, {stage}'
                        : data.action_type === 'create_task'
                            ? 'Descripción de la tarea — ej. Llamar a {name} para dar seguimiento'
                            : 'Texto de la nota'
                }
                className={inputClass}
            />
            {errors['config.text'] && <p className="text-xs text-red-500 font-medium">{errors['config.text']}</p>}

            {data.action_type === 'create_task' && (
                <div className="flex flex-wrap items-center gap-3 text-xs text-gray-600">
                    <select value={data.config.task_type} onChange={(e) => setConfig({ task_type: e.target.value })} className="px-2.5 py-1.5 border border-gray-200 rounded-lg bg-white text-xs">
                        <option value="call">📞 Llamar</option>
                        <option value="meet">🤝 Reunión</option>
                        <option value="follow_up">🔔 Seguimiento</option>
                        <option value="email">✉️ Email</option>
                        <option value="other">Otra</option>
                    </select>
                    <label className="flex items-center gap-1.5">
                        Vence en
                        <input type="number" min="1" max="720" value={data.config.due_in_hours} onChange={(e) => setConfig({ due_in_hours: Number(e.target.value) })} className="w-16 px-2 py-1.5 border border-gray-200 rounded-lg bg-white text-center text-xs" />
                        horas
                    </label>
                    <select value={data.config.assigned_to} onChange={(e) => setConfig({ assigned_to: e.target.value })} className="px-2.5 py-1.5 border border-gray-200 rounded-lg bg-white text-xs">
                        <option value="">Responsable del lead</option>
                        {members.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                    </select>
                </div>
            )}

            <div className="flex justify-end gap-2">
                <button type="button" onClick={onDone} className="px-3 py-2 text-xs font-semibold text-gray-600 hover:text-gray-800">Cancelar</button>
                <button type="submit" disabled={processing} className="px-4 py-2 text-xs font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 disabled:opacity-50 transition-all shadow-lg shadow-emerald-500/20">
                    Guardar automatización
                </button>
            </div>
        </form>
    );
}

export default function Automations({ pipeline, stages, members, whatsappEnabled }) {
    const { flash } = usePage().props;
    const [adding, setAdding] = useState(null); // stage id

    return (
        <AuthenticatedLayout>
            <Head title="Digital Pipeline" />

            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <div>
                    <Link href={route('leads.index')} className="text-sm text-emerald-600 hover:text-emerald-700 font-medium inline-flex items-center gap-1">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                        </svg>
                        Volver a leads
                    </Link>
                    <h1 className="text-2xl sm:text-3xl font-bold text-gray-900 mt-1">Digital Pipeline — {pipeline.name}</h1>
                    <p className="text-sm text-gray-400 mt-1">
                        Cuando un lead <strong>entra</strong> a una etapa, se ejecutan sus acciones automáticas
                    </p>
                </div>

                {flash?.success && (
                    <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 shadow-sm">{flash.success}</div>
                )}

                <div className="grid gap-5 lg:grid-cols-2 items-start">
                    {stages.map((stage) => (
                        <div key={stage.id} className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                            <div className="px-5 py-3.5 flex items-center justify-between text-white" style={{ background: `linear-gradient(135deg, ${stage.color} 0%, ${stage.color}dd 100%)` }}>
                                <span className="font-bold text-sm flex items-center gap-1.5">
                                    {stage.stage_type === 'won' && '🏆'}
                                    {stage.stage_type === 'lost' && '✕'}
                                    {stage.name}
                                </span>
                                <span className="text-[10px] font-bold bg-white/25 rounded-full px-2 py-0.5">
                                    {stage.automations.length} automatización{stage.automations.length !== 1 ? 'es' : ''}
                                </span>
                            </div>

                            <div className="p-4 space-y-2">
                                {stage.automations.map((automation) => {
                                    const meta = ACTION_META[automation.action_type] ?? ACTION_META.add_note;
                                    return (
                                        <div key={automation.id} className={`flex items-center gap-3 rounded-xl border p-3.5 ${automation.is_active ? 'border-gray-100 bg-white' : 'border-gray-100 bg-gray-50 opacity-60'}`}>
                                            <div className={`w-9 h-9 shrink-0 rounded-xl bg-gradient-to-br ${meta.gradient} flex items-center justify-center text-white text-sm shadow-sm`}>
                                                {meta.icon}
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <p className="text-sm font-semibold text-gray-900">{meta.label}</p>
                                                <p className="text-xs text-gray-500 truncate">{automation.config?.text}</p>
                                            </div>
                                            <span className="text-[10px] text-gray-400 shrink-0 tabular-nums">{automation.execution_count} ejec.</span>
                                            <button
                                                onClick={() => router.post(route('automations.toggle', automation.id), {}, { preserveScroll: true })}
                                                className={`shrink-0 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold ring-1 transition-all ${
                                                    automation.is_active
                                                        ? 'bg-emerald-50 text-emerald-700 ring-emerald-200 hover:bg-emerald-100'
                                                        : 'bg-gray-100 text-gray-600 ring-gray-200 hover:bg-gray-200'
                                                }`}
                                            >
                                                <span className={`w-1.5 h-1.5 rounded-full ${automation.is_active ? 'bg-emerald-500' : 'bg-gray-400'}`} />
                                                {automation.is_active ? 'Activa' : 'Pausada'}
                                            </button>
                                            <button
                                                onClick={() => { if (confirm('¿Eliminar esta automatización?')) router.delete(route('automations.destroy', automation.id), { preserveScroll: true }); }}
                                                className="shrink-0 p-1.5 text-gray-300 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                                            >
                                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166" /></svg>
                                            </button>
                                        </div>
                                    );
                                })}

                                {adding === stage.id ? (
                                    <NewAutomationForm pipeline={pipeline} stage={stage} members={members} whatsappEnabled={whatsappEnabled} onDone={() => setAdding(null)} />
                                ) : (
                                    <button
                                        onClick={() => setAdding(stage.id)}
                                        className="w-full rounded-xl border-2 border-dashed border-gray-200 py-2.5 text-xs font-semibold text-gray-400 hover:border-emerald-300 hover:text-emerald-600 hover:bg-emerald-50/30 transition-all"
                                    >
                                        + Añadir acción al entrar a "{stage.name}"
                                    </button>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
