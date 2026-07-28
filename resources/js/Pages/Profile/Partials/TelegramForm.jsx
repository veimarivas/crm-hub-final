import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Vinculación de Telegram para recibir avisos fuera del CRM.
 *
 * Nunca se le pide al usuario que averigüe su "chat id": se genera un enlace
 * al bot con un token de un solo uso y Telegram hace el resto.
 */
export default function TelegramForm({ user, botConfigured }) {
    const { flash } = usePage().props;
    const [copiado, setCopiado] = useState(false);
    const enlace = flash?.telegram_link;
    const vinculado = !!user.telegram_chat_id;

    const copiar = () => {
        navigator.clipboard.writeText(enlace);
        setCopiado(true);
        setTimeout(() => setCopiado(false), 2000);
    };

    return (
        <section className="max-w-xl">
            <header>
                <h2 className="text-lg font-medium text-gray-900">Avisos por Telegram</h2>
                <p className="mt-1 text-sm text-gray-600">
                    Recibí en tu teléfono un aviso cuando te escriba un contacto asignado, aunque no tengas
                    el CRM abierto. Así podés entrar a atenderlo mientras la IA gana tiempo.
                </p>
            </header>

            {! botConfigured ? (
                <div className="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                    El bot de Telegram todavía no está configurado en el servidor. Pedíselo a quien administra
                    el sistema.
                </div>
            ) : vinculado ? (
                <div className="mt-6 space-y-4">
                    <div className="flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                        <span className="text-2xl">✅</span>
                        <div className="min-w-0">
                            <p className="text-sm font-semibold text-emerald-900">Telegram conectado</p>
                            <p className="text-xs text-emerald-700 mt-0.5">
                                {user.telegram_linked_at
                                    ? `Vinculado el ${new Date(user.telegram_linked_at).toLocaleDateString('es', { day: 'numeric', month: 'long', year: 'numeric' })}`
                                    : 'Listo para recibir avisos'}
                            </p>
                        </div>
                    </div>

                    <button
                        type="button"
                        onClick={() => router.delete(route('telegram.unlink'), { preserveScroll: true })}
                        className="text-sm font-semibold text-red-600 hover:text-red-800"
                    >
                        Desvincular Telegram
                    </button>
                </div>
            ) : (
                <div className="mt-6 space-y-4">
                    {enlace ? (
                        <div className="rounded-xl border border-[#045474]/20 bg-[#045474]/5 p-4 space-y-3">
                            <p className="text-sm text-gray-700">
                                <strong>Último paso:</strong> abrí este enlace y tocá <strong>Iniciar</strong> en Telegram.
                            </p>
                            <div className="flex flex-wrap items-center gap-2">
                                <a
                                    href={enlace}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="px-4 py-2 rounded-xl text-sm font-bold text-white bg-[#229ED9] hover:opacity-90 transition-opacity inline-flex items-center gap-2"
                                >
                                    Abrir Telegram
                                </a>
                                <button
                                    type="button"
                                    onClick={copiar}
                                    className="px-3 py-2 rounded-xl text-sm font-semibold text-gray-600 bg-white border border-gray-200 hover:bg-gray-50"
                                >
                                    {copiado ? '✓ Copiado' : 'Copiar enlace'}
                                </button>
                            </div>
                            <p className="text-xs text-gray-500">
                                Si lo abrís desde la computadora y usás Telegram en el teléfono, copiá el enlace
                                y pegalo allá. Después recargá esta página.
                            </p>
                        </div>
                    ) : (
                        <button
                            type="button"
                            onClick={() => router.post(route('telegram.link'), {}, { preserveScroll: true })}
                            className="px-4 py-2.5 rounded-xl text-sm font-bold text-white bg-[#229ED9] hover:opacity-90 transition-opacity"
                        >
                            Conectar Telegram
                        </button>
                    )}
                </div>
            )}
        </section>
    );
}
