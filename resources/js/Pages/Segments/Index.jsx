import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

/**
 * Segmentos: audiencias definidas por una pregunta, no por una lista.
 *
 * El constructor muestra el **conteo en vivo** mientras se editan las
 * condiciones. Es lo que hace visible que el segmento sea dinámico —se mueve un
 * criterio y el número cambia— y la única defensa real contra guardar una
 * audiencia sin saber a cuántos alcanza.
 */

const OP_LABELS = {
    in: 'es alguno de',
    not_in: 'no es ninguno de',
    is_empty: 'está vacío',
    gte: 'es mayor o igual a',
    lte: 'es menor o igual a',
    eq: 'es igual a',
    contains: 'contiene',
    is: 'es',
    older_than: 'hace más de (días)',
    newer_than: 'hace menos de (días)',
    never: 'nunca ocurrió',
};

const emptyDefinition = () => ({ version: 2, match: 'all', conditions: [], include_closed: false });

/** Valor inicial coherente con el tipo, para no mandar `null` al servidor. */
function defaultValue(field, op) {
    if (op === 'never' || op === 'is_empty') return true;
    if (op === 'is') return true;
    if (['in', 'not_in'].includes(op)) return [];
    if (['older_than', 'newer_than', 'gte', 'lte'].includes(op)) return 7;

    return '';
}

function ValueInput({ field, condition, options, onChange }) {
    const { op, value } = condition;
    const choices = options[field.type] ?? null;

    if (op === 'never' || op === 'is_empty') return null;

    if (op === 'is') {
        return (
            <select
                value={value ? '1' : '0'}
                onChange={(e) => onChange(e.target.value === '1')}
                className="text-sm border-gray-200 rounded-xl py-1.5 focus:ring-[#045474] focus:border-[#045474]"
            >
                <option value="1">Sí</option>
                <option value="0">No</option>
            </select>
        );
    }

    if (choices && ['in', 'not_in'].includes(op)) {
        const selected = Array.isArray(value) ? value : [];

        return (
            <div className="flex flex-wrap gap-1.5">
                {choices.map((c) => {
                    const active = selected.includes(c.value);

                    return (
                        <button
                            key={c.value}
                            type="button"
                            onClick={() => onChange(active ? selected.filter((v) => v !== c.value) : [...selected, c.value])}
                            className={`px-2.5 py-1 rounded-lg text-xs font-semibold ring-1 transition-colors ${
                                active ? 'bg-[#045474] text-white ring-[#045474]' : 'bg-white text-gray-600 ring-gray-200 hover:bg-gray-50'
                            }`}
                            style={active && c.color ? { background: c.color, borderColor: c.color } : undefined}
                        >
                            {c.label}
                        </button>
                    );
                })}
                {choices.length === 0 && <span className="text-xs text-gray-400">Sin opciones disponibles</span>}
            </div>
        );
    }

    const numeric = ['gte', 'lte', 'older_than', 'newer_than'].includes(op);

    return (
        <input
            type={numeric ? 'number' : 'text'}
            value={value ?? ''}
            onChange={(e) => onChange(numeric ? Number(e.target.value) : e.target.value)}
            className="text-sm border-gray-200 rounded-xl py-1.5 w-40 focus:ring-[#045474] focus:border-[#045474]"
        />
    );
}

function ConditionRow({ node, catalog, options, onChange, onRemove }) {
    const field = catalog.find((f) => f.field === node.field);
    if (!field) return null;

    const changeField = (nextKey) => {
        const next = catalog.find((f) => f.field === nextKey);
        const op = next.ops[0];
        onChange({ field: nextKey, op, value: defaultValue(next, op) });
    };

    const changeOp = (op) => onChange({ ...node, op, value: defaultValue(field, op) });

    const grouped = useMemo(() => {
        const out = {};
        catalog.forEach((f) => { (out[f.group] ??= []).push(f); });

        return out;
    }, [catalog]);

    return (
        <div className="flex flex-wrap items-center gap-2 bg-gray-50 rounded-xl px-3 py-2">
            <select
                value={node.field}
                onChange={(e) => changeField(e.target.value)}
                className="text-sm font-semibold border-gray-200 rounded-xl py-1.5 focus:ring-[#045474] focus:border-[#045474]"
            >
                {Object.entries(grouped).map(([group, fields]) => (
                    <optgroup key={group} label={group}>
                        {fields.map((f) => <option key={f.field} value={f.field}>{f.label}</option>)}
                    </optgroup>
                ))}
            </select>

            <select
                value={node.op}
                onChange={(e) => changeOp(e.target.value)}
                className="text-sm border-gray-200 rounded-xl py-1.5 text-gray-600 focus:ring-[#045474] focus:border-[#045474]"
            >
                {field.ops.map((op) => <option key={op} value={op}>{OP_LABELS[op] ?? op}</option>)}
            </select>

            <ValueInput field={field} condition={node} options={options} onChange={(value) => onChange({ ...node, value })} />

            <button type="button" onClick={onRemove} title="Quitar condición" className="ml-auto p-1 text-gray-400 hover:text-rose-600">
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    );
}

function GroupEditor({ group, catalog, options, onChange, onRemove, depth = 0 }) {
    const update = (i, node) => onChange({ ...group, conditions: group.conditions.map((c, j) => (j === i ? node : c)) });
    const remove = (i) => onChange({ ...group, conditions: group.conditions.filter((_, j) => j !== i) });

    const addCondition = () => {
        const first = catalog[0];
        onChange({ ...group, conditions: [...group.conditions, { field: first.field, op: first.ops[0], value: defaultValue(first, first.ops[0]) }] });
    };

    const addGroup = () => onChange({ ...group, conditions: [...group.conditions, { match: 'any', conditions: [] }] });

    return (
        <div className={depth > 0 ? 'border-l-2 border-[#045474]/20 pl-3 space-y-2' : 'space-y-2'}>
            <div className="flex items-center gap-2">
                <span className="text-[11px] font-bold uppercase tracking-wider text-gray-400">Cumple</span>
                <select
                    value={group.match ?? 'all'}
                    onChange={(e) => onChange({ ...group, match: e.target.value })}
                    className="text-xs font-bold border-gray-200 rounded-lg py-1 focus:ring-[#045474] focus:border-[#045474]"
                >
                    <option value="all">todas las condiciones</option>
                    <option value="any">al menos una</option>
                </select>
                {onRemove && (
                    <button type="button" onClick={onRemove} className="ml-auto text-[11px] font-semibold text-gray-400 hover:text-rose-600">
                        Quitar grupo
                    </button>
                )}
            </div>

            {group.conditions.map((node, i) => (
                node.conditions ? (
                    <GroupEditor
                        key={i}
                        group={node}
                        catalog={catalog}
                        options={options}
                        depth={depth + 1}
                        onChange={(g) => update(i, g)}
                        onRemove={() => remove(i)}
                    />
                ) : (
                    <ConditionRow
                        key={i}
                        node={node}
                        catalog={catalog}
                        options={options}
                        onChange={(n) => update(i, n)}
                        onRemove={() => remove(i)}
                    />
                )
            ))}

            {group.conditions.length === 0 && (
                <p className="text-xs text-gray-400 px-1">Sin condiciones: alcanza a todos los leads.</p>
            )}

            <div className="flex gap-2">
                <button type="button" onClick={addCondition} className="text-xs font-bold text-[#045474] hover:underline">
                    + Condición
                </button>
                {depth < 3 && (
                    <button type="button" onClick={addGroup} className="text-xs font-bold text-gray-400 hover:text-gray-600">
                        + Grupo
                    </button>
                )}
            </div>
        </div>
    );
}

/** Conteo en vivo, con debounce para no consultar en cada tecla. */
function useLiveCount(definition, open) {
    const [count, setCount] = useState(null);
    const [error, setError] = useState(null);
    const serialized = JSON.stringify(definition);

    useEffect(() => {
        if (!open) return undefined;

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
    }, [serialized, open]);

    return { count, error };
}

function SegmentModal({ segment, catalog, options, onClose }) {
    const editing = Boolean(segment?.id);
    const form = useForm({
        name: segment?.name ?? '',
        is_shared: segment?.is_shared ?? false,
        filters: segment?.definition ?? emptyDefinition(),
    });

    const { count, error } = useLiveCount(form.data.filters, true);

    const submit = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        editing ? form.patch(route('segments.update', segment.id), options) : form.post(route('segments.store'), options);
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
                    <GroupEditor
                        group={form.data.filters}
                        catalog={catalog}
                        options={options}
                        onChange={(g) => form.setData('filters', { ...g, version: 2 })}
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

                {/* Lo que hace visible que el segmento sea dinámico. */}
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

/** Resumen legible de la definición, para no tener que abrir cada lista. */
function describe(definition, catalog) {
    const parts = (definition.conditions ?? []).map((node) => {
        if (node.conditions) return `(${describe(node, catalog)})`;
        const field = catalog.find((f) => f.field === node.field);

        return `${field?.label ?? node.field} ${OP_LABELS[node.op] ?? node.op}`;
    });

    if (parts.length === 0) return 'Todos los leads';

    return parts.join(definition.match === 'any' ? ' o ' : ' y ');
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
