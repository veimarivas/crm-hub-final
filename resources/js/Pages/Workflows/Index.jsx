import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Listado de workflows.
 *
 * Arriba de todo, el **kill switch de la cuenta**: si algo se descontroló, hay
 * que poder pararlo desde acá y no esperando un deploy. Por eso está a la vista
 * y no escondido en configuración.
 */
export default function WorkflowsIndex({ workflows = [], paused = false }) {
    const [creating, setCreating] = useState(false);
    const form = useForm({ name: '' });

    const create = (e) => {
        e.preventDefault();
        form.post(route('workflows.store'));
    };

    return (
        <AuthenticatedLayout>
            <Head title="Workflows" />

            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Workflows</h1>
                        <p className="text-sm text-gray-500 mt-1">
                            Se define quién debe estar y el motor mete y saca leads solo, a medida que la realidad cambia.
                        </p>
                    </div>
                    <button
                        onClick={() => setCreating(true)}
                        className="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold bg-gradient-to-r from-[#045474] to-[#1c486c] text-white shadow-lg hover:opacity-90"
                    >
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        Nuevo workflow
                    </button>
                </div>

                <div className={`rounded-2xl border px-5 py-4 flex flex-wrap items-center justify-between gap-3 ${
                    paused ? 'bg-rose-50 border-rose-200' : 'bg-white border-gray-100 shadow-sm'
                }`}>
                    <div>
                        <p className={`text-sm font-bold ${paused ? 'text-rose-800' : 'text-gray-900'}`}>
                            {paused ? 'Workflows en pausa' : 'Workflows activos'}
                        </p>
                        <p className="text-xs text-gray-500 mt-0.5">
                            {paused
                                ? 'No se ejecuta ningún paso ni se inscribe a nadie, en toda la cuenta.'
                                : 'Freno de emergencia: para todo sin esperar un deploy.'}
                        </p>
                    </div>
                    <button
                        onClick={() => router.patch(route('workflows.pause'), {}, { preserveScroll: true })}
                        className={`px-4 py-2 rounded-xl text-sm font-bold shadow-sm ${
                            paused ? 'bg-emerald-600 text-white hover:bg-emerald-700' : 'bg-white border border-rose-200 text-rose-700 hover:bg-rose-50'
                        }`}
                    >
                        {paused ? 'Reanudar' : 'Pausar todo'}
                    </button>
                </div>

                {workflows.length === 0 ? (
                    <div className="bg-white rounded-2xl border border-dashed border-gray-300 px-5 py-16 text-center">
                        <p className="text-sm font-semibold text-gray-500">Todavía no hay workflows</p>
                        <p className="text-xs text-gray-400 mt-1">Nacen inactivos: podés armarlos con calma y activarlos después.</p>
                    </div>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {workflows.map((w) => (
                            <Link
                                key={w.id}
                                href={route('workflows.edit', w.id)}
                                className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 hover:shadow-md transition-all flex flex-col"
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <h3 className="text-base font-bold text-gray-900 min-w-0 truncate">{w.name}</h3>
                                    <span className={`shrink-0 text-[10px] font-bold px-2 py-0.5 rounded-full ring-1 ${
                                        w.is_active ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-gray-100 text-gray-500 ring-gray-200'
                                    }`}>
                                        {w.is_active ? 'Activo' : 'Borrador'}
                                    </span>
                                </div>

                                <p className="text-xs text-gray-400 mt-1">
                                    {w.enrollment_type === 'filter' ? 'Inscribe por criterios' : 'Inscribe por evento'}
                                    {' · '}{w.steps_count} paso{w.steps_count === 1 ? '' : 's'}
                                </p>

                                {w.description && <p className="text-xs text-gray-500 mt-2 flex-1">{w.description}</p>}

                                <dl className="grid grid-cols-4 gap-2 mt-4 pt-3 border-t border-gray-100 text-center">
                                    {[
                                        ['En curso', w.stats.active, 'text-gray-900'],
                                        ['Meta', w.stats.goal, 'text-emerald-600'],
                                        ['Fin', w.stats.completed, 'text-gray-500'],
                                        ['Fallidos', w.stats.failed, w.stats.failed > 0 ? 'text-rose-600' : 'text-gray-300'],
                                    ].map(([label, value, tone]) => (
                                        <div key={label}>
                                            <dd className={`text-lg font-extrabold tabular-nums leading-none ${tone}`}>{value}</dd>
                                            <dt className="text-[10px] uppercase tracking-wider text-gray-400 mt-0.5">{label}</dt>
                                        </div>
                                    ))}
                                </dl>
                            </Link>
                        ))}
                    </div>
                )}
            </div>

            <Modal show={creating} onClose={() => setCreating(false)} maxWidth="md">
                <form onSubmit={create} className="p-6">
                    <h2 className="text-lg font-bold text-gray-900">Nuevo workflow</h2>
                    <p className="text-xs text-gray-500 mt-1">Nace inactivo. Nada se ejecuta hasta que lo actives.</p>

                    <input
                        autoFocus
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        placeholder="Ej.: Recuperar leads fríos"
                        className="mt-4 w-full text-sm border-gray-200 rounded-xl focus:ring-[#045474] focus:border-[#045474]"
                    />
                    {form.errors.name && <p className="text-xs text-rose-600 mt-1">{form.errors.name}</p>}

                    <div className="flex justify-end gap-2 mt-5">
                        <button type="button" onClick={() => setCreating(false)} className="px-4 py-2 rounded-xl text-sm font-semibold bg-white border border-gray-200 text-gray-700 hover:bg-gray-50">
                            Cancelar
                        </button>
                        <button type="submit" disabled={form.processing} className="px-4 py-2 rounded-xl text-sm font-semibold bg-gradient-to-r from-[#045474] to-[#1c486c] text-white shadow-lg disabled:opacity-50">
                            Crear
                        </button>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
