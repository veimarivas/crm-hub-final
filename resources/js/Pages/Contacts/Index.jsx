import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ServiceWindowBadge from '@/Components/ServiceWindowBadge';
import Modal from '@/Components/Modal';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const inputClass = 'w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 focus:bg-white transition-all';

function ContactModal({ open, onClose, contact, companies, allTags, customFields }) {
    const isEdit = !!contact;
    const { data, setData, post, patch, processing, errors, reset, clearErrors } = useForm({
        name: contact?.name ?? '',
        position: contact?.position ?? '',
        phone: contact?.phone ?? '',
        email: contact?.email ?? '',
        company_id: contact?.company_id ?? '',
        tag_ids: contact?.tags?.map((t) => t.id) ?? [],
        custom_values: {},
    });

    const toggleTag = (id) =>
        setData('tag_ids', data.tag_ids.includes(id) ? data.tag_ids.filter((t) => t !== id) : [...data.tag_ids, id]);

    const close = () => { reset(); clearErrors(); onClose(); };
    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: close };
        isEdit ? patch(route('contacts.update', contact.id), opts) : post(route('contacts.store'), opts);
    };

    return (
        <Modal show={open} onClose={close}>
            <form onSubmit={submit}>
                <div className="px-6 pt-6 pb-4 border-b border-gray-100 flex items-center gap-3">
                    <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-[#045474] to-[#1c486c] flex items-center justify-center text-white shadow-lg shadow-[#045474]/20">
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </div>
                    <h2 className="text-base font-bold text-gray-900">{isEdit ? 'Editar contacto' : 'Nuevo contacto'}</h2>
                </div>
                <div className="px-6 py-5 space-y-4">
                    {[['name', 'Nombre *', true], ['position', 'Cargo', false], ['phone', 'Teléfono', false], ['email', 'Email', false]].map(([field, label, required]) => (
                        <div key={field}>
                            <label className="block text-sm font-semibold text-gray-700 mb-1.5">{label}</label>
                            <input value={data[field]} onChange={(e) => setData(field, e.target.value)} required={required} className={inputClass} />
                            {errors[field] && <p className="mt-1 text-xs text-red-500 font-medium">{errors[field]}</p>}
                        </div>
                    ))}
                    <div>
                        <label className="block text-sm font-semibold text-gray-700 mb-1.5">Empresa</label>
                        <select value={data.company_id} onChange={(e) => setData('company_id', e.target.value)} className={inputClass}>
                            <option value="">— Sin empresa —</option>
                            {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                    </div>

                    {allTags.length > 0 && (
                        <div>
                            <label className="block text-sm font-semibold text-gray-700 mb-1.5">Etiquetas</label>
                            <div className="flex flex-wrap gap-1.5">
                                {allTags.map((tag) => {
                                    const active = data.tag_ids.includes(tag.id);
                                    return (
                                        <button
                                            key={tag.id}
                                            type="button"
                                            onClick={() => toggleTag(tag.id)}
                                            className={`rounded-full px-2.5 py-1 text-xs font-medium transition-all ${active ? 'text-white shadow-md' : 'bg-gray-100 text-gray-500 hover:bg-gray-200'}`}
                                            style={active ? { backgroundColor: tag.color } : {}}
                                        >
                                            {tag.name}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    )}

                    {customFields.map((field) => (
                        <div key={field.id}>
                            <label className="block text-sm font-semibold text-gray-700 mb-1.5">{field.name}</label>
                            {field.field_type === 'select' ? (
                                <select
                                    value={data.custom_values[field.id] ?? ''}
                                    onChange={(e) => setData('custom_values', { ...data.custom_values, [field.id]: e.target.value })}
                                    className={inputClass}
                                >
                                    <option value="">—</option>
                                    {(field.options ?? []).map((opt) => <option key={opt} value={opt}>{opt}</option>)}
                                </select>
                            ) : (
                                <input
                                    type={field.field_type === 'number' ? 'number' : field.field_type === 'date' ? 'date' : 'text'}
                                    value={data.custom_values[field.id] ?? ''}
                                    onChange={(e) => setData('custom_values', { ...data.custom_values, [field.id]: e.target.value })}
                                    className={inputClass}
                                />
                            )}
                        </div>
                    ))}
                </div>
                <div className="px-6 py-4 bg-gray-50/80 border-t border-gray-100 rounded-b-2xl flex justify-end gap-3">
                    <button type="button" onClick={close} className="px-4 py-2.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-all shadow-sm">Cancelar</button>
                    <button type="submit" disabled={processing} className="px-5 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 disabled:opacity-50 transition-all shadow-lg shadow-emerald-500/20">
                        {isEdit ? 'Guardar' : 'Crear'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

export default function Index({ contacts, companies, allTags, customFields, filters }) {
    const { flash, errors: pageErrors } = usePage().props;
    const [modal, setModal] = useState(null);
    const [search, setSearch] = useState(filters.q ?? '');

    const applySearch = () => router.get(route('contacts.index'), { q: search || undefined }, { preserveState: true, replace: true });

    return (
        <AuthenticatedLayout>
            <Head title="Contactos" />

            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Contactos</h1>
                        <p className="text-sm text-gray-400 mt-1">{contacts.total} en total — los de WhatsApp se sincronizan solos</p>
                    </div>
                    <div className="flex items-center gap-2 flex-wrap">
                        <a
                            href={route('contacts.export', { q: filters?.q || search || undefined })}
                            className="px-3.5 py-2.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 shadow-sm inline-flex items-center gap-1.5"
                            title="Exportar contactos a CSV (respetando la búsqueda)"
                        >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" /></svg>
                            Exportar
                        </a>
                        <button
                            onClick={() => {
                                if (confirm('¿Importar los contactos del CRM de WhatsApp? Los duplicados se omiten.')) {
                                    router.post(route('contacts.import-wacrm'), {}, { preserveScroll: true });
                                }
                            }}
                            className="px-3.5 py-2.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 hover:border-gray-300 transition-all shadow-sm flex items-center gap-1.5"
                        >
                            💬 Importar del WhatsApp CRM
                        </button>
                        <button onClick={() => setModal('create')} className="px-4 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 transition-all shadow-lg shadow-emerald-500/20 flex items-center gap-1.5">
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            Nuevo contacto
                        </button>
                    </div>
                </div>

                {flash?.success && <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 shadow-sm">{flash.success}</div>}
                {pageErrors?.import && <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 shadow-sm">{pageErrors.import}</div>}

                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div className="p-5 border-b border-gray-100 flex gap-2">
                        <input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && applySearch()}
                            placeholder="Buscar por nombre, teléfono, email…"
                            className="flex-1 max-w-md px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 focus:bg-white transition-all"
                        />
                        <button onClick={applySearch} className="px-4 py-2.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-all shadow-sm">Buscar</button>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="bg-gray-50/80">
                                    <th className="text-left px-5 py-3.5 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Nombre</th>
                                    <th className="text-left px-5 py-3.5 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Teléfono</th>
                                    <th className="text-left px-5 py-3.5 text-[11px] font-bold text-gray-500 uppercase tracking-wider hidden md:table-cell">Email</th>
                                    <th className="text-left px-5 py-3.5 text-[11px] font-bold text-gray-500 uppercase tracking-wider hidden md:table-cell">Empresa</th>
                                    <th className="text-right px-5 py-3.5 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Leads abiertos</th>
                                    <th className="text-right px-5 py-3.5 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Acción</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-50">
                                {contacts.data.map((contact) => (
                                    <tr key={contact.id} className="hover:bg-gray-50 transition-colors group">
                                        <td className="px-5 py-4">
                                            <div className="flex items-center gap-3">
                                                <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-[#045474] to-[#1c486c] flex items-center justify-center text-white text-xs font-bold shadow-sm">
                                                    {contact.name.charAt(0).toUpperCase()}
                                                </div>
                                                <div>
                                                    <span className="font-semibold text-gray-900">{contact.name}</span>
                                                    {contact.position && <p className="text-xs text-gray-400">{contact.position}</p>}
                                                </div>
                                                {contact.wacrm_contact_id && <span title="Sincronizado con WhatsApp" className="text-emerald-500 text-xs">💬</span>}
                                                <ServiceWindowBadge window={contact.service_window} />
                                            </div>
                                        </td>
                                        <td className="px-5 py-4 text-gray-600 font-medium tabular-nums">{contact.phone || <span className="italic text-gray-300">—</span>}</td>
                                        <td className="px-5 py-4 text-gray-500 hidden md:table-cell">{contact.email || <span className="italic text-gray-300">—</span>}</td>
                                        <td className="px-5 py-4 text-gray-500 hidden md:table-cell">{contact.company?.name || <span className="italic text-gray-300">—</span>}</td>
                                        <td className="px-5 py-4 text-right font-bold tabular-nums text-gray-700">{contact.open_leads_count}</td>
                                        <td className="px-5 py-4 text-right">
                                            <div className="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                                <button onClick={() => setModal(contact)} className="p-2 text-gray-400 hover:text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors" title="Editar">
                                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg>
                                                </button>
                                                <button
                                                    onClick={() => { if (confirm('¿Eliminar este contacto?')) router.delete(route('contacts.destroy', contact.id), { preserveScroll: true }); }}
                                                    className="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Eliminar"
                                                >
                                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166" /></svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                                {contacts.data.length === 0 && (
                                    <tr><td colSpan={6} className="px-5 py-14 text-center text-sm text-gray-400">Sin contactos todavía</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                    {(contacts.prev_page_url || contacts.next_page_url) && (
                        <div className="px-5 py-4 bg-gray-50/80 border-t border-gray-100 flex justify-end gap-3 text-sm">
                            {contacts.prev_page_url && <Link href={contacts.prev_page_url} className="text-emerald-600 font-medium">← Anterior</Link>}
                            {contacts.next_page_url && <Link href={contacts.next_page_url} className="text-emerald-600 font-medium">Siguiente →</Link>}
                        </div>
                    )}
                </div>
            </div>

            <ContactModal
                key={modal && typeof modal === 'object' ? modal.id : String(modal)}
                open={modal === 'create' || (modal && typeof modal === 'object')}
                onClose={() => setModal(null)}
                contact={typeof modal === 'object' ? modal : null}
                companies={companies}
                allTags={allTags}
                customFields={customFields}
            />
        </AuthenticatedLayout>
    );
}
