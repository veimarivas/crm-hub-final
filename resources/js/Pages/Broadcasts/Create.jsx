import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export default function Create({ segments }) {
    const [preview, setPreview] = useState({ count: null, sample: [], loading: false });
    const [imagePreview, setImagePreview] = useState(null);
    const form = useForm({
        name: '',
        message: '',
        segment_id: '',
        filters: {},
        image: null,
    });

    // Cuando cambia el segment, cargamos los filtros y hacemos preview
    useEffect(() => {
        if (!form.data.segment_id) {
            form.setData('filters', {});
            setPreview({ count: null, sample: [], loading: false });
            return;
        }
        const seg = segments.find((s) => s.id === form.data.segment_id);
        if (!seg) return;
        form.setData('filters', seg.filters || {});
    }, [form.data.segment_id]);

    const handleImage = (e) => {
        const file = e.target.files?.[0];
        if (!file) return;
        form.setData('image', file);
        setImagePreview(URL.createObjectURL(file));
    };

    const clearImage = () => {
        form.setData('image', null);
        setImagePreview(null);
    };

    // Preview cada vez que cambian los filtros
    useEffect(() => {
        if (Object.keys(form.data.filters || {}).length === 0 && !form.data.segment_id) return;
        setPreview((p) => ({ ...p, loading: true }));
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        fetch(route('broadcasts.preview'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ filters: form.data.filters }),
        })
            .then((r) => r.json())
            .then((d) => setPreview({ count: d.count, sample: d.sample || [], loading: false }))
            .catch(() => setPreview({ count: 0, sample: [], loading: false }));
    }, [form.data.filters]);

    const submit = (e) => {
        e.preventDefault();
        form.post(route('broadcasts.store'));
    };

    const inputClass = 'w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 focus:bg-white transition-all';

    return (
        <AuthenticatedLayout>
            <Head title="Nuevo broadcast" />
            <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-5">
                <div>
                    <Link href={route('broadcasts.index')} className="text-sm text-emerald-600 hover:text-emerald-700 font-medium inline-flex items-center gap-1">← Volver</Link>
                    <h1 className="text-2xl sm:text-3xl font-bold text-gray-900 mt-2">Nuevo broadcast</h1>
                    <p className="text-sm text-gray-500 mt-1">Enviá un mensaje de WhatsApp a un segmento de leads</p>
                </div>

                <form onSubmit={submit} className="space-y-5">
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 sm:p-6 space-y-4">
                        <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Nombre interno</label>
                            <input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} maxLength={150} required placeholder="ej. Recordatorio alumnos MBA Enero" className={inputClass} />
                            {form.errors.name && <p className="mt-1 text-xs text-red-500 font-medium">{form.errors.name}</p>}
                        </div>

                        <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Destinatarios (lista guardada)</label>
                            <select value={form.data.segment_id} onChange={(e) => form.setData('segment_id', e.target.value)} className={inputClass}>
                                <option value="">— Elegí una lista —</option>
                                {segments.map((s) => <option key={s.id} value={s.id}>📋 {s.name}</option>)}
                            </select>
                            <p className="text-[11px] text-gray-400 mt-1.5">
                                {segments.length === 0
                                    ? 'Sin listas todavía — creá una desde Leads → aplicá filtros → botón "💾 Guardar".'
                                    : 'Los filtros de la lista se aplican a leads abiertos con teléfono. Contactos duplicados reciben un solo mensaje.'
                                }
                            </p>
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
                                    <button type="button" onClick={clearImage} className="px-3 py-1.5 text-xs font-semibold text-red-600 bg-red-50 border border-red-200 rounded-lg hover:bg-red-100">Quitar</button>
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
                                placeholder="¡Hola! Te contactamos para..."
                                className={inputClass}
                            />
                            <p className="text-[11px] text-gray-400 mt-1 text-right tabular-nums">{form.data.message.length} / 4000</p>
                            {form.errors.message && <p className="mt-1 text-xs text-red-500 font-medium">{form.errors.message}</p>}
                        </div>
                    </div>

                    {/* Preview */}
                    {preview.count !== null && (
                        <div className={`rounded-2xl border shadow-sm p-4 ${preview.count === 0 ? 'bg-red-50 border-red-200' : 'bg-emerald-50 border-emerald-200'}`}>
                            <div className="flex items-center gap-3">
                                <div className={`w-11 h-11 rounded-xl flex items-center justify-center text-white shadow ${preview.count === 0 ? 'bg-red-500' : 'bg-emerald-500'}`}>
                                    <span className="text-lg font-bold">{preview.loading ? '…' : preview.count}</span>
                                </div>
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-bold text-gray-900">
                                        {preview.count === 0 ? 'Sin destinatarios' : `${preview.count} destinatario${preview.count !== 1 ? 's' : ''} recibirán este mensaje`}
                                    </p>
                                    {preview.sample.length > 0 && (
                                        <p className="text-[11px] text-gray-500 mt-0.5 truncate">
                                            Ej: {preview.sample.map((s) => s.name || s.phone).slice(0, 3).join(', ')}
                                            {preview.count > 3 && ` … y ${preview.count - 3} más`}
                                        </p>
                                    )}
                                </div>
                            </div>
                        </div>
                    )}

                    <div className="rounded-xl bg-amber-50 border border-amber-200 p-4 text-xs text-amber-800">
                        <p className="font-bold mb-1">⚠ Ventana de 24h de Meta</p>
                        <p>WhatsApp Business API solo permite mensajes de texto libre a contactos que escribieron en las últimas 24h. Fuera de esa ventana necesitás templates aprobados (esta versión aún no los soporta). Se envía texto simple o imagen con caption; los fallos aparecen en el detalle del broadcast.</p>
                    </div>

                    <div className="flex justify-end gap-3">
                        <Link href={route('broadcasts.index')} className="px-4 py-2.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 shadow-sm">Cancelar</Link>
                        <button
                            type="submit"
                            disabled={form.processing || preview.count === 0 || !form.data.segment_id}
                            className="px-5 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:opacity-90 disabled:opacity-40 disabled:cursor-not-allowed shadow-lg shadow-emerald-500/20 inline-flex items-center gap-2"
                        >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" /></svg>
                            {form.processing ? 'Enviando…' : `Enviar a ${preview.count ?? 0}`}
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
