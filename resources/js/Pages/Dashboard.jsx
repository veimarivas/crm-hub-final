import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { DndContext, PointerSensor, closestCenter, useSensor, useSensors } from '@dnd-kit/core';
import { SortableContext, arrayMove, rectSortingStrategy, useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { SPAN, WIDGETS } from '@/Components/Dashboard/widgets';

/**
 * Dashboard por widgets.
 *
 * Dos modos bien separados: **ver** (la grilla normal, sin nada que estorbe) y
 * **acomodar** (arrastrar, cambiar tamaño, prender y apagar). Mezclarlos —dejar
 * los controles de edición siempre visibles— llena de ruido la pantalla que más
 * se mira en el día.
 *
 * Los datos vienen SOLO de los widgets visibles: el servidor no calcula lo que
 * está apagado. Por eso al prender uno hay que recargar; no hay payload
 * escondido esperando.
 */

const SIZE_LABELS = { sm: 'Chico', md: 'Medio', lg: 'Grande', full: 'Ancho completo' };

function SortableWidget({ item, children, onSize, onHide }) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: item.widget_key });

    return (
        <div
            ref={setNodeRef}
            style={{ transform: CSS.Transform.toString(transform), transition }}
            className={`${SPAN[item.size] ?? SPAN.md} ${isDragging ? 'opacity-50 z-10' : ''}`}
        >
            <div className={`relative rounded-2xl ${item.is_visible ? '' : 'opacity-40'} ring-2 ring-dashed ring-[#045474]/30 p-1.5`}>
                <div className="flex items-center gap-1.5 px-1 pb-1.5">
                    <button
                        type="button"
                        {...attributes}
                        {...listeners}
                        title="Arrastrar para reordenar"
                        className="cursor-grab active:cursor-grabbing p-1 text-gray-400 hover:text-gray-700 rounded"
                    >
                        <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M7 4a1 1 0 112 0 1 1 0 01-2 0zm0 6a1 1 0 112 0 1 1 0 01-2 0zm0 6a1 1 0 112 0 1 1 0 01-2 0zm5-12a1 1 0 112 0 1 1 0 01-2 0zm0 6a1 1 0 112 0 1 1 0 01-2 0zm0 6a1 1 0 112 0 1 1 0 01-2 0z" />
                        </svg>
                    </button>

                    <select
                        value={item.size}
                        onChange={(e) => onSize(e.target.value)}
                        className="text-[11px] font-semibold border-gray-200 rounded-lg py-0.5 pl-2 pr-6 text-gray-600 focus:ring-[#045474] focus:border-[#045474]"
                    >
                        {Object.entries(SIZE_LABELS).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
                    </select>

                    <button
                        type="button"
                        onClick={onHide}
                        className={`ml-auto text-[11px] font-bold px-2 py-1 rounded-lg transition-colors ${
                            item.is_visible ? 'text-gray-500 hover:bg-gray-100' : 'text-emerald-700 bg-emerald-50 hover:bg-emerald-100'
                        }`}
                    >
                        {item.is_visible ? 'Ocultar' : 'Mostrar'}
                    </button>
                </div>

                <div className="pointer-events-none">{children}</div>
            </div>
        </div>
    );
}

export default function Dashboard({ layout = [], widgets = {}, catalog = [], isAdmin }) {
    const [editing, setEditing] = useState(false);
    const [items, setItems] = useState(layout);
    const [saving, setSaving] = useState(false);

    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 5 } }));

    // Widgets del catálogo que el usuario todavía no tiene en su tablero.
    const missing = useMemo(
        () => catalog.filter((c) => !items.some((i) => i.widget_key === c.key)),
        [catalog, items],
    );

    const startEditing = () => { setItems(layout); setEditing(true); };

    const cancelEditing = () => { setItems(layout); setEditing(false); };

    const setSize = (key, size) => setItems((prev) => prev.map((i) => (i.widget_key === key ? { ...i, size } : i)));

    const toggleVisible = (key) => setItems((prev) => prev.map((i) => (i.widget_key === key ? { ...i, is_visible: !i.is_visible } : i)));

    const addWidget = (entry) => setItems((prev) => [...prev, {
        widget_key: entry.key, size: entry.defaultSize, is_visible: true, position: prev.length, config: null,
    }]);

    const onDragEnd = ({ active, over }) => {
        if (!over || active.id === over.id) return;
        setItems((prev) => {
            const from = prev.findIndex((i) => i.widget_key === active.id);
            const to = prev.findIndex((i) => i.widget_key === over.id);

            return arrayMove(prev, from, to);
        });
    };

    const save = () => {
        setSaving(true);
        router.patch(route('dashboard.layout'), {
            widgets: items.map(({ widget_key, size, is_visible }) => ({ widget_key, size, is_visible })),
        }, {
            // Recarga completa a propósito: al prender un widget su payload no
            // existe todavía en el cliente, porque el servidor no lo calculó.
            onSuccess: () => setEditing(false),
            onFinish: () => setSaving(false),
        });
    };

    const reset = () => router.delete(route('dashboard.layout.reset'), { onSuccess: () => setEditing(false) });

    const visible = items.filter((i) => i.is_visible);

    return (
        <AuthenticatedLayout>
            <Head title="Dashboard" />

            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Dashboard</h1>
                        <p className="text-sm text-gray-500 mt-1">
                            {editing ? 'Arrastrá para reordenar, elegí el tamaño y prendé lo que quieras ver.' : 'Tu embudo de ventas de un vistazo'}
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        {editing ? (
                            <>
                                <button onClick={reset} className="px-4 py-2 rounded-xl text-sm font-semibold bg-white border border-gray-200 text-gray-600 hover:bg-gray-50 shadow-sm">
                                    Restaurar por defecto
                                </button>
                                <button onClick={cancelEditing} className="px-4 py-2 rounded-xl text-sm font-semibold bg-white border border-gray-200 text-gray-700 hover:bg-gray-50 shadow-sm">
                                    Cancelar
                                </button>
                                <button onClick={save} disabled={saving} className="px-4 py-2 rounded-xl text-sm font-semibold bg-gradient-to-r from-[#045474] to-[#1c486c] text-white shadow-lg shadow-[#045474]/20 hover:opacity-90 disabled:opacity-50">
                                    {saving ? 'Guardando…' : 'Guardar tablero'}
                                </button>
                            </>
                        ) : (
                            <>
                                <Link href={route('leads.index')} className="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold bg-gradient-to-r from-[#045474] to-[#1c486c] text-white shadow-lg shadow-[#045474]/20 hover:opacity-90">
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                    Nuevo lead
                                </Link>
                                <Link href={route('tasks.index')} className="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold bg-white border border-gray-200 text-gray-700 hover:bg-gray-50 shadow-sm">
                                    Agenda
                                </Link>
                                <button onClick={startEditing} title="Personalizar tablero" className="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold bg-white border border-gray-200 text-gray-700 hover:bg-gray-50 shadow-sm">
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527a1.125 1.125 0 011.45.12l.773.774c.39.389.44 1.002.12 1.45l-.527.737c-.25.35-.272.806-.107 1.204.165.397.505.71.93.78l.893.15c.543.09.94.559.94 1.109v1.094c0 .55-.397 1.02-.94 1.11l-.893.149c-.425.07-.765.383-.93.78-.165.398-.143.854.107 1.204l.527.738c.32.447.269 1.06-.12 1.45l-.774.773a1.125 1.125 0 01-1.449.12l-.738-.527c-.35-.25-.806-.272-1.203-.107-.397.165-.71.505-.781.929l-.149.894c-.09.542-.56.94-1.11.94h-1.094c-.55 0-1.019-.398-1.11-.94l-.148-.894c-.071-.424-.384-.764-.781-.93-.398-.164-.854-.142-1.204.108l-.738.527c-.447.32-1.06.269-1.45-.12l-.773-.774a1.125 1.125 0 01-.12-1.45l.527-.737c.25-.35.273-.806.108-1.204-.165-.397-.505-.71-.93-.78l-.894-.15c-.542-.09-.94-.56-.94-1.109v-1.094c0-.55.398-1.02.94-1.11l.894-.149c.424-.07.765-.383.93-.78.165-.398.143-.854-.107-1.204l-.527-.738a1.125 1.125 0 01.12-1.45l.773-.773a1.125 1.125 0 011.45-.12l.737.527c.35.25.807.272 1.204.107.397-.165.71-.505.78-.929l.15-.894z" /><path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                    Personalizar
                                </button>
                            </>
                        )}
                    </div>
                </div>

                {editing && missing.length > 0 && (
                    <div className="bg-white rounded-2xl border border-dashed border-gray-300 p-4">
                        <p className="text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-2">Agregar al tablero</p>
                        <div className="flex flex-wrap gap-2">
                            {missing.map((entry) => (
                                <button
                                    key={entry.key}
                                    onClick={() => addWidget(entry)}
                                    title={entry.description}
                                    className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold bg-gray-50 text-gray-700 border border-gray-200 hover:bg-white hover:border-[#045474]/40 transition-colors"
                                >
                                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                    {entry.label}
                                </button>
                            ))}
                        </div>
                    </div>
                )}

                {editing ? (
                    <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
                        <SortableContext items={items.map((i) => i.widget_key)} strategy={rectSortingStrategy}>
                            <div className="grid grid-cols-1 lg:grid-cols-6 gap-5 items-start">
                                {items.map((item) => {
                                    const Widget = WIDGETS[item.widget_key];
                                    const data = widgets[item.widget_key];

                                    return (
                                        <SortableWidget
                                            key={item.widget_key}
                                            item={item}
                                            onSize={(size) => setSize(item.widget_key, size)}
                                            onHide={() => toggleVisible(item.widget_key)}
                                        >
                                            {/* Un widget recién agregado o apagado no tiene datos
                                                todavía: se muestra su silueta y se llena al guardar. */}
                                            {Widget && data ? (
                                                <Widget data={data} />
                                            ) : (
                                                <div className="bg-white rounded-2xl border border-gray-100 shadow-sm px-5 py-10 text-center">
                                                    <p className="text-sm font-semibold text-gray-500">
                                                        {catalog.find((c) => c.key === item.widget_key)?.label ?? item.widget_key}
                                                    </p>
                                                    <p className="text-xs text-gray-400 mt-1">Se carga al guardar el tablero</p>
                                                </div>
                                            )}
                                        </SortableWidget>
                                    );
                                })}
                            </div>
                        </SortableContext>
                    </DndContext>
                ) : (
                    <div className="grid grid-cols-1 lg:grid-cols-6 gap-5 items-start">
                        {visible.map((item) => {
                            const Widget = WIDGETS[item.widget_key];
                            const data = widgets[item.widget_key];
                            if (!Widget || !data) return null;

                            return (
                                <div key={item.widget_key} className={SPAN[item.size] ?? SPAN.md}>
                                    <Widget data={data} />
                                </div>
                            );
                        })}

                        {visible.length === 0 && (
                            <div className="lg:col-span-6 bg-white rounded-2xl border border-dashed border-gray-300 px-5 py-16 text-center">
                                <p className="text-sm font-semibold text-gray-500">Tu tablero está vacío</p>
                                <button onClick={startEditing} className="mt-3 px-4 py-2 rounded-xl text-sm font-semibold bg-gradient-to-r from-[#045474] to-[#1c486c] text-white shadow-lg">
                                    Elegir widgets
                                </button>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
