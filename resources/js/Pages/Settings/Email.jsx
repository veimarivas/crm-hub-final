import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Casillas corporativas de Google Workspace.
 *
 * Cada quien conecta la suya: el token da acceso a ese correo, así que
 * administrar la casilla de otro sería leer su correspondencia. La pantalla
 * muestra las del equipo —para saber quién está conectado— pero solo deja
 * operar la propia.
 */
export default function EmailSettings({ mailboxes = [], configured, domain }) {
    const { flash } = usePage().props;
    const [confirmDelete, setConfirmDelete] = useState(null);

    return (
        <AuthenticatedLayout>
            <Head title="Correo" />

            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
                <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Correo</h1>
                        <p className="text-sm text-gray-500 mt-1">
                            Los correos entran al timeline del lead como un mensaje más, junto a los de WhatsApp.
                        </p>
                    </div>
                    {configured && (
                        <a
                            href={route('settings.email.connect')}
                            className="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold bg-gradient-to-r from-[#045474] to-[#1c486c] text-white shadow-lg hover:opacity-90"
                        >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                            </svg>
                            Conectar mi casilla
                        </a>
                    )}
                </div>

                {flash?.error && (
                    <div className="rounded-2xl bg-rose-50 border border-rose-200 px-5 py-3 text-sm text-rose-800">{flash.error}</div>
                )}

                {!configured && (
                    <div className="rounded-2xl bg-amber-50 border border-amber-200 px-5 py-4">
                        <p className="text-sm font-bold text-amber-900">Falta configurar Google</p>
                        <p className="text-xs text-amber-800 mt-1">
                            Hay que crear las credenciales OAuth en la consola de Google Cloud y ponerlas en el
                            <code className="mx-1 px-1 rounded bg-amber-100">.env</code>
                            (<code>GOOGLE_CLIENT_ID</code>, <code>GOOGLE_CLIENT_SECRET</code>, <code>GOOGLE_REDIRECT_URI</code>).
                        </p>
                    </div>
                )}

                {domain && (
                    <p className="text-xs text-gray-400">
                        Solo se aceptan casillas del dominio <strong className="text-gray-600">@{domain}</strong>.
                    </p>
                )}

                {mailboxes.length === 0 ? (
                    <div className="bg-white rounded-2xl border border-dashed border-gray-300 px-5 py-16 text-center">
                        <p className="text-sm font-semibold text-gray-500">Ninguna casilla conectada</p>
                        <p className="text-xs text-gray-400 mt-1">
                            Al conectar, se empieza a sincronizar desde ese momento: no se importa el correo viejo.
                        </p>
                    </div>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2">
                        {mailboxes.map((m) => (
                            <div key={m.id} className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <p className="text-sm font-bold text-gray-900 truncate">{m.email}</p>
                                        <p className="text-xs text-gray-400 mt-0.5">{m.owner}</p>
                                    </div>
                                    <span className={`shrink-0 text-[10px] font-bold px-2 py-0.5 rounded-full ring-1 ${
                                        m.needs_reconnect || m.last_error
                                            ? 'bg-amber-50 text-amber-700 ring-amber-200'
                                            : 'bg-emerald-50 text-emerald-700 ring-emerald-200'
                                    }`}>
                                        {m.needs_reconnect ? 'Reconectar' : m.last_error ? 'Con error' : 'Activa'}
                                    </span>
                                </div>

                                <p className="text-[11px] text-gray-400 mt-3">
                                    {m.last_synced_at
                                        ? `Última sincronización: ${new Date(m.last_synced_at).toLocaleString('es', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}`
                                        : 'Todavía sin sincronizar'}
                                </p>

                                {/* Un fallo se ve acá y no solo en la tabla de jobs:
                                    una casilla que dejó de traer correo hace tres
                                    días tiene que notarse. */}
                                {m.last_error && <p className="text-[11px] text-amber-700 mt-1">{m.last_error}</p>}

                                {m.is_mine && (
                                    <div className="flex items-center gap-2 mt-4 pt-3 border-t border-gray-100">
                                        <button
                                            onClick={() => router.post(route('settings.email.sync', m.id), {}, { preserveScroll: true })}
                                            className="text-xs font-bold text-[#045474] hover:underline"
                                        >
                                            Sincronizar ahora
                                        </button>
                                        {m.needs_reconnect && (
                                            <a href={route('settings.email.connect')} className="text-xs font-bold text-amber-700 hover:underline">
                                                Reconectar
                                            </a>
                                        )}
                                        <button
                                            onClick={() => setConfirmDelete(m)}
                                            className="ml-auto text-xs font-semibold text-gray-400 hover:text-rose-600"
                                        >
                                            Desconectar
                                        </button>
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </div>

            <Modal show={Boolean(confirmDelete)} onClose={() => setConfirmDelete(null)} maxWidth="md">
                <div className="p-6">
                    <h2 className="text-lg font-bold text-gray-900">Desconectar {confirmDelete?.email}</h2>
                    <p className="text-sm text-gray-500 mt-2">
                        Deja de traer correo nuevo. Lo que ya está en el timeline de los leads no se toca.
                    </p>
                    <div className="flex justify-end gap-2 mt-5">
                        <button onClick={() => setConfirmDelete(null)} className="px-4 py-2 rounded-xl text-sm font-semibold bg-white border border-gray-200 text-gray-700 hover:bg-gray-50">
                            Cancelar
                        </button>
                        <button
                            onClick={() => router.delete(route('settings.email.destroy', confirmDelete.id), {
                                preserveScroll: true,
                                onFinish: () => setConfirmDelete(null),
                            })}
                            className="px-4 py-2 rounded-xl text-sm font-semibold bg-rose-600 text-white hover:bg-rose-700"
                        >
                            Sí, desconectar
                        </button>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
