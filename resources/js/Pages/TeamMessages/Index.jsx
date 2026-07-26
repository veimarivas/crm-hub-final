import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export const CATEGORY_META = {
    seguimiento: { label: 'Seguimiento', icon: '🎯', className: 'bg-emerald-50 text-emerald-700 ring-emerald-200', dot: '#10b981' },
    personal: { label: 'Personal', icon: '👤', className: 'bg-sky-50 text-sky-700 ring-sky-200', dot: '#0ea5e9' },
    marketing: { label: 'Marketing', icon: '📣', className: 'bg-violet-50 text-violet-700 ring-violet-200', dot: '#8b5cf6' },
};

const ROLE_LABEL = { owner: 'Owner', admin: 'Admin', agent: 'Agente', viewer: 'Viewer' };

function initials(name) {
    return (name || '?').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
}

function whenText(iso) {
    return new Date(iso).toLocaleString('es', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

/** Fecha mínima del datetime-local: ahora, en hora local (no UTC). */
function localNow() {
    const d = new Date(Date.now() - new Date().getTimezoneOffset() * 60000);
    return d.toISOString().slice(0, 16);
}

export default function TeamMessagesIndex({ members, categories, sent, preselected }) {
    const [scheduled, setScheduled] = useState(false);

    const form = useForm({
        title: '',
        body: '',
        category: 'seguimiento',
        deliver_at: '',
        user_ids: preselected ? [preselected] : [],
    });

    // Al entrar desde Seguimiento (?to=<id>) el responsable ya viene elegido.
    useEffect(() => {
        if (preselected) form.setData('user_ids', [preselected]);
    }, [preselected]);

    const selected = form.data.user_ids;
    const allSelected = members.length > 0 && selected.length === members.length;

    const toggle = (id) => {
        form.setData('user_ids', selected.includes(id) ? selected.filter((x) => x !== id) : [...selected, id]);
    };

    const submit = (e) => {
        e.preventDefault();
        form.post(route('team-messages.store'), {
            preserveScroll: true,
            onSuccess: () => { form.reset('title', 'body', 'deliver_at', 'user_ids'); setScheduled(false); },
        });
    };

    const inputClass = 'w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 focus:bg-white transition-all';

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-900">Avisos</h2>}>
            <Head title="Avisos" />

            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <div>
                    <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Avisos al equipo</h1>
                    <p className="text-sm text-gray-500 mt-1">
                        Notas y recordatorios que le llegan al responsable a sus notificaciones. Podés mandarlos a una persona o a varias.
                    </p>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-5 gap-6">
                    <form onSubmit={submit} className="lg:col-span-3 bg-white rounded-2xl shadow-sm border border-gray-100 p-5 sm:p-6 space-y-5">
                        <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-gray-400 mb-2">Apartado</label>
                            <div className="grid grid-cols-3 gap-2">
                                {categories.map((c) => {
                                    const meta = CATEGORY_META[c];
                                    const active = form.data.category === c;
                                    return (
                                        <button
                                            key={c}
                                            type="button"
                                            onClick={() => form.setData('category', c)}
                                            className={`px-3 py-2.5 rounded-xl text-sm font-semibold border-2 transition-all flex items-center justify-center gap-1.5 ${
                                                active ? 'border-emerald-500 bg-emerald-50 text-emerald-700 shadow-sm' : 'border-gray-200 text-gray-500 hover:border-gray-300'
                                            }`}
                                        >
                                            <span>{meta.icon}</span>
                                            {meta.label}
                                        </button>
                                    );
                                })}
                            </div>
                            {form.errors.category && <p className="text-xs text-red-600 mt-1.5">{form.errors.category}</p>}
                        </div>

                        <div>
                            <label htmlFor="title" className="block text-xs font-semibold uppercase tracking-wider text-gray-400 mb-1.5">Título</label>
                            <input
                                id="title"
                                value={form.data.title}
                                onChange={(e) => form.setData('title', e.target.value)}
                                maxLength={255}
                                placeholder="Ej: Revisar los contactos sin responder de esta semana"
                                className={inputClass}
                            />
                            {form.errors.title && <p className="text-xs text-red-600 mt-1.5">{form.errors.title}</p>}
                        </div>

                        <div>
                            <label htmlFor="body" className="block text-xs font-semibold uppercase tracking-wider text-gray-400 mb-1.5">
                                Mensaje <span className="normal-case tracking-normal font-normal text-gray-400">(opcional)</span>
                            </label>
                            <textarea
                                id="body"
                                value={form.data.body}
                                onChange={(e) => form.setData('body', e.target.value)}
                                rows={4}
                                maxLength={5000}
                                placeholder="Detalle de la nota o el recordatorio…"
                                className={`${inputClass} resize-none`}
                            />
                            {form.errors.body && <p className="text-xs text-red-600 mt-1.5">{form.errors.body}</p>}
                        </div>

                        <div className="rounded-xl border border-gray-200 p-4 space-y-3">
                            <label className="flex items-center gap-2.5 cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={scheduled}
                                    onChange={(e) => { setScheduled(e.target.checked); if (!e.target.checked) form.setData('deliver_at', ''); }}
                                    className="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500"
                                />
                                <span className="text-sm font-semibold text-gray-700">Programarlo como recordatorio</span>
                            </label>
                            {scheduled && (
                                <div>
                                    <input
                                        type="datetime-local"
                                        value={form.data.deliver_at}
                                        min={localNow()}
                                        onChange={(e) => form.setData('deliver_at', e.target.value)}
                                        className={inputClass}
                                    />
                                    <p className="text-[11px] text-gray-400 mt-1.5">
                                        Le aparecerá recién en ese momento. Hasta entonces no lo ve ni le cuenta como no leído.
                                    </p>
                                    {form.errors.deliver_at && <p className="text-xs text-red-600 mt-1.5">{form.errors.deliver_at}</p>}
                                </div>
                            )}
                        </div>

                        <div>
                            <div className="flex items-center justify-between mb-2">
                                <label className="block text-xs font-semibold uppercase tracking-wider text-gray-400">
                                    Destinatarios {selected.length > 0 && <span className="text-emerald-600">· {selected.length}</span>}
                                </label>
                                {members.length > 0 && (
                                    <button
                                        type="button"
                                        onClick={() => form.setData('user_ids', allSelected ? [] : members.map((m) => m.id))}
                                        className="text-xs font-semibold text-emerald-600 hover:text-emerald-700"
                                    >
                                        {allSelected ? 'Ninguno' : 'Todo el equipo'}
                                    </button>
                                )}
                            </div>

                            {members.length === 0 ? (
                                <p className="text-sm text-gray-400 py-4 text-center">Todavía no hay nadie más en el equipo.</p>
                            ) : (
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-64 overflow-y-auto pr-1">
                                    {members.map((m) => {
                                        const on = selected.includes(m.id);
                                        return (
                                            <button
                                                key={m.id}
                                                type="button"
                                                onClick={() => toggle(m.id)}
                                                className={`flex items-center gap-2.5 px-3 py-2 rounded-xl border-2 text-left transition-all ${
                                                    on ? 'border-emerald-500 bg-emerald-50' : 'border-gray-200 hover:border-gray-300'
                                                }`}
                                            >
                                                <span className={`w-8 h-8 shrink-0 rounded-full flex items-center justify-center text-xs font-bold text-white ${on ? 'bg-emerald-600' : 'bg-gray-400'}`}>
                                                    {on ? '✓' : initials(m.name)}
                                                </span>
                                                <span className="min-w-0">
                                                    <span className="block text-sm font-semibold text-gray-900 truncate">{m.name}</span>
                                                    <span className="block text-[11px] text-gray-400">{ROLE_LABEL[m.account_role] ?? m.account_role}</span>
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>
                            )}
                            {form.errors.user_ids && <p className="text-xs text-red-600 mt-1.5">{form.errors.user_ids}</p>}
                        </div>

                        <div className="flex justify-end pt-1">
                            <button
                                type="submit"
                                disabled={form.processing || members.length === 0}
                                className="px-5 py-2.5 rounded-xl text-sm font-bold text-white bg-emerald-600 hover:bg-emerald-700 shadow-sm disabled:opacity-50 transition-colors"
                            >
                                {form.processing ? 'Enviando…' : scheduled ? 'Programar recordatorio' : 'Enviar nota'}
                            </button>
                        </div>
                    </form>

                    <div className="lg:col-span-2 bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="p-5 border-b border-gray-100">
                            <h3 className="text-base font-bold text-gray-900">Enviados</h3>
                            <p className="text-xs text-gray-500 mt-0.5">Tus últimos avisos y quién los leyó.</p>
                        </div>
                        {sent.length === 0 ? (
                            <p className="px-5 py-10 text-center text-sm text-gray-400">Todavía no mandaste ningún aviso.</p>
                        ) : (
                            <ul className="divide-y divide-gray-50 max-h-[640px] overflow-y-auto">
                                {sent.map((s, i) => {
                                    const meta = CATEGORY_META[s.category] ?? CATEGORY_META.seguimiento;
                                    return (
                                        <li key={i} className="px-5 py-4">
                                            <div className="flex items-start justify-between gap-2">
                                                <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-[11px] font-bold ring-1 shrink-0 ${meta.className}`}>
                                                    {meta.icon} {meta.label}
                                                </span>
                                                {s.pending && (
                                                    <span className="inline-flex px-2 py-0.5 rounded-lg text-[11px] font-bold ring-1 bg-amber-50 text-amber-700 ring-amber-200 shrink-0">
                                                        Programado
                                                    </span>
                                                )}
                                            </div>
                                            <p className="text-sm font-semibold text-gray-900 mt-2">{s.title}</p>
                                            {s.body && <p className="text-xs text-gray-500 mt-1 line-clamp-2">{s.body}</p>}
                                            <p className="text-[11px] text-gray-400 mt-2">
                                                {s.pending ? `Se entrega ${whenText(s.deliver_at)}` : whenText(s.created_at)}
                                                {' · '}
                                                {s.recipients === 1 ? s.names[0] ?? '1 persona' : `${s.recipients} personas`}
                                            </p>
                                            {!s.pending && (
                                                <p className="text-[11px] mt-1 font-semibold text-gray-500">
                                                    Leído por {s.read}/{s.recipients}
                                                </p>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
