/**
 * Ventana de servicio de WhatsApp: cuánto queda para escribirle al contacto
 * sin que Meta cobre.
 *
 *  - 24 h desde su último mensaje (conversación de servicio).
 *  - 72 h si llegó tocando un anuncio Click-to-WhatsApp (free entry point).
 *
 * Cerrada = escribirle requiere una plantilla aprobada y **eso se factura**,
 * por eso el badge se pone rojo: es el momento de decidir si vale el gasto.
 */

export function windowCountdown(seconds) {
    if (seconds <= 0) return 'Cerrada';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    if (h >= 24) return `${Math.floor(h / 24)}d ${h % 24}h`;
    return h > 0 ? `${h}h ${m}m` : `${m}m`;
}

export function windowTone(w) {
    if (!w?.is_open) return { badge: 'bg-red-50 text-red-700 ring-red-200', bar: 'bg-red-500', text: 'text-red-700' };
    if (w.is_expiring) return { badge: 'bg-amber-50 text-amber-700 ring-amber-200', bar: 'bg-amber-500', text: 'text-amber-700' };
    return { badge: 'bg-emerald-50 text-emerald-700 ring-emerald-200', bar: 'bg-emerald-500', text: 'text-emerald-700' };
}

export function windowTitle(w) {
    if (!w) return '';
    if (!w.is_open) {
        return 'Ventana cerrada: escribirle ahora requiere una plantilla aprobada y tiene costo.';
    }
    const origen = w.source === 'meta_ad' ? ', abierta por un anuncio de Facebook' : '';

    return `Quedan ${windowCountdown(w.remaining_seconds)} para escribirle sin costo (ventana de ${w.window_hours} h${origen}).`;
}

/** Badge compacto para listados y tablas. */
export default function ServiceWindowBadge({ window: w, showOrigin = false }) {
    if (!w || w.source === 'none') return null;

    const tone = windowTone(w);
    const fromAd = w.source === 'meta_ad';

    return (
        <span
            title={windowTitle(w)}
            className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-[11px] font-bold ring-1 ${tone.badge}`}
        >
            <span>{fromAd ? '📣' : '💬'}</span>
            {w.is_open ? windowCountdown(w.remaining_seconds) : 'Cerrada'}
            {showOrigin && <span className="font-normal opacity-70">{fromAd ? '72h' : '24h'}</span>}
        </span>
    );
}

/**
 * Tarjeta con el detalle: de dónde vino el contacto, cuánto queda y una
 * barra de progreso de la ventana. Para la ficha del lead y del contacto.
 */
export function ServiceWindowCard({ window: w }) {
    if (!w || w.source === 'none') {
        return (
            <div className="rounded-xl border border-gray-200 bg-gray-50 p-4">
                <p className="text-xs font-bold uppercase tracking-wider text-gray-400">Ventana de WhatsApp</p>
                <p className="text-sm text-gray-500 mt-1.5">
                    El contacto todavía no escribió por WhatsApp, así que no hay ventana abierta.
                </p>
            </div>
        );
    }

    const tone = windowTone(w);
    const fromAd = w.source === 'meta_ad';
    const totalSeconds = w.window_hours * 3600;
    const pct = Math.max(0, Math.min(100, (w.remaining_seconds / totalSeconds) * 100));

    const fmt = (iso) => (iso ? new Date(iso).toLocaleString('es', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—');

    return (
        <div className={`rounded-xl border p-4 ${w.is_open ? 'border-gray-200 bg-white' : 'border-red-200 bg-red-50/40'}`}>
            <div className="flex items-start justify-between gap-2">
                <div>
                    <p className="text-xs font-bold uppercase tracking-wider text-gray-400">Ventana de WhatsApp</p>
                    <p className={`text-2xl font-extrabold mt-1 tabular-nums leading-none ${tone.text}`}>
                        {w.is_open ? windowCountdown(w.remaining_seconds) : 'Cerrada'}
                    </p>
                </div>
                <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-[11px] font-bold ring-1 shrink-0 ${tone.badge}`}>
                    {fromAd ? '📣 Anuncio · 72 h' : '💬 WhatsApp · 24 h'}
                </span>
            </div>

            <div className="mt-3 h-1.5 w-full rounded-full bg-gray-100 overflow-hidden">
                <div className={`h-full rounded-full transition-all ${tone.bar}`} style={{ width: `${pct}%` }} />
            </div>

            <p className="text-xs text-gray-500 mt-2.5 leading-relaxed">
                {w.is_open ? (
                    <>Podés escribirle sin costo hasta el <strong>{fmt(w.expires_at)}</strong>.</>
                ) : (
                    <>Venció el <strong>{fmt(w.expires_at)}</strong>. Escribirle ahora requiere una plantilla aprobada y <strong>tiene costo</strong>.</>
                )}
            </p>

            <dl className="mt-3 pt-3 border-t border-gray-100 space-y-1.5 text-xs">
                <div className="flex justify-between gap-2">
                    <dt className="text-gray-400">Origen</dt>
                    <dd className="text-gray-700 font-medium text-right">
                        {fromAd ? 'Anuncio de Facebook (Click-to-WhatsApp)' : 'WhatsApp directo'}
                    </dd>
                </div>
                <div className="flex justify-between gap-2">
                    <dt className="text-gray-400">Último mensaje del contacto</dt>
                    <dd className="text-gray-700 font-medium">{fmt(w.last_inbound_at)}</dd>
                </div>
                {w.ad_referral_at && (
                    <div className="flex justify-between gap-2">
                        <dt className="text-gray-400">Clic en el anuncio</dt>
                        <dd className="text-gray-700 font-medium">{fmt(w.ad_referral_at)}</dd>
                    </div>
                )}
            </dl>

            {/* Las dos ventanas se comportan distinto y confundirlas cuesta
                plata: la de 24 h se reinicia con cada mensaje, la del anuncio
                corre desde el clic y no se estira. */}
            <p className="text-[11px] text-gray-400 mt-3 leading-relaxed">
                {fromAd ? (
                    <>Las 72 h corren desde el clic en el anuncio y <strong>no se reinician</strong> aunque el contacto
                    escriba. Dentro de ellas todo es gratis, incluidas las plantillas. Al vencer, si el contacto escribió
                    hace poco te quedan sus 24 h estándar.</>
                ) : (
                    <>La ventana de 24 h <strong>se reinicia cada vez</strong> que el contacto escribe.</>
                )}
            </p>
        </div>
    );
}
