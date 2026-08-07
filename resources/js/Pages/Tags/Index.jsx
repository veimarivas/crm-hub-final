import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

function TagRow({ tag, palette, newLeadTag, onDelete }) {
    const [editing, setEditing] = useState(false);
    const form = useForm({ name: tag.name, color: tag.color });
    const usos = tag.leads_count + tag.contacts_count + tag.companies_count;
    const esAutomatica = tag.name.toLowerCase() === newLeadTag.toLowerCase();

    const guardar = (e) => {
        e.preventDefault();
        form.patch(route('tags.update', tag.id), { preserveScroll: true, onSuccess: () => setEditing(false) });
    };

    return (
        <div className="bg-white rounded-2xl border border-gray-100 p-4 flex flex-col gap-3 transition-shadow hover:shadow-md">
            <div className="flex items-center gap-2 min-w-0">
                <span className="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold text-white shadow-sm truncate max-w-[70%]" style={{ backgroundColor: tag.color }}>
                    {tag.name}
                </span>
                {esAutomatica && (
                    <span className="text-[10px] font-bold px-1.5 py-0.5 rounded bg-sky-50 text-sky-700 ring-1 ring-sky-200 shrink-0" title="Se agrega sola a cada lead que entra">
                        AUTOMÁTICA
                    </span>
                )}
            </div>

            <p className="text-[11px] text-gray-500 tabular-nums">
                <Link href={route('leads.index', { tag: tag.id })} className="hover:text-emerald-600 hover:underline font-semibold">
                    {tag.leads_count} lead{tag.leads_count === 1 ? '' : 's'}
                </Link>
                {tag.contacts_count > 0 && <span> · {tag.contacts_count} contactos</span>}
                {tag.companies_count > 0 && <span> · {tag.companies_count} empresas</span>}
            </p>

            {editing ? (
                <form onSubmit={guardar} className="space-y-2 rounded-xl bg-emerald-50/40 border border-emerald-100 p-3">
                    <input
                        autoFocus
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        maxLength={60}
                        className="w-full px-3 py-1.5 border border-emerald-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500/30"
                    />
                    <div className="flex items-center justify-between gap-2">
                        <div className="flex items-center gap-1 flex-wrap">
                            {palette.map((c) => (
                                <button
                                    key={c}
                                    type="button"
                                    onClick={() => form.setData('color', c)}
                                    className={`w-5 h-5 rounded-full transition-transform ${form.data.color === c ? 'ring-2 ring-offset-2 ring-gray-400 scale-110' : ''}`}
                                    style={{ backgroundColor: c }}
                                    title={c}
                                />
                            ))}
                        </div>
                        <div className="flex items-center gap-2">
                            <button type="button" onClick={() => { form.reset(); setEditing(false); }} className="text-xs text-gray-500 hover:text-gray-700 px-2">Cancelar</button>
                            <button type="submit" disabled={form.processing} className="px-3 py-1.5 rounded-lg text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50">Guardar</button>
                        </div>
                    </div>
                </form>
            ) : (
                <div className="flex items-center gap-2 mt-auto pt-3 border-t border-gray-50">
                    <Link
                        href={route('broadcasts.create', { tag: tag.id })}
                        className="px-2.5 py-1.5 rounded-lg text-xs font-semibold text-emerald-700 bg-emerald-50 hover:bg-emerald-100 inline-flex items-center gap-1"
                        title="Enviar un mensaje masivo a los leads con esta etiqueta"
                    >
                        📣 Difundir
                    </Link>
                    <div className="ml-auto flex items-center gap-1">
                        <button onClick={() => setEditing(true)} className="px-2.5 py-1.5 rounded-lg text-xs font-semibold text-gray-600 hover:bg-gray-100">Editar</button>
                        <button onClick={() => onDelete(tag, usos)} className="px-2.5 py-1.5 rounded-lg text-xs font-semibold text-red-600 hover:bg-red-50">Borrar</button>
                    </div>
                </div>
            )}
        </div>
    );
}

export default function Index({ tags, palette, newLeadTag }) {
    const { flash } = usePage().props;
    const form = useForm({ name: '', color: palette[0] });
    const [deleting, setDeleting] = useState(null);

    const crear = (e) => {
        e.preventDefault();
        if (!form.data.name.trim()) return;
        form.post(route('tags.store'), { preserveScroll: true, onSuccess: () => form.setData('name', '') });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Etiquetas" />

            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <div className="flex items-end justify-between gap-4 flex-wrap">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Etiquetas</h1>
                        <p className="text-sm text-gray-500 mt-1">
                            Sirven para agrupar leads y después difundirles un mensaje. Cada lead que entra nace con «{newLeadTag}».
                        </p>
                    </div>
                    <span className="text-xs font-semibold text-gray-400 tabular-nums">{tags.length} etiquetas</span>
                </div>

                {flash?.success && (
                    <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 shadow-sm">{flash.success}</div>
                )}

                <form onSubmit={crear} className="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 flex flex-wrap items-center gap-3">
                    <input
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        maxLength={60}
                        placeholder="Nombre de la etiqueta…"
                        className="flex-1 min-w-[180px] px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:bg-white"
                    />
                    <div className="flex items-center gap-1">
                        {palette.map((c) => (
                            <button
                                key={c}
                                type="button"
                                onClick={() => form.setData('color', c)}
                                className={`w-5 h-5 rounded-full transition-transform ${form.data.color === c ? 'ring-2 ring-offset-2 ring-gray-400 scale-110' : ''}`}
                                style={{ backgroundColor: c }}
                            />
                        ))}
                    </div>
                    <button
                        type="submit"
                        disabled={form.processing || !form.data.name.trim()}
                        className="px-4 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:opacity-90 disabled:opacity-40 shadow-lg shadow-emerald-500/20"
                    >
                        Crear
                    </button>
                    {form.errors.name && <p className="w-full text-xs text-red-500 font-medium">{form.errors.name}</p>}
                </form>

                <div>
                    {tags.length === 0 ? (
                        <div className="bg-white rounded-2xl border border-gray-100 p-14 text-center text-sm text-gray-400">Todavía no hay etiquetas.</div>
                    ) : (
                        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
                            {tags.map((t) => (
                                <TagRow key={t.id} tag={t} palette={palette} newLeadTag={newLeadTag} onDelete={(tag, usos) => setDeleting({ ...tag, usos })} />
                            ))}
                        </div>
                    )}
                </div>
            </div>

            <Modal show={!!deleting} onClose={() => setDeleting(null)} maxWidth="md">
                <div className="p-6">
                    <div className="flex items-start gap-4">
                        <div className="w-11 h-11 rounded-full bg-red-50 flex items-center justify-center text-red-600 shrink-0">
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                            </svg>
                        </div>
                        <div className="min-w-0 flex-1">
                            <h3 className="text-base font-bold text-gray-900">Borrar etiqueta</h3>
                            <p className="text-sm text-gray-500 mt-1">
                                ¿Borrar la etiqueta{' '}
                                <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold text-white" style={{ backgroundColor: deleting?.color }}>
                                    {deleting?.name}
                                </span>
                                ?
                            </p>
                            <p className="text-xs text-red-600 mt-2">
                                Se quitará de <strong>{deleting?.usos ?? 0}</strong> registro{deleting?.usos === 1 ? '' : 's'}.
                                Esta acción no se puede deshacer.
                            </p>
                        </div>
                    </div>
                    <div className="mt-6 flex justify-end gap-3">
                        <button onClick={() => setDeleting(null)} className="px-4 py-2.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-all shadow-sm">Cancelar</button>
                        <button
                            onClick={() => {
                                router.delete(route('tags.destroy', deleting.id), { preserveScroll: true, onSuccess: () => setDeleting(null) });
                            }}
                            className="px-5 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-500 rounded-xl transition-all shadow-lg shadow-red-500/20"
                        >
                            Sí, borrar
                        </button>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}