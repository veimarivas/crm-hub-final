import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const ROLE_META = {
    owner: { label: 'Owner', gradient: 'from-amber-500 to-orange-600', bg: 'bg-amber-50', text: 'text-amber-800', ring: 'ring-amber-200' },
    admin: { label: 'Admin', gradient: 'from-purple-500 to-violet-600', bg: 'bg-purple-50', text: 'text-purple-800', ring: 'ring-purple-200' },
    agent: { label: 'Agente', gradient: 'from-emerald-500 to-teal-600', bg: 'bg-emerald-50', text: 'text-emerald-800', ring: 'ring-emerald-200' },
    viewer: { label: 'Solo lectura', gradient: 'from-gray-400 to-gray-500', bg: 'bg-gray-50', text: 'text-gray-700', ring: 'ring-gray-200' },
};

const SCOPE_LABELS = {
    'leads:read': 'Leer leads',
    'leads:write': 'Crear leads',
    'contacts:read': 'Leer contactos',
};

export default function Team({ members, invitations, apiKeys = [], apiScopes = [], isAdmin, isOwner, newInviteUrl, newApiKey, autoAssignLeads = false }) {
    const { flash, errors, auth } = usePage().props;
    const [copied, setCopied] = useState(false);
    const [copiedKey, setCopiedKey] = useState(false);
    const [confirmRemove, setConfirmRemove] = useState(null);
    const [removing, setRemoving] = useState(false);
    const inviteForm = useForm({ role: 'agent', label: '' });
    const keyForm = useForm({ name: '', scopes: [...apiScopes] });

    const invite = (e) => {
        e.preventDefault();
        inviteForm.post(route('team.invite'), { preserveScroll: true });
    };

    const createKey = (e) => {
        e.preventDefault();
        keyForm.post(route('team.api-keys.store'), { preserveScroll: true, onSuccess: () => keyForm.reset('name') });
    };

    const toggleScope = (scope) => {
        keyForm.setData('scopes', keyForm.data.scopes.includes(scope)
            ? keyForm.data.scopes.filter((s) => s !== scope)
            : [...keyForm.data.scopes, scope]);
    };

    const copy = () => {
        navigator.clipboard.writeText(newInviteUrl);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    const copyKey = () => {
        navigator.clipboard.writeText(newApiKey);
        setCopiedKey(true);
        setTimeout(() => setCopiedKey(false), 2000);
    };

    return (
        <AuthenticatedLayout>
            <Head title="Equipo" />

            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <div>
                    <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Equipo</h1>
                    <p className="text-sm text-gray-400 mt-1">Invita a tu equipo de ventas con un link</p>
                </div>

                {flash?.success && <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 shadow-sm">{flash.success}</div>}
                {errors?.member && <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 shadow-sm">{errors.member}</div>}

                {newInviteUrl && (
                    <div className="rounded-2xl border border-emerald-200 bg-gradient-to-br from-emerald-50 to-teal-50 p-5 shadow-sm">
                        <p className="text-sm font-bold text-emerald-900">Link de invitación creado</p>
                        <p className="text-xs text-emerald-700 mb-2 mt-0.5">Compártelo — expira en 7 días y es de un solo uso</p>
                        <div className="flex items-center gap-2">
                            <code className="flex-1 block select-all break-all rounded-lg bg-white px-3 py-2 text-xs font-mono ring-1 ring-inset ring-gray-200">{newInviteUrl}</code>
                            <button onClick={copy} className="px-3 py-2 text-xs font-semibold text-white bg-gradient-to-r from-gray-700 to-gray-900 rounded-lg hover:opacity-90 transition-all shrink-0">
                                {copied ? '✓' : 'Copiar'}
                            </button>
                        </div>
                    </div>
                )}

                {/* Round-robin: asignacion automatica de leads entrantes */}
                {isAdmin && (
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 sm:p-6">
                        <div className="flex items-start justify-between gap-4">
                            <div className="min-w-0 flex-1">
                                <h3 className="text-base font-bold text-gray-900 flex items-center gap-2">
                                    <span className="text-lg">🔄</span>
                                    Distribución automática de leads
                                </h3>
                                <p className="text-xs text-gray-500 mt-1 leading-relaxed">
                                    Cuando un lead entra por <strong>WhatsApp, formulario o Lead Ad</strong> sin responsable, se asigna al agente con menos leads abiertos.
                                    Los leads creados manualmente no se tocan.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => router.post(route('team.auto-assign'), {}, { preserveScroll: true })}
                                className={`shrink-0 relative inline-flex h-7 w-14 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-emerald-500/30 ${autoAssignLeads ? 'bg-emerald-500' : 'bg-gray-300'}`}
                                role="switch"
                                aria-checked={autoAssignLeads}
                            >
                                <span className={`inline-block h-5 w-5 transform rounded-full bg-white shadow transition ${autoAssignLeads ? 'translate-x-8' : 'translate-x-1'}`} />
                            </button>
                        </div>
                        {autoAssignLeads && (
                            <div className="mt-3 inline-flex items-center gap-1.5 text-[11px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-full px-2.5 py-1">
                                <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse" />
                                Activo — los nuevos leads se reparten automáticamente
                            </div>
                        )}
                    </div>
                )}

                <div className="grid gap-6 xl:grid-cols-2 items-start">

                {/* Miembros */}
                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div className="p-5 sm:p-6 border-b border-gray-100">
                        <h3 className="text-base font-bold text-gray-900">Miembros</h3>
                        <p className="text-xs text-gray-400 mt-0.5">{members.length} miembro{members.length !== 1 ? 's' : ''}</p>
                    </div>
                    <ul className="divide-y divide-gray-50">
                        {members.map((member) => {
                            const role = ROLE_META[member.account_role] ?? ROLE_META.viewer;
                            return (
                                <li key={member.id} className="flex items-center justify-between px-5 sm:px-6 py-4 hover:bg-gray-50 transition-colors">
                                    <div className="flex items-center gap-3">
                                        <div className={`w-10 h-10 rounded-xl bg-gradient-to-br ${role.gradient} flex items-center justify-center text-white text-sm font-bold shadow-lg`}>
                                            {member.name.charAt(0).toUpperCase()}
                                        </div>
                                        <div>
                                            <p className="font-semibold text-gray-900">
                                                {member.name}
                                                {member.id === auth.user.id && <span className="ml-2 text-xs text-gray-400 font-normal">(tú)</span>}
                                            </p>
                                            <p className="text-xs text-gray-500">{member.email}</p>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        {isAdmin && member.account_role !== 'owner' ? (
                                            <>
                                                <select
                                                    value={member.account_role}
                                                    onChange={(e) => router.patch(route('team.members.update', member.id), { account_role: e.target.value }, { preserveScroll: true })}
                                                    className="px-3 py-1.5 border border-gray-200 rounded-lg text-xs font-medium bg-gray-50"
                                                >
                                                    <option value="admin">Admin</option>
                                                    <option value="agent">Agente</option>
                                                    <option value="viewer">Solo lectura</option>
                                                </select>
                                                {isOwner && member.id !== auth.user.id && (
                                                    <button
                                                        onClick={() => { if (confirm(`¿Transferir la propiedad de la cuenta a ${member.name}? Tú pasarás a ser admin.`)) router.post(route('team.members.transfer', member.id), {}, { preserveScroll: true }); }}
                                                        className="p-1.5 text-gray-400 hover:text-amber-600 hover:bg-amber-50 rounded-lg transition-colors"
                                                        title="Hacer owner"
                                                    >
                                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.562.562 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z" />
                                                        </svg>
                                                    </button>
                                                )}
                                                {member.id !== auth.user.id && (
                                                    <button
                                                        onClick={() => setConfirmRemove(member)}
                                                        className="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                                                        title="Expulsar"
                                                    >
                                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />
                                                        </svg>
                                                    </button>
                                                )}
                                            </>
                                        ) : (
                                            <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold ring-1 ${role.bg} ${role.text} ${role.ring}`}>
                                                {role.label}
                                            </span>
                                        )}
                                    </div>
                                </li>
                            );
                        })}
                    </ul>

                    {isAdmin && (
                        <form onSubmit={invite} className="p-5 sm:p-6 border-t border-gray-100 bg-gray-50/50 flex flex-wrap items-end gap-3">
                            <div>
                                <label className="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Rol</label>
                                <select value={inviteForm.data.role} onChange={(e) => inviteForm.setData('role', e.target.value)} className="px-3 py-2.5 border border-gray-200 rounded-xl text-sm bg-white">
                                    <option value="admin">Admin</option>
                                    <option value="agent">Agente</option>
                                    <option value="viewer">Solo lectura</option>
                                </select>
                            </div>
                            <div className="flex-1 min-w-[180px]">
                                <label className="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Etiqueta (opcional)</label>
                                <input value={inviteForm.data.label} onChange={(e) => inviteForm.setData('label', e.target.value)} placeholder="ej. Vendedores" className="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-white" />
                            </div>
                            <button type="submit" disabled={inviteForm.processing} className="px-4 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 disabled:opacity-50 transition-all shadow-lg shadow-emerald-500/20">
                                Generar link
                            </button>
                        </form>
                    )}

                    {invitations.length > 0 && (
                        <div className="p-5 sm:p-6 border-t border-gray-100 space-y-2">
                            <p className="text-xs font-semibold uppercase tracking-wider text-gray-500">Invitaciones activas</p>
                            {invitations.map((inv) => (
                                <div key={inv.id} className="flex items-center justify-between rounded-xl bg-gray-50 p-3 text-sm gap-3">
                                    <span className="text-gray-600 min-w-0 flex-1">
                                        <span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-semibold ring-1 mr-2 ${ROLE_META[inv.role]?.bg} ${ROLE_META[inv.role]?.text} ${ROLE_META[inv.role]?.ring}`}>
                                            {ROLE_META[inv.role]?.label ?? inv.role}
                                        </span>
                                        {inv.label || 'Sin etiqueta'}
                                        <span className="text-gray-400 ml-2 text-xs">expira {new Date(inv.expires_at).toLocaleDateString('es', { day: 'numeric', month: 'short' })}</span>
                                    </span>
                                    {isAdmin && (
                                        <div className="flex items-center gap-3 shrink-0">
                                            <button
                                                onClick={() => router.post(route('team.invitations.regenerate', inv.id), {}, { preserveScroll: true })}
                                                title="Regenera el token y muestra el link arriba (útil si perdiste el original)"
                                                className="text-xs text-emerald-700 hover:text-emerald-800 font-medium flex items-center gap-1"
                                            >
                                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                                                Regenerar link
                                            </button>
                                            <button onClick={() => router.delete(route('team.invitations.revoke', inv.id), { preserveScroll: true })} className="text-xs text-red-600 hover:text-red-700 font-medium">
                                                Revocar
                                            </button>
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                {/* API keys — consumidas por meta_ads (atribución y Lead Ads) */}
                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div className="p-5 sm:p-6 border-b border-gray-100">
                        <h3 className="text-base font-bold text-gray-900">API keys</h3>
                        <p className="text-xs text-gray-400 mt-0.5">Acceso a la API pública /api/v1 — la usa Meta Ads Manager para atribución y Lead Ads</p>
                    </div>

                    {newApiKey && (
                        <div className="m-5 sm:m-6 rounded-2xl border border-emerald-200 bg-gradient-to-br from-emerald-50 to-teal-50 p-5 shadow-sm">
                            <p className="text-sm font-bold text-emerald-900">API key creada</p>
                            <p className="text-xs text-emerald-700 mb-2 mt-0.5">Cópiala ahora — no volverá a mostrarse</p>
                            <div className="flex items-center gap-2">
                                <code className="flex-1 block select-all break-all rounded-lg bg-white px-3 py-2 text-xs font-mono ring-1 ring-inset ring-gray-200">{newApiKey}</code>
                                <button onClick={copyKey} className="px-3 py-2 text-xs font-semibold text-white bg-gradient-to-r from-gray-700 to-gray-900 rounded-lg hover:opacity-90 transition-all shrink-0">
                                    {copiedKey ? '✓' : 'Copiar'}
                                </button>
                            </div>
                        </div>
                    )}

                    {apiKeys.length > 0 && (
                        <ul className="divide-y divide-gray-50">
                            {apiKeys.map((key) => (
                                <li key={key.id} className="flex items-center justify-between px-5 sm:px-6 py-4 hover:bg-gray-50 transition-colors group">
                                    <div>
                                        <p className="font-semibold text-gray-900 text-sm">
                                            {key.name}
                                            {key.revoked_at && (
                                                <span className="ml-2 inline-flex px-2 py-0.5 rounded-full text-xs font-bold ring-1 bg-red-50 text-red-700 ring-red-200">Revocada</span>
                                            )}
                                        </p>
                                        <p className="text-xs text-gray-500 font-mono mt-0.5">{key.key_prefix}…</p>
                                        <p className="text-xs text-gray-400 mt-0.5">
                                            {(key.scopes || []).map((s) => SCOPE_LABELS[s] ?? s).join(' · ')}
                                            {key.last_used_at
                                                ? ` — usada ${new Date(key.last_used_at).toLocaleDateString('es', { day: 'numeric', month: 'short' })}`
                                                : ' — sin usar'}
                                        </p>
                                    </div>
                                    {isAdmin && !key.revoked_at && (
                                        <button
                                            onClick={() => { if (confirm(`¿Revocar la API key "${key.name}"? Las integraciones que la usen dejarán de funcionar.`)) router.delete(route('team.api-keys.revoke', key.id), { preserveScroll: true }); }}
                                            className="text-xs text-red-600 hover:text-red-700 font-medium opacity-0 group-hover:opacity-100 transition-opacity"
                                        >
                                            Revocar
                                        </button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}

                    {isAdmin && (
                        <form onSubmit={createKey} className="p-5 sm:p-6 border-t border-gray-100 bg-gray-50/50 flex flex-wrap items-end gap-3">
                            <div className="flex-1 min-w-[180px]">
                                <label className="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Nombre</label>
                                <input value={keyForm.data.name} onChange={(e) => keyForm.setData('name', e.target.value)} placeholder="ej. Meta Ads Manager" required className="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-white" />
                            </div>
                            <div>
                                <label className="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Permisos</label>
                                <div className="flex gap-3">
                                    {apiScopes.map((scope) => (
                                        <label key={scope} className="flex items-center gap-1.5 text-sm text-gray-700 bg-white border border-gray-200 rounded-xl px-3 py-2.5 cursor-pointer">
                                            <input type="checkbox" checked={keyForm.data.scopes.includes(scope)} onChange={() => toggleScope(scope)} className="rounded border-gray-300" />
                                            {SCOPE_LABELS[scope] ?? scope}
                                        </label>
                                    ))}
                                </div>
                            </div>
                            <button type="submit" disabled={keyForm.processing || keyForm.data.scopes.length === 0} className="px-4 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-purple-600 to-violet-600 rounded-xl hover:from-purple-500 hover:to-violet-500 disabled:opacity-50 transition-all shadow-lg shadow-purple-500/20">
                                Crear API key
                            </button>
                        </form>
                    )}
                </div>

                </div>

                <Modal show={!!confirmRemove} onClose={() => setConfirmRemove(null)} maxWidth="md">
                    <div className="p-6">
                        <div className="flex items-start gap-4">
                            <div className="w-11 h-11 rounded-full bg-red-50 flex items-center justify-center text-red-600 shrink-0">
                                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                </svg>
                            </div>
                            <div className="min-w-0 flex-1">
                                <h3 className="text-base font-bold text-gray-900">Expulsar miembro</h3>
                                <p className="text-sm text-gray-500 mt-1">
                                    ¿Expulsar a <strong>{confirmRemove?.name}</strong> (<span className="text-gray-400">{confirmRemove?.email}</span>) del equipo?
                                </p>
                                <p className="text-xs text-red-600 mt-2">Perderá el acceso inmediatamente. Esta acción no se puede deshacer.</p>
                            </div>
                        </div>
                        <div className="mt-6 flex justify-end gap-3">
                            <button onClick={() => setConfirmRemove(null)} className="px-4 py-2.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-all shadow-sm">
                                Cancelar
                            </button>
                            <button
                                onClick={() => {
                                    setRemoving(true);
                                    router.delete(route('team.members.remove', confirmRemove.id), {
                                        preserveScroll: true,
                                        onSuccess: () => { setConfirmRemove(null); setRemoving(false); },
                                        onError: () => setRemoving(false),
                                    });
                                }}
                                disabled={removing}
                                className="px-5 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-500 rounded-xl transition-all shadow-lg shadow-red-500/20 disabled:opacity-50"
                            >
                                {removing ? 'Expulsando…' : 'Sí, expulsar'}
                            </button>
                        </div>
                    </div>
                </Modal>
            </div>
        </AuthenticatedLayout>
    );
}
