import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

function TagRow({ tag, palette, newLeadTag }) {
    const [editing, setEditing] = useState(false);
    const form = useForm({ name: tag.name, color: tag.color });
    const usos = tag.leads_count + tag.contacts_count + tag.companies_count;
    const esAutomatica = tag.name.toLowerCase() === newLeadTag.toLowerCase();

    const guardar = (e) => {
        e.preventDefault();
        form.patch(route('tags.update', tag.id), { preserveScroll: true, onSuccess: () => setEditing(false) });
    };

    if (editing) {
        return (
            <form onSubmit={guardar} className="flex flex-wrap items-center gap-3 px-4 py-3 bg-emerald-50/40">
                <input
                    autoFocus
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    maxLength={60}
                    className="px-3 py-1.5 border border-emerald-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500/30"
                />
                <div className="flex items-center gap-1">
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
                <div className="flex items-center gap-2 ml-auto">
                    <button type="submit" disabled={form.processing} className="px-3 py-1.5 rounded-lg text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50">Guardar</button>
                    <button type="button" onClick={() => { form.reset(); setEditing(false); }} className="text-xs text-gray-500 hover:text-gray-700 px-2">Cancelar</button>
                </div>
            </form>
        );
    }

    return (
        <div className="group flex flex-wrap items-center gap-3 px-4 py-3 hover:bg-gray-50 transition-colors">
            <span className="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold text-white shadow-sm" style={{ backgroundColor: tag.color }}>
                {tag.name}
            </span>

            {esAutomatica && (
                <span className="text-[10px] font-bold px-1.5 py-0.5 rounded bg-sky-50 text-sky-700 ring-1 ring-sky-200" title="Se agrega sola a cada lead que entra">
                    AUTOMÁTICA
                </span>
            )}

            <div className="flex items-center gap-3 text-[11px] text-gray-500 tabular-nums">
                <Link href={route('leads.index', { tag: tag.id })} className="hover:text-emerald-600 hover:underline">
                    {tag.leads_count} lead{tag.leads_count === 1 ? '' : 's'}
                </Link>
                {tag.contacts_count > 0 && <span>{tag.contacts_count} contactos</span>}
                {tag.companies_count > 0 && <span>{tag.companies_count} empresas</span>}
            </div>

            <div className="flex items-center gap-1 ml-auto opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity">
                <Link
                    href={route('broadcasts.create', { tag: tag.id })}
                    className="px-2.5 py-1.5 rounded-lg text-xs font-semibold text-emerald-700 bg-emerald-50 hover:bg-emerald-100"
                    title="Enviar un mensaje masivo a los leads con esta etiqueta"
                >
                    📣 Difundir
                </Link>
                <button onClick={() => setEditing(true)} className="px-2.5 py-1.5 rounded-lg text-xs font-semibold text-gray-600 hover:bg-gray-100">Editar</button>
                <button
                    onClick={() => {
                        // El conteo va en la pregunta: borrar una etiqueta la
                        // saca de todos los leads que la tienen, y eso no se
                        // deshace.
                        if (confirm(`¿Borrar «${tag.name}»?\n\nSe quitará de ${usos} registro${usos === 1 ? '' : 's'}. No se puede deshacer.`)) {
                            router.delete(route('tags.destroy', tag.id), { preserveScroll: true });
                        }
                    }}
                    className="px-2.5 py-1.5 rounded-lg text-xs font-semibold text-red-600 hover:bg-red-50"
                >
                    Borrar
                </button>
            </div>
        </div>
    );
}

export default function Index({ tags, palette, newLeadTag }) {
    const { flash } = usePage().props;
    const form = useForm({ name: '', color: palette[0] });

    const crear = (e) => {
        e.preventDefault();
        if (!form.data.name.trim()) return;
        form.post(route('tags.store'), { preserveScroll: true, onSuccess: () => form.setData('name', '') });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Etiquetas" />

            <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-5">
                <div>
                    <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Etiquetas</h1>
                    <p className="text-sm text-gray-500 mt-1">
                        Sirven para agrupar leads y después difundirles un mensaje. Cada lead que entra nace con «{newLeadTag}».
                    </p>
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

                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    {tags.length === 0 ? (
                        <div className="p-14 text-center text-sm text-gray-400">Todavía no hay etiquetas.</div>
                    ) : (
                        <ul className="divide-y divide-gray-50">
                            {tags.map((t) => (
                                <li key={t.id}><TagRow tag={t} palette={palette} newLeadTag={newLeadTag} /></li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
