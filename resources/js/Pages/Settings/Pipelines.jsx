import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const PRESET_COLORS = ['#0ea5e9', '#8b5cf6', '#f59e0b', '#ec4899', '#10b981', '#ef4444', '#6366f1', '#14b8a6', '#eab308'];

function StageRow({ stage, isFirst, isLast, onSave, onDelete, onMove }) {
    const [editing, setEditing] = useState(false);
    const [name, setName] = useState(stage.name);
    const [color, setColor] = useState(stage.color);

    const save = () => {
        if (!name.trim()) return;
        onSave(stage.id, { name: name.trim(), color });
        setEditing(false);
    };

    const isTerminal = stage.stage_type !== 'open';
    const typeBadge = stage.stage_type === 'won' ? { icon: '🏆', label: 'Ganado', color: 'bg-emerald-100 text-emerald-700' }
        : stage.stage_type === 'lost' ? { icon: '✕', label: 'Perdido', color: 'bg-red-100 text-red-700' }
        : null;

    return (
        <li className={`flex items-center gap-3 p-3 rounded-xl border transition-colors ${editing ? 'border-emerald-300 bg-emerald-50/40' : 'border-gray-100 bg-white hover:bg-gray-50'}`}>
            {!isTerminal ? (
                <div className="flex flex-col gap-0.5 shrink-0">
                    <button onClick={() => onMove(stage.id, -1)} disabled={isFirst} className="p-0.5 text-gray-400 hover:text-emerald-600 disabled:opacity-30 disabled:cursor-not-allowed">
                        <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}><path strokeLinecap="round" strokeLinejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" /></svg>
                    </button>
                    <button onClick={() => onMove(stage.id, +1)} disabled={isLast} className="p-0.5 text-gray-400 hover:text-emerald-600 disabled:opacity-30 disabled:cursor-not-allowed">
                        <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}><path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                    </button>
                </div>
            ) : (
                <span className="w-3 shrink-0" />
            )}

            {editing ? (
                <>
                    <input type="color" value={color} onChange={(e) => setColor(e.target.value)} className="w-9 h-9 rounded-lg border border-gray-200 cursor-pointer shrink-0" />
                    <input value={name} onChange={(e) => setName(e.target.value)} maxLength={100} className="flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/30" onKeyDown={(e) => e.key === 'Enter' && save()} autoFocus />
                    <button onClick={save} className="px-3 py-2 rounded-lg text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-500">Guardar</button>
                    <button onClick={() => { setEditing(false); setName(stage.name); setColor(stage.color); }} className="px-2 py-2 rounded-lg text-xs font-semibold text-gray-500 hover:bg-gray-100">Cancelar</button>
                </>
            ) : (
                <>
                    <span className="w-9 h-9 rounded-lg shadow-inner shrink-0" style={{ backgroundColor: stage.color }} />
                    <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                            <span className="text-sm font-bold text-gray-900 truncate">{stage.name}</span>
                            {typeBadge && <span className={`text-[10px] font-bold px-1.5 py-0.5 rounded-full ${typeBadge.color}`}>{typeBadge.icon} {typeBadge.label}</span>}
                        </div>
                        <p className="text-[10px] text-gray-400 mt-0.5">Posición {stage.position} · {stage.leads_count ?? 0} leads</p>
                    </div>
                    <button onClick={() => setEditing(true)} className="p-2 rounded-lg text-gray-400 hover:text-emerald-600 hover:bg-emerald-50" title="Editar">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487z" /></svg>
                    </button>
                    {!isTerminal && (
                        <button onClick={() => { if (confirm(`¿Borrar la etapa "${stage.name}"?`)) onDelete(stage.id); }} className="p-2 rounded-lg text-gray-300 hover:text-red-600 hover:bg-red-50" title="Borrar">
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166" /></svg>
                        </button>
                    )}
                </>
            )}
        </li>
    );
}

function PipelineCard({ pipeline }) {
    const [addingStage, setAddingStage] = useState(false);
    const [editingName, setEditingName] = useState(false);
    const nameForm = useForm({ name: pipeline.name, is_default: pipeline.is_default });
    const stageForm = useForm({ name: '', color: PRESET_COLORS[0] });

    const openStages = pipeline.stages.filter((s) => s.stage_type === 'open').sort((a, b) => a.position - b.position);
    const terminalStages = pipeline.stages.filter((s) => s.stage_type !== 'open').sort((a, b) => a.position - b.position);

    const saveName = () => {
        nameForm.patch(route('pipelines.update', pipeline.id), { preserveScroll: true, onSuccess: () => setEditingName(false) });
    };

    const addStage = (e) => {
        e.preventDefault();
        if (!stageForm.data.name.trim()) return;
        stageForm.post(route('pipelines.stages.store', pipeline.id), { preserveScroll: true, onSuccess: () => { stageForm.reset('name'); setAddingStage(false); } });
    };

    const updateStage = (id, patch) => router.patch(route('pipelines.stages.update', id), patch, { preserveScroll: true });
    const deleteStage = (id) => router.delete(route('pipelines.stages.destroy', id), { preserveScroll: true });
    const moveStage = (id, delta) => {
        const idx = openStages.findIndex((s) => s.id === id);
        const newIdx = idx + delta;
        if (newIdx < 0 || newIdx >= openStages.length) return;
        const reordered = [...openStages];
        [reordered[idx], reordered[newIdx]] = [reordered[newIdx], reordered[idx]];
        router.post(route('pipelines.stages.reorder', pipeline.id), { order: reordered.map((s) => s.id) }, { preserveScroll: true });
    };

    return (
        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div className="p-5 border-b border-gray-100 flex items-center justify-between gap-3 bg-gradient-to-r from-gray-50 to-transparent">
                {editingName ? (
                    <div className="flex items-center gap-2 flex-1">
                        <input value={nameForm.data.name} onChange={(e) => nameForm.setData('name', e.target.value)} className="flex-1 px-3 py-2 border border-emerald-300 rounded-lg text-base font-bold" onKeyDown={(e) => e.key === 'Enter' && saveName()} autoFocus />
                        <label className="text-xs font-semibold text-gray-600 flex items-center gap-1"><input type="checkbox" checked={nameForm.data.is_default} onChange={(e) => nameForm.setData('is_default', e.target.checked)} /> Default</label>
                        <button onClick={saveName} className="px-3 py-2 rounded-lg text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-500">Guardar</button>
                        <button onClick={() => { setEditingName(false); nameForm.reset(); }} className="text-xs text-gray-500 px-2">Cancelar</button>
                    </div>
                ) : (
                    <>
                        <div className="min-w-0 flex-1">
                            <div className="flex items-center gap-2">
                                <h3 className="text-lg font-bold text-gray-900 truncate">{pipeline.name}</h3>
                                {pipeline.is_default && <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-100 text-amber-800">DEFAULT</span>}
                            </div>
                            <p className="text-xs text-gray-500 mt-0.5">{pipeline.leads_count} leads · {openStages.length} etapas activas</p>
                        </div>
                        <div className="flex items-center gap-1 shrink-0">
                            <button onClick={() => setEditingName(true)} className="p-2 rounded-lg text-gray-400 hover:text-emerald-600 hover:bg-emerald-50" title="Editar nombre">
                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487z" /></svg>
                            </button>
                            {!pipeline.is_default && pipeline.leads_count === 0 && (
                                <button onClick={() => { if (confirm(`¿Borrar el pipeline "${pipeline.name}"?`)) router.delete(route('pipelines.destroy', pipeline.id), { preserveScroll: true }); }} className="p-2 rounded-lg text-gray-300 hover:text-red-600 hover:bg-red-50">
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166" /></svg>
                                </button>
                            )}
                        </div>
                    </>
                )}
            </div>

            <div className="p-5 space-y-2">
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-2">Etapas activas</p>
                <ul className="space-y-2">
                    {openStages.map((s, i) => (
                        <StageRow
                            key={s.id}
                            stage={s}
                            isFirst={i === 0}
                            isLast={i === openStages.length - 1}
                            onSave={updateStage}
                            onDelete={deleteStage}
                            onMove={moveStage}
                        />
                    ))}
                </ul>

                {addingStage ? (
                    <form onSubmit={addStage} className="mt-3 flex items-center gap-2 p-3 rounded-xl border-2 border-dashed border-emerald-200 bg-emerald-50/30">
                        <input type="color" value={stageForm.data.color} onChange={(e) => stageForm.setData('color', e.target.value)} className="w-9 h-9 rounded-lg border border-gray-200 cursor-pointer shrink-0" />
                        <input
                            value={stageForm.data.name}
                            onChange={(e) => stageForm.setData('name', e.target.value)}
                            placeholder="Nombre de la etapa"
                            maxLength={100}
                            required
                            autoFocus
                            className="flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/30"
                        />
                        <button type="submit" disabled={stageForm.processing} className="px-3 py-2 rounded-lg text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50">Agregar</button>
                        <button type="button" onClick={() => setAddingStage(false)} className="text-xs text-gray-500 px-2">Cancelar</button>
                    </form>
                ) : (
                    <button onClick={() => setAddingStage(true)} className="mt-2 w-full py-2.5 text-xs font-bold text-emerald-600 border-2 border-dashed border-emerald-200 rounded-xl hover:bg-emerald-50 transition-colors">
                        + Agregar etapa
                    </button>
                )}

                <div className="mt-4 pt-4 border-t border-gray-100">
                    <p className="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-2">Etapas terminales (requeridas)</p>
                    <ul className="space-y-2">
                        {terminalStages.map((s) => (
                            <StageRow key={s.id} stage={s} isFirst isLast onSave={updateStage} onDelete={deleteStage} onMove={() => {}} />
                        ))}
                    </ul>
                </div>

                <div className="mt-4 pt-4 border-t border-gray-100">
                    <Link
                        href={route('pipelines.automations', pipeline.id)}
                        className="w-full inline-flex items-center justify-center gap-2 py-2.5 text-xs font-bold text-violet-700 bg-violet-50 border border-violet-200 rounded-xl hover:bg-violet-100 transition-colors"
                    >
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                        </svg>
                        Automatizaciones por etapa
                    </Link>
                </div>
            </div>
        </div>
    );
}

export default function Pipelines({ pipelines }) {
    const { flash } = usePage().props;
    const [showNew, setShowNew] = useState(false);
    const newForm = useForm({ name: '' });

    const create = (e) => {
        e.preventDefault();
        if (!newForm.data.name.trim()) return;
        newForm.post(route('pipelines.store'), { preserveScroll: true, onSuccess: () => { newForm.reset(); setShowNew(false); } });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Pipelines" />
            <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-5">
                <div className="flex items-end justify-between gap-4 flex-wrap">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Pipelines</h1>
                        <p className="text-sm text-gray-500 mt-1">Configura las etapas de tu proceso de ventas</p>
                    </div>
                    <button onClick={() => setShowNew(!showNew)} className="px-4 py-2 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:opacity-90 shadow-lg shadow-emerald-500/20 inline-flex items-center gap-1.5">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        Nuevo pipeline
                    </button>
                </div>

                {flash?.success && <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 shadow-sm">{flash.success}</div>}

                {showNew && (
                    <form onSubmit={create} className="bg-emerald-50 rounded-2xl border-2 border-dashed border-emerald-200 p-5 flex items-center gap-3">
                        <input
                            value={newForm.data.name}
                            onChange={(e) => newForm.setData('name', e.target.value)}
                            placeholder="Nombre del pipeline (ej. Ventas B2B)"
                            required autoFocus maxLength={100}
                            className="flex-1 px-3.5 py-2.5 border border-emerald-200 rounded-xl text-sm bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/30"
                        />
                        <button type="submit" disabled={newForm.processing} className="px-4 py-2.5 rounded-xl text-sm font-bold text-white bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50">Crear</button>
                        <button type="button" onClick={() => { newForm.reset(); setShowNew(false); }} className="text-sm text-gray-500 px-2">Cancelar</button>
                    </form>
                )}

                {pipelines.map((p) => <PipelineCard key={p.id} pipeline={p} />)}

                {pipelines.length === 0 && (
                    <div className="bg-white rounded-2xl border border-gray-100 p-12 text-center text-sm text-gray-400">
                        Sin pipelines — creá el primero
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
