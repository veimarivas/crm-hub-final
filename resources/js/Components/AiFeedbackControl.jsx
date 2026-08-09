/**
 * Pulgar arriba / abajo bajo cada respuesta de la IA, en el chat del lead.
 *
 * Va acá y no en una pantalla de configuración porque el agente que ve la
 * respuesta mala es el único que tiene el contexto para arreglarla, y lo tiene
 * **en ese momento**. Pedirle que después vaya a otro lado a reportarlo es
 * garantizar que no lo haga.
 *
 * El pulgar abajo abre el campo de corrección pero **no lo exige**: marcar que
 * algo estuvo mal ya es información útil, y bloquear el voto detrás de un
 * formulario haría que nadie vote.
 */

import { router } from '@inertiajs/react';
import { useState } from 'react';

export default function AiFeedbackControl({ leadId, eventId, current }) {
    const [rating, setRating] = useState(current?.rating ?? null);
    const [correcting, setCorrecting] = useState(false);
    const [text, setText] = useState(current?.correction ?? '');
    const [sending, setSending] = useState(false);

    const send = (nextRating, correction = null) => {
        setSending(true);
        router.post(route('leads.ai-feedback', leadId), {
            lead_event_id: eventId,
            rating: nextRating,
            correction,
        }, {
            preserveScroll: true,
            onSuccess: () => { setRating(nextRating); setCorrecting(false); },
            onFinish: () => setSending(false),
        });
    };

    const vote = (nextRating) => {
        if (nextRating === 'down') {
            // Se registra el voto igual, y de paso se ofrece corregir.
            send('down', text || null);
            setCorrecting(true);

            return;
        }

        setCorrecting(false);
        send('up');
    };

    return (
        <div className="flex flex-col items-end mt-0.5 mr-1">
            <div className="flex items-center gap-1">
                {rating && (
                    <span className="text-[10px] text-gray-400 mr-1">
                        {rating === 'up' ? 'Marcada como buena' : 'Marcada para revisión'}
                    </span>
                )}

                <button
                    type="button"
                    onClick={() => vote('up')}
                    disabled={sending}
                    title="La respuesta estuvo bien"
                    className={`p-1 rounded-lg transition-colors disabled:opacity-40 ${
                        rating === 'up' ? 'text-emerald-600 bg-emerald-50' : 'text-gray-300 hover:text-emerald-600 hover:bg-emerald-50'
                    }`}
                >
                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6.633 10.5c.806 0 1.533-.446 2.031-1.08a9.041 9.041 0 012.861-2.4c.723-.384 1.35-.956 1.653-1.715a4.498 4.498 0 00.322-1.672V3a.75.75 0 01.75-.75A2.25 2.25 0 0116.5 4.5c0 1.152-.26 2.243-.723 3.218-.266.558.107 1.282.725 1.282h3.126c1.026 0 1.945.694 2.054 1.715.045.422.068.85.068 1.285a11.95 11.95 0 01-2.649 7.521c-.388.482-.987.729-1.605.729H13.48c-.483 0-.964-.078-1.423-.23l-3.114-1.04a4.501 4.501 0 00-1.423-.23H5.904M14.25 9h2.25M5.904 18.75c.083.205.173.405.27.602.197.4-.078.898-.523.898h-.908c-.889 0-1.713-.518-1.972-1.368a12 12 0 01-.521-3.507c0-1.553.295-3.036.831-4.398C3.387 10.203 4.167 9.75 5 9.75h1.053c.472 0 .745.556.5.96a8.958 8.958 0 00-1.302 4.665c0 1.194.232 2.333.654 3.375z" />
                    </svg>
                </button>

                <button
                    type="button"
                    onClick={() => vote('down')}
                    disabled={sending}
                    title="La respuesta estuvo mal — corregir"
                    className={`p-1 rounded-lg transition-colors disabled:opacity-40 ${
                        rating === 'down' ? 'text-rose-600 bg-rose-50' : 'text-gray-300 hover:text-rose-600 hover:bg-rose-50'
                    }`}
                >
                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M7.5 15h2.25m8.024-9.75c.011.05.028.1.052.148.591 1.2.924 2.55.924 3.977a8.96 8.96 0 01-.999 4.125m.023-8.25c-.076-.365.183-.75.575-.75h.908c.889 0 1.713.518 1.972 1.368.339 1.11.521 2.287.521 3.507 0 1.553-.295 3.036-.831 4.398C20.613 14.547 19.833 15 19 15h-1.053c-.472 0-.745-.556-.5-.96a8.95 8.95 0 00.303-.54m.023-8.25H16.48a4.5 4.5 0 01-1.423-.23l-3.114-1.04a4.5 4.5 0 00-1.423-.23H6.504c-.618 0-1.217.247-1.605.729A11.95 11.95 0 002.25 12c0 .434.023.863.068 1.285C2.427 14.306 3.346 15 4.372 15h3.126c.618 0 .991.724.725 1.282A7.471 7.471 0 007.5 19.5a2.25 2.25 0 002.25 2.25.75.75 0 00.75-.75v-.633c0-.573.11-1.14.322-1.672.304-.76.93-1.33 1.653-1.715a9.04 9.04 0 002.86-2.4c.498-.634 1.226-1.08 2.032-1.08h.384" />
                    </svg>
                </button>
            </div>

            {correcting && (
                <div className="mt-1.5 w-full max-w-md">
                    <textarea
                        value={text}
                        onChange={(e) => setText(e.target.value)}
                        rows={2}
                        placeholder="¿Qué debería haber contestado?"
                        className="w-full text-xs border-gray-200 rounded-xl focus:ring-violet-500 focus:border-violet-500"
                    />
                    <div className="flex items-center justify-end gap-2 mt-1">
                        {/* Se dice explícito para no prometer lo que no pasa:
                            la IA no aprende hasta que alguien lo apruebe. */}
                        <span className="text-[10px] text-gray-400 mr-auto">Va a revisión antes de enseñarle a la IA.</span>
                        <button type="button" onClick={() => setCorrecting(false)} className="text-[11px] font-semibold text-gray-400 hover:text-gray-600">
                            Cerrar
                        </button>
                        <button
                            type="button"
                            onClick={() => send('down', text || null)}
                            disabled={sending || !text.trim()}
                            className="px-2.5 py-1 rounded-lg text-[11px] font-bold bg-violet-600 text-white hover:bg-violet-700 disabled:opacity-40"
                        >
                            Enviar corrección
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}
