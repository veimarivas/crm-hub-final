import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

/** Cuánto le queda de ventana, en palabras. */
function restante(win) {
    if (!win?.is_open) return 'Cerrada';
    const h = Math.floor(win.remaining_seconds / 3600);
    if (h >= 24) return `${Math.floor(h / 24)}d ${h % 24}h`;
    if (h >= 1) return `${h}h`;

    return `${Math.max(1, Math.round(win.remaining_seconds / 60))}m`;
}

function VentanaBadge({ win }) {
    if (!win?.is_open) {
        return (
            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-red-50 text-red-700 ring-1 ring-red-200 whitespace-nowrap">
                ✕ Fuera de ventana
            </span>
        );
    }

    const tone = win.is_expiring
        ? 'bg-amber-50 text-amber-700 ring-amber-200'
        : 'bg-emerald-50 text-emerald-700 ring-emerald-200';

    return (
        <span
            className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold ring-1 whitespace-nowrap ${tone}`}
            title={win.source === 'meta_ad' ? 'Ventana de 72 h por anuncio Click-to-WhatsApp' : 'Ventana de 24 h desde su último mensaje'}
        >
            {win.source === 'meta_ad' ? '📣' : '💬'} {restante(win)}
        </span>
    );
}

const money = (usd) => `US$ ${usd.toFixed(2)}`;

export default function Create({ segments, tags = [], members = [], pricing }) {
    const [data, setData] = useState({ count: null, in_window: 0, out_of_window: 0, recipients: [], truncated: false, cost_out_of_window: null, loading: false });
    const [imagePreview, setImagePreview] = useState(null);
    const [excluidos, setExcluidos] = useState(() => new Set()); // ids destildados a mano
    const [incluidosFuera, setIncluidosFuera] = useState(() => new Set()); // fuera de ventana tildados a mano
    const [busqueda, setBusqueda] = useState('');

    const form = useForm({
        name: '',
        message: '',
        segment_id: '',
        filters: {},
        lead_ids: [],
        image: null,
    });

    // El filtro por etiqueta es el camino corto: «los Nuevos», «los del MBA».
    const [tagsElegidas, setTagsElegidas] = useState(() => {
        const url = new URLSearchParams(window.location.search);
        const t = url.get('tag');

        return new Set(t ? [t] : []);
    });
    const [responsable, setResponsable] = useState('');

    // Los filtros salen de lo que se elige arriba; una lista guardada los pisa.
    useEffect(() => {
        if (form.data.segment_id) {
            const seg = segments.find((s) => s.id === form.data.segment_id);
            form.setData('filters', seg?.filters || {});

            return;
        }
        form.setData('filters', {
            tags: Array.from(tagsElegidas),
            responsible: responsable || null,
        });
    }, [form.data.segment_id, tagsElegidas, responsable]);

    // Trae la lista completa cada vez que cambian los filtros.
    useEffect(() => {
        const f = form.data.filters || {};
        const vacio = !f.tags?.length && !f.responsible && !f.tag && !f.source && !f.q;
        if (vacio && !form.data.segment_id) {
            setData({ count: null, in_window: 0, out_of_window: 0, recipients: [], truncated: false, cost_out_of_window: null, loading: false });

            return;
        }

        setData((p) => ({ ...p, loading: true }));
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        fetch(route('broadcasts.preview'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ filters: f }),
        })
            .then((r) => r.json())
            .then((d) => {
                setData({ ...d, loading: false });
                // Cada vez que cambia el universo se vuelve al criterio por
                // defecto: solo los que están en ventana.
                setExcluidos(new Set());
                setIncluidosFuera(new Set());
            })
            .catch(() => setData({ count: 0, in_window: 0, out_of_window: 0, recipients: [], truncated: false, cost_out_of_window: null, loading: false }));
    }, [JSON.stringify(form.data.filters)]);

    const seleccionado = (r) => (r.window.is_open ? !excluidos.has(r.lead_id) : incluidosFuera.has(r.lead_id));

    const toggle = (r) => {
        if (r.window.is_open) {
            setExcluidos((prev) => {
                const n = new Set(prev);
                n.has(r.lead_id) ? n.delete(r.lead_id) : n.add(r.lead_id);

                return n;
            });
        } else {
            setIncluidosFuera((prev) => {
                const n = new Set(prev);
                n.has(r.lead_id) ? n.delete(r.lead_id) : n.add(r.lead_id);

                return n;
            });
        }
    };

    const elegidos = useMemo(() => data.recipients.filter(seleccionado), [data.recipients, excluidos, incluidosFuera]);
    const elegidosFuera = elegidos.filter((r) => !r.window.is_open);
    const costo = elegidosFuera.length * (pricing?.rates?.marketing ?? 0);

    const visibles = data.recipients.filter((r) => {
        const t = busqueda.trim().toLowerCase();
        if (!t) return true;

        return (r.name || '').toLowerCase().includes(t) || (r.phone || '').includes(t);
    });

    const handleImage = (e) => {
        const file = e.target.files?.[0];
        if (!file) return;
        form.setData('image', file);
        setImagePreview(URL.createObjectURL(file));
    };

    const submit = (e) => {
        e.preventDefault();

        // OJO: `transform()` no devuelve el form en @inertiajs/react v2, así
        // que encadenarle `.post()` revienta con un TypeError y el botón no
        // hace absolutamente nada. Van en dos sentencias.
        form.transform((d) => ({ ...d, lead_ids: elegidos.map((r) => r.lead_id) }));
        form.post(route('broadcasts.store'), { preserveScroll: true });
    };

    const inputClass = 'w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 focus:bg-white transition-all';

    return (
        <AuthenticatedLayout>
            <Head title="Nuevo broadcast" />
            <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-5">
                <div>
                    <Link href={route('broadcasts.index')} className="text-sm text-emerald-600 hover:text-emerald-700 font-medium inline-flex items-center gap-1">← Volver</Link>
                    <h1 className="text-2xl sm:text-3xl font-bold text-gray-900 mt-2">Nuevo envío masivo</h1>
                    <p className="text-sm text-gray-500 mt-1">Elegí a quién le llega, revisá la lista y recién ahí enviá.</p>
                </div>

                <form onSubmit={submit} className="space-y-5">
                    {/* Paso 1 — a quién */}
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 sm:p-6 space-y-4">
                        <h2 className="text-sm font-bold text-gray-900 flex items-center gap-2">
                            <span className="w-6 h-6 rounded-lg bg-emerald-100 text-emerald-700 inline-flex items-center justify-center text-xs font-black">1</span>
                            ¿A quién?
                        </h2>

                        <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Etiquetas</label>
                            <div className="flex flex-wrap gap-1.5">
                                {tags.map((t) => {
                                    const activa = tagsElegidas.has(t.id);

                                    return (
                                        <button
                                            key={t.id}
                                            type="button"
                                            disabled={!!form.data.segment_id}
                                            onClick={() => setTagsElegidas((prev) => {
                                                const n = new Set(prev);
                                                n.has(t.id) ? n.delete(t.id) : n.add(t.id);

                                                return n;
                                            })}
                                            className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-bold transition-all disabled:opacity-40 ${activa ? 'text-white shadow-md' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'}`}
                                            style={activa ? { backgroundColor: t.color } : {}}
                                        >
                                            {t.name}
                                            <span className={`text-[10px] font-medium ${activa ? 'text-white/70' : 'text-gray-400'}`}>{t.leads_count}</span>
                                        </button>
                                    );
                                })}
                                {tags.length === 0 && (
                                    <Link href={route('tags.index')} className="text-xs text-emerald-600 hover:underline">Todavía no hay etiquetas — creá una →</Link>
                                )}
                            </div>
                            {tagsElegidas.size > 1 && (
                                <p className="text-[11px] text-gray-400 mt-1.5">Con varias etiquetas entra quien tenga <strong>al menos una</strong>.</p>
                            )}
                        </div>

                        <div className="grid sm:grid-cols-2 gap-4">
                            <div>
                                <label className="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Responsable</label>
                                <select value={responsable} disabled={!!form.data.segment_id} onChange={(e) => setResponsable(e.target.value)} className={inputClass}>
                                    <option value="">Todos</option>
                                    <option value="none">Sin asignar</option>
                                    {members.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5">…o una lista guardada</label>
                                <select value={form.data.segment_id} onChange={(e) => form.setData('segment_id', e.target.value)} className={inputClass}>
                                    <option value="">— Ninguna —</option>
                                    {segments.map((s) => <option key={s.id} value={s.id}>📋 {s.name}</option>)}
                                </select>
                            </div>
                        </div>
                    </div>

                    {/* Paso 2 — la lista */}
                    {data.count !== null && (
                        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                            <div className="p-5 sm:p-6 pb-4 space-y-3">
                                <h2 className="text-sm font-bold text-gray-900 flex items-center gap-2">
                                    <span className="w-6 h-6 rounded-lg bg-emerald-100 text-emerald-700 inline-flex items-center justify-center text-xs font-black">2</span>
                                    Revisá la lista
                                    {data.loading && <span className="text-xs font-medium text-gray-400">actualizando…</span>}
                                </h2>

                                <div className="flex flex-wrap items-center gap-2 text-xs">
                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-emerald-50 text-emerald-700 font-bold ring-1 ring-emerald-200">
                                        {data.in_window} en ventana · gratis
                                    </span>
                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-red-50 text-red-700 font-bold ring-1 ring-red-200">
                                        {data.out_of_window} fuera de ventana
                                    </span>
                                    <span className="text-gray-400">de {data.count} con teléfono</span>

                                    <div className="ml-auto flex items-center gap-2">
                                        <button
                                            type="button"
                                            onClick={() => { setExcluidos(new Set()); setIncluidosFuera(new Set()); }}
                                            className="px-2.5 py-1 rounded-lg font-semibold text-emerald-700 bg-emerald-50 hover:bg-emerald-100"
                                        >
                                            Solo los que están en ventana
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => { setExcluidos(new Set()); setIncluidosFuera(new Set(data.recipients.filter((r) => !r.window.is_open).map((r) => r.lead_id))); }}
                                            className="px-2.5 py-1 rounded-lg font-semibold text-gray-600 bg-gray-100 hover:bg-gray-200"
                                        >
                                            Marcar todos
                                        </button>
                                    </div>
                                </div>

                                <input
                                    value={busqueda}
                                    onChange={(e) => setBusqueda(e.target.value)}
                                    placeholder="Buscar en la lista por nombre o teléfono…"
                                    className="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:bg-white"
                                />
                            </div>

                            <ul className="divide-y divide-gray-50 max-h-96 overflow-y-auto border-t border-gray-100">
                                {visibles.map((r) => {
                                    const marcado = seleccionado(r);
                                    const fuera = !r.window.is_open;

                                    return (
                                        <li
                                            key={r.lead_id}
                                            className={`flex items-center gap-3 px-4 sm:px-6 py-2.5 transition-colors ${marcado ? (fuera ? 'bg-red-50/50' : 'bg-emerald-50/40') : 'opacity-60'}`}
                                        >
                                            <input
                                                type="checkbox"
                                                checked={marcado}
                                                onChange={() => toggle(r)}
                                                className={`w-4 h-4 rounded border-gray-300 shrink-0 focus:ring-2 ${fuera ? 'text-red-600 focus:ring-red-500' : 'text-emerald-600 focus:ring-emerald-500'}`}
                                            />
                                            <div className="min-w-0 flex-1">
                                                <p className="text-sm font-semibold text-gray-900 truncate">{r.name || r.phone}</p>
                                                <p className="text-[11px] text-gray-500 font-mono">{r.phone}</p>
                                            </div>
                                            <div className="hidden sm:flex flex-wrap gap-1 max-w-[180px] justify-end">
                                                {r.tags.slice(0, 2).map((t) => (
                                                    <span key={t.id} className="text-[9px] font-bold px-1.5 py-0.5 rounded-full text-white" style={{ backgroundColor: t.color }}>{t.name}</span>
                                                ))}
                                            </div>
                                            <VentanaBadge win={r.window} />
                                        </li>
                                    );
                                })}
                                {visibles.length === 0 && (
                                    <li className="px-6 py-10 text-center text-sm text-gray-400">Nadie coincide con esos filtros.</li>
                                )}
                            </ul>

                            {data.truncated && (
                                <p className="px-6 py-2 text-[11px] text-gray-500 bg-gray-50 border-t border-gray-100">
                                    Se muestran los primeros 500 de {data.count}. Afiná los filtros para revisarlos todos.
                                </p>
                            )}
                        </div>
                    )}

                    {/* Aviso de costo — solo cuando de verdad hay algo que cobrar */}
                    {elegidosFuera.length > 0 && (
                        <div className="rounded-2xl border border-red-200 bg-red-50 p-4 sm:p-5 space-y-2">
                            <p className="text-sm font-bold text-red-900">
                                ⚠ {elegidosFuera.length} de tus destinatarios están fuera de la ventana
                            </p>
                            <p className="text-xs text-red-800 leading-relaxed">
                                A ellos <strong>este mensaje de texto libre no les va a llegar</strong>: pasadas las 24 h desde su último
                                mensaje (o 72 h si vinieron de un anuncio), Meta solo entrega <strong>plantillas aprobadas</strong> y esas
                                se facturan. Van a figurar como fallidos en el detalle del envío.
                            </p>
                            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 pt-1 text-xs text-red-900">
                                <span>
                                    Si los mandaras con plantilla de marketing:
                                    <strong className="ml-1">{money(costo)}</strong>
                                    <span className="text-red-700"> (Bs. {(costo * (pricing?.bob_per_usd ?? 0)).toFixed(2)})</span>
                                </span>
                                <span className="text-red-700">
                                    {elegidosFuera.length} × US$ {(pricing?.rates?.marketing ?? 0).toFixed(4)} c/u
                                </span>
                            </div>
                            <p className="text-[10px] text-red-700/80 pt-1">
                                Tarifa de {pricing?.country} para plantillas de marketing. Utilidad/autenticación: US$ {(pricing?.rates?.utility ?? 0).toFixed(4)}.
                                Meta actualiza sus tarifas cada trimestre — esto es una estimación. Fuente: {pricing?.source}.
                            </p>
                        </div>
                    )}

                    {/* Paso 3 — qué se manda */}
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 sm:p-6 space-y-4">
                        <h2 className="text-sm font-bold text-gray-900 flex items-center gap-2">
                            <span className="w-6 h-6 rounded-lg bg-emerald-100 text-emerald-700 inline-flex items-center justify-center text-xs font-black">3</span>
                            ¿Qué les mandamos?
                        </h2>

                        <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Nombre interno</label>
                            <input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} maxLength={150} required placeholder="ej. Recordatorio alumnos MBA Enero" className={inputClass} />
                            {form.errors.name && <p className="mt-1 text-xs text-red-500 font-medium">{form.errors.name}</p>}
                        </div>

                        <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Imagen adjunta (opcional)</label>
                            {imagePreview ? (
                                <div className="flex items-center gap-3 rounded-xl border border-gray-200 p-3">
                                    <img src={imagePreview} alt="Vista previa" className="w-16 h-16 rounded-lg object-cover border border-gray-200 shadow-sm" />
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-medium text-gray-800 truncate">{form.data.image?.name}</p>
                                        <p className="text-[11px] text-gray-400">Se enviará junto al texto del mensaje.</p>
                                    </div>
                                    <button type="button" onClick={() => { form.setData('image', null); setImagePreview(null); }} className="px-3 py-1.5 text-xs font-semibold text-red-600 bg-red-50 border border-red-200 rounded-lg hover:bg-red-100">Quitar</button>
                                </div>
                            ) : (
                                <label className="block cursor-pointer rounded-xl border-2 border-dashed border-gray-300 p-5 text-center hover:border-emerald-400 hover:bg-emerald-50/30 transition-all">
                                    <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" onChange={handleImage} className="hidden" />
                                    <span className="text-sm text-gray-500">
                                        <span className="font-semibold text-emerald-600">📷 Adjuntar imagen</span>
                                        <span className="block text-[11px] text-gray-400 mt-0.5">JPG, PNG, WEBP o GIF · máx 10 MB</span>
                                    </span>
                                </label>
                            )}
                            {form.errors.image && <p className="mt-1 text-xs text-red-500 font-medium">{form.errors.image}</p>}
                        </div>

                        <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Mensaje</label>
                            <textarea
                                value={form.data.message}
                                onChange={(e) => form.setData('message', e.target.value)}
                                rows={5}
                                maxLength={4000}
                                required
                                placeholder="¡Hola! Te contactamos para…"
                                className={inputClass}
                            />
                            <p className="text-[11px] text-gray-400 mt-1 text-right tabular-nums">{form.data.message.length} / 4000</p>
                            {form.errors.message && <p className="mt-1 text-xs text-red-500 font-medium">{form.errors.message}</p>}
                        </div>
                    </div>

                    {/* Barra de envío */}
                    <div className="sticky bottom-4 z-20">
                        {/* Cualquier motivo por el que el envío no salga tiene
                            que verse acá: al lado del botón que se apretó. */}
                        {(form.errors.lead_ids || form.errors.name || form.errors.message || form.errors.image) && (
                            <div className="mb-2 rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-xs font-semibold text-red-700 shadow-sm">
                                {form.errors.lead_ids || form.errors.name || form.errors.message || form.errors.image}
                            </div>
                        )}
                        <div className="rounded-2xl border border-gray-200 bg-white shadow-xl p-4 flex flex-wrap items-center gap-3">
                            <div className="min-w-0">
                                <p className="text-sm font-bold text-gray-900">
                                    {elegidos.length} destinatario{elegidos.length === 1 ? '' : 's'} seleccionado{elegidos.length === 1 ? '' : 's'}
                                </p>
                                <p className="text-[11px] text-gray-500">
                                    {elegidos.length - elegidosFuera.length} en ventana (gratis)
                                    {elegidosFuera.length > 0 && <span className="text-red-600 font-semibold"> · {elegidosFuera.length} fuera ≈ {money(costo)} con plantilla</span>}
                                </p>
                            </div>
                            <div className="ml-auto flex items-center gap-3">
                                <Link href={route('broadcasts.index')} className="px-4 py-2.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 shadow-sm">Cancelar</Link>
                                <button
                                    type="submit"
                                    disabled={form.processing || elegidos.length === 0}
                                    className="px-5 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:opacity-90 disabled:opacity-40 disabled:cursor-not-allowed shadow-lg shadow-emerald-500/20 inline-flex items-center gap-2"
                                >
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" /></svg>
                                    {form.processing ? 'Enviando…' : `Enviar a ${elegidos.length}`}
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
