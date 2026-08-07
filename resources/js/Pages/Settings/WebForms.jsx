import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const inputClass = 'w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 focus:bg-white transition-all';

export default function WebForms({ forms, pipelines }) {
    const { flash } = usePage().props;
    const [copied, setCopied] = useState(null);

    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        pipeline_id: pipelines[0]?.id ?? '',
        headline: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('webforms.store'), { preserveScroll: true, onSuccess: () => reset('name', 'headline') });
    };

    const copy = (text, id) => {
        navigator.clipboard.writeText(text);
        setCopied(id);
        setTimeout(() => setCopied(null), 2000);
    };

    return (
        <AuthenticatedLayout>
            <Head title="Formularios web" />

            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <div>
                    <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Formularios web</h1>
                    <p className="text-sm text-gray-400 mt-1">Captura leads desde tu sitio — cada envío crea un contacto y un lead</p>
                </div>

                {flash?.success && (
                    <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 shadow-sm">{flash.success}</div>
                )}

                {/* Crear */}
                <form onSubmit={submit} className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 sm:p-6 space-y-4">
                    <h3 className="text-base font-bold text-gray-900">Nuevo formulario</h3>
                    <div className="grid lg:grid-cols-3 gap-4">
                        <div>
                            <label className="block text-sm font-semibold text-gray-700 mb-1.5">Nombre interno</label>
                            <input value={data.name} onChange={(e) => setData('name', e.target.value)} required placeholder="ej. Landing promoción" className={inputClass} />
                            {errors.name && <p className="mt-1 text-xs text-red-500 font-medium">{errors.name}</p>}
                        </div>
                        <div>
                            <label className="block text-sm font-semibold text-gray-700 mb-1.5">Pipeline destino</label>
                            <select value={data.pipeline_id} onChange={(e) => setData('pipeline_id', e.target.value)} className={inputClass}>
                                {pipelines.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="block text-sm font-semibold text-gray-700 mb-1.5">Título visible <span className="text-gray-400 font-normal">(opcional)</span></label>
                            <input value={data.headline} onChange={(e) => setData('headline', e.target.value)} placeholder="ej. Solicita tu cotización" className={inputClass} />
                        </div>
                    </div>
                    <button type="submit" disabled={processing} className="px-4 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 disabled:opacity-50 transition-all shadow-lg shadow-emerald-500/20">
                        Crear formulario
                    </button>
                </form>

                {/* Lista */}
                <div className="grid gap-4 lg:grid-cols-2 items-start">
                    {forms.map((form) => (
                        <div key={form.id} className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div className="flex items-center gap-3">
                                    <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-500 to-violet-600 flex items-center justify-center text-white shadow-lg shadow-purple-500/20">
                                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                        </svg>
                                    </div>
                                    <div>
                                        <p className="font-bold text-gray-900">{form.name}</p>
                                        <p className="text-xs text-gray-400">
                                            → {form.pipeline?.name} · <span className="font-semibold text-gray-600">{form.submissions_count}</span> envíos
                                        </p>
                                    </div>
                                </div>
                                <div className="flex items-center gap-2">
                                    <button
                                        onClick={() => router.post(route('webforms.toggle', form.id), {}, { preserveScroll: true })}
                                        className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold ring-1 transition-all ${
                                            form.is_active
                                                ? 'bg-emerald-50 text-emerald-700 ring-emerald-200 hover:bg-emerald-100'
                                                : 'bg-gray-100 text-gray-600 ring-gray-200 hover:bg-gray-200'
                                        }`}
                                    >
                                        <span className={`w-1.5 h-1.5 rounded-full ${form.is_active ? 'bg-emerald-500' : 'bg-gray-400'}`} />
                                        {form.is_active ? 'Activo' : 'Inactivo'}
                                    </button>
                                    <button
                                        onClick={() => { if (confirm('¿Eliminar este formulario?')) router.delete(route('webforms.destroy', form.id), { preserveScroll: true }); }}
                                        className="p-1.5 text-gray-300 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                                    >
                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166" /></svg>
                                    </button>
                                </div>
                            </div>

                            <div className="mt-4 space-y-2">
                                <div>
                                    <div className="flex items-center justify-between mb-1">
                                        <p className="text-[10px] font-bold uppercase tracking-wider text-gray-400">URL pública (compártela o enlázala)</p>
                                        <button onClick={() => copy(form.public_url, form.id + '-url')} className="text-xs text-emerald-600 hover:text-emerald-700 font-medium">
                                            {copied === form.id + '-url' ? '✓ Copiado' : 'Copiar'}
                                        </button>
                                    </div>
                                    <a href={form.public_url} target="_blank" rel="noreferrer" className="block px-3.5 py-2.5 bg-gray-50 border border-gray-200 rounded-xl font-mono text-xs text-emerald-700 hover:bg-gray-100 transition-colors break-all">
                                        {new URL(form.public_url).pathname}
                                    </a>
                                </div>
                                <div>
                                    <div className="flex items-center justify-between mb-1">
                                        <p className="text-[10px] font-bold uppercase tracking-wider text-gray-400">Snippet para embeber en tu web</p>
                                        <button
                                            onClick={() => copy(`<iframe src="${form.public_url}" style="width:100%;max-width:460px;height:560px;border:0;border-radius:20px;" title="${form.name}"></iframe>`, form.id + '-embed')}
                                            className="text-xs text-emerald-600 hover:text-emerald-700 font-medium"
                                        >
                                            {copied === form.id + '-embed' ? '✓ Copiado' : 'Copiar'}
                                        </button>
                                    </div>
                                    <code className="block px-3.5 py-2.5 bg-gray-900 text-gray-100 rounded-xl font-mono text-[10px] break-all">
                                        {`<iframe src="${form.public_url}" style="width:100%;max-width:460px;height:560px;border:0;border-radius:20px;"></iframe>`}
                                    </code>
                                </div>
                            </div>
                        </div>
                    ))}
                    {forms.length === 0 && (
                        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-14 text-center text-sm text-gray-400">
                            Sin formularios todavía — crea el primero arriba
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
