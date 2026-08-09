import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import SegmentBuilder, { describe, emptyDefinition } from '@/Components/SegmentBuilder';
import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * Segmentos: audiencias definidas por una pregunta, no por una lista.
 *
 * El editor de criterios vive en `Components/SegmentBuilder` porque lo comparte
 * con el constructor de workflows (inscripción, meta y ramas): es el mismo
 * `SegmentQuery` del servidor, así que la UI también tiene que ser una sola.
 *
 * El **conteo en vivo** es lo que hace visible que el segmento sea dinámico —se
 * mueve un criterio y el número cambia— y la única defensa real contra guardar
 * una audiencia sin saber a cuántos alcanza.
 */

/** Conteo en vivo, con debounce para no consultar en cada tecla. */
function useLiveCount(definition) {
    const [count, setCount] = useState(null);
    const [error, setError] = useState(null);
    const serialized = JSON.stringify(definition);

    useEffect(() => {
        let cancelled = false;
        setError(null);

        const timer = setTimeout(() => {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            fetch(route('segments.count'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ filters: JSON.parse(serialized) }),
            })
                .then(async (r) => (r.ok ? r.json() : Promise.reject(await r.json())))
                .then((d) => { if (!cancelled) setCount(d); })
                .catch((e) => { if (!cancelled) { setCount(null); setError(e?.message ?? 'No se pudo calcular'); } });
        }, 400);

        return () => { cancelled = true; clearTimeout(timer); };
    }, [serialized]);

    return { count, error };
}

function SegmentModal({ segment, catalog, options, onClose }) {
    const editing = Boolean(segment?.id);
    const form = useForm({
        name: segment?.name ?? '',
        is_shared: segment?.is_shared ?? false,
        filters: segment?.definition ?? emptyDefinition(),
    });

    const { count, error } = useLiveCount(form.data.filters);

    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onClose };
        editing ? form.patch(route('segments.update', segment.id), opts) : form.post(route('segments.store'), opts);
    };

    return (
        <Modal show onClose={onClose} maxWidth="3xl">
            <form onSubmit={submit} className="p-6 space-y-5">
                <div>
                    <h2 className="text-lg font-bold text-gray-900">{editing ? 'Editar lista' : 'Nueva lista'}</h2>
                    <p className="text-xs text-gray-500 mt-0.5">
                        Se guarda la pregunta, no los leads: quien empiece a cumplirla entra solo.
                    </p>
                </div>

                <div className="flex flex-wrap gap-3">
                    <input
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        placeholder="Nombre de la lista"
                        className="flex-1 min-w-[14rem] text-sm border-gray-200 rounded-xl focus:ring-[#045474] focus:border-[#045474]"
                    />
                    <label className="inline-flex items-center gap-2 text-xs font-semibold text-gray-600">
                        <input
                            type="checkbox"
                            checked={form.data.is_shared}
                            onChange={(e) => form.setData('is_shared', e.target.checked)}
                            className="rounded border-gray-300 text-[#045474] focus:ring-[#045474]"
                        />
                        Compartir con el equipo
                    </label>
                </div>
                {form.errors.name && <p className="text-xs text-rose-600">{form.errors.name}</p>}

                <div className="bg-white rounded-2xl border border-gray-100 p-4">
                    <SegmentBuilder
                        group={form.data.filters}
                        catalog={catalog}
                        options={options}
                        onChange={(g) => form.setData('filters', { ...form.data.filters, ...g, version: 2 })}
                    />
                </div>

                <label className="inline-flex items-center gap-2 text-xs font-semibold text-gray-600">
                    <input
                        type="checkbox"
                        checked={form.data.filters.include_closed ?? false}
                        onChange={(e) => form.setData('filters', { ...form.data.filters, include_closed: e.target.checked })}
                        className="rounded border-gray-300 text-[#045474] focus:ring-[#045474]"
                    />
                    Incluir leads cerrados en los envíos
                </label>

                <div className="rounded-2xl bg-gradient-to-r from-[#045474]/5 to-transparent border border-[#045474]/15 px-4 py-3">
                    {error ? (
                        <p className="text-xs font-semibold text-rose-600">{error}</p>
                    ) : count ? (
                        <p className="text-sm text-gray-700">
                            <strong className="text-2xl font-extrabold text-[#045474] tabular-nums">{count.reachable}</strong>
                            {' '}alcanzables por WhatsApp ahora
                            <span className="text-gray-400"> · {count.open} abiertos · {count.total} en total</span>
                        </p>
                    ) : (
                        <p className="text-xs text-gray-400">Calculando…</p>
                    )}
                </div>
                {form.errors.filters && <p className="text-xs text-rose-600">{form.errors.filters}</p>}

                <div className="flex justify-end gap-2">
                    <button type="button" onClick={onClose} className="px-4 py-2 rounded-xl text-sm font-semibold bg-white border border-gray-200 text-gray-700 hover:bg-gray-50">
                        Cancelar
                    </button>
                    <button type="submit" disabled={form.processing} className="px-4 py-2 rounded-xl text-sm font-semibold bg-gradient-to-r from-[#045474] to-[#1c486c] text-white shadow-lg disabled:opacity-50">
                        {form.processing ? 'Guardando…' : 'Guardar lista'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

export default function SegmentsIndex({ segments = [], catalog = [], options = {} }) {
    const [editing, setEditing] = useState(null);
    const [confirmDelete, setConfirmDelete] = useState(null);

    return (
        <AuthenticatedLayout>
            <Head title="Listas" />

            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Listas</h1>
                        <p className="text-sm text-gray-500 mt-1">
                            Audiencias que se actualizan solas: se guarda la condición, no los leads.
                        </p>
                    </div>
                    <button
                        onClick={() => setEditing({})}
                        className="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold bg-gradient-to-r from-[#045474] to-[#1c486c] text-white shadow-lg hover:opacity-90"
                    >
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        Nueva lista
                    </button>
                </div>

                {segments.length === 0 ? (
                    <div className="bg-white rounded-2xl border border-dashed border-gray-300 px-5 py-16 text-center">
                        <p className="text-sm font-semibold text-gray-500">Todavía no hay listas</p>
                        <p className="text-xs text-gray-400 mt-1">Armá una y usala para difundir o para filtrar el tablero.</p>
                    </div>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {segments.map((s) => (
                            <div key={s.id} className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 flex flex-col">
                                <div className="flex items-start justify-between gap-2">
                                    <h3 className="text-base font-bold text-gray-900 min-w-0 truncate">{s.name}</h3>
                                    {s.is_shared && (
                                        <span className="shrink-0 text-[10px] font-bold px-2 py-0.5 rounded-full bg-sky-50 text-sky-700 ring-1 ring-sky-200">
                                            Compartida
                                        </span>
                                    )}
                                </div>
                                <p className="text-xs text-gray-500 mt-2 flex-1">{describe(s.definition, catalog)}</p>
                                {!s.is_mine && <p className="text-[11px] text-gray-400 mt-2">De {s.owner}</p>}

                                <div className="flex items-center gap-2 mt-4 pt-3 border-t border-gray-100">
                                    <button
                                        onClick={() => router.get(route('broadcasts.create'), { segment: s.id })}
                                        className="text-xs font-bold text-[#045474] hover:underline"
                                    >
                                        Difundir
                                    </button>
                                    {s.is_mine && (
                                        <>
                                            <button onClick={() => setEditing(s)} className="text-xs font-semibold text-gray-500 hover:text-gray-800">
                                                Editar
                                            </button>
                                            <button onClick={() => setConfirmDelete(s)} className="ml-auto text-xs font-semibold text-gray-400 hover:text-rose-600">
                                                Eliminar
                                            </button>
                                        </>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {editing && (
                <SegmentModal
                    segment={editing.id ? editing : null}
                    catalog={catalog}
                    options={options}
                    onClose={() => setEditing(null)}
                />
            )}

            <Modal show={Boolean(confirmDelete)} onClose={() => setConfirmDelete(null)} maxWidth="md">
                <div className="p-6">
                    <h2 className="text-lg font-bold text-gray-900">Eliminar «{confirmDelete?.name}»</h2>
                    <p className="text-sm text-gray-500 mt-2">
                        Los leads no se tocan: se borra la definición de la audiencia.
                    </p>
                    <div className="flex justify-end gap-2 mt-5">
                        <button onClick={() => setConfirmDelete(null)} className="px-4 py-2 rounded-xl text-sm font-semibold bg-white border border-gray-200 text-gray-700 hover:bg-gray-50">
                            Cancelar
                        </button>
                        <button
                            onClick={() => {
                                router.delete(route('segments.destroy', confirmDelete.id), {
                                    preserveScroll: true,
                                    onFinish: () => setConfirmDelete(null),
                                });
                            }}
                            className="px-4 py-2 rounded-xl text-sm font-semibold bg-rose-600 text-white hover:bg-rose-700"
                        >
                            Sí, eliminar
                        </button>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
