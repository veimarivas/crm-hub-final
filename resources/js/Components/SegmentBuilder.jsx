/**
 * Editor de criterios de segmento: árbol de condiciones con grupos Y/O.
 *
 * Compartido entre `/segments` y el constructor de workflows (inscripción,
 * meta y ramas). Es el mismo `SegmentQuery` del servidor de los dos lados, así
 * que la UI también tiene que ser una sola: si se duplicara, un criterio se
 * vería distinto según dónde se lo configure.
 */

import { useMemo } from 'react';

export const OP_LABELS = {
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

export const emptyDefinition = () => ({ version: 2, match: 'all', conditions: [] });

/** Valor inicial coherente con el operador, para no mandar `null` al servidor. */
function defaultValue(op) {
    if (['never', 'is_empty', 'is'].includes(op)) return true;
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

    const grouped = useMemo(() => {
        const out = {};
        catalog.forEach((f) => { (out[f.group] ??= []).push(f); });

        return out;
    }, [catalog]);

    if (!field) return null;

    const changeField = (nextKey) => {
        const next = catalog.find((f) => f.field === nextKey);
        const op = next.ops[0];
        onChange({ field: nextKey, op, value: defaultValue(op) });
    };

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
                onChange={(e) => onChange({ ...node, op: e.target.value, value: defaultValue(e.target.value) })}
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

export default function SegmentBuilder({ group, catalog = [], options = {}, onChange, onRemove, depth = 0 }) {
    const update = (i, node) => onChange({ ...group, conditions: group.conditions.map((c, j) => (j === i ? node : c)) });
    const remove = (i) => onChange({ ...group, conditions: group.conditions.filter((_, j) => j !== i) });

    const addCondition = () => {
        const first = catalog[0];
        if (!first) return;
        onChange({ ...group, conditions: [...group.conditions, { field: first.field, op: first.ops[0], value: defaultValue(first.ops[0]) }] });
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

            {(group.conditions ?? []).map((node, i) => (
                node.conditions ? (
                    <SegmentBuilder
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

            {(group.conditions ?? []).length === 0 && (
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

/** Resumen legible de una definición, para no tener que abrirla. */
export function describe(definition, catalog) {
    const parts = (definition?.conditions ?? []).map((node) => {
        if (node.conditions) return `(${describe(node, catalog)})`;
        const field = catalog.find((f) => f.field === node.field);

        return `${field?.label ?? node.field} ${OP_LABELS[node.op] ?? node.op}`;
    });

    if (parts.length === 0) return 'Todos los leads';

    return parts.join(definition.match === 'any' ? ' o ' : ' y ');
}
