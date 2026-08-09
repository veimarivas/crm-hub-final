/**
 * Panel del copiloto en la ficha del lead.
 *
 * Tres cosas, en este orden deliberado:
 *   1. QUÉ HACER ahora (lo único que cambia un resultado),
 *   2. el score y su banda,
 *   3. de dónde salió ese número.
 *
 * El desglose va colapsado pero presente: nadie lo abre todos los días, y el
 * día que el número no cuadra con la intuición del asesor, tenerlo a mano es
 * la diferencia entre corregir el criterio y dejar de creerle al módulo.
 */

const BANDS = {
    caliente: { label: 'Caliente', ring: 'ring-rose-200', bg: 'bg-rose-50', text: 'text-rose-700', bar: 'bg-rose-500' },
    tibio: { label: 'Tibio', ring: 'ring-amber-200', bg: 'bg-amber-50', text: 'text-amber-700', bar: 'bg-amber-500' },
    frio: { label: 'Frío', ring: 'ring-sky-200', bg: 'bg-sky-50', text: 'text-sky-700', bar: 'bg-sky-500' },
};

const TONES = {
    danger: 'bg-rose-50 text-rose-700 ring-rose-200 hover:bg-rose-100',
    warning: 'bg-amber-50 text-amber-700 ring-amber-200 hover:bg-amber-100',
    neutral: 'bg-gray-50 text-gray-700 ring-gray-200 hover:bg-gray-100',
};

const ICONS = {
    reply: 'M8 10.5h8m-8 4h4m-4 5.5l-3 3V6a3 3 0 013-3h12a3 3 0 013 3v9a3 3 0 01-3 3H8z',
    window: 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z',
    task: 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    cooled: 'M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z',
    stagnant: 'M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3',
};

export default function CopilotPanel({ copilot, onAction }) {
    if (!copilot) return null;

    const { score, band, factors = [], actions = [], calibration, scoredAt } = copilot;
    const tone = BANDS[band] ?? null;
    const hasScore = score !== null && score !== undefined;

    return (
        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div className="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between">
                <h3 className="text-sm font-bold text-gray-900 flex items-center gap-2">
                    <span className="w-1 h-4 bg-gradient-to-b from-[#045474] to-[#1c486c] rounded-full" />
                    Copiloto
                </h3>
                {hasScore && tone && (
                    <span className={`text-[11px] font-bold px-2 py-0.5 rounded-full ring-1 ${tone.bg} ${tone.text} ${tone.ring}`}>
                        {tone.label}
                    </span>
                )}
            </div>

            <div className="p-5 space-y-4">
                {/* 1. Qué hacer ahora */}
                {actions.length > 0 ? (
                    <div className="space-y-2">
                        {actions.map((a) => (
                            <button
                                key={a.key}
                                type="button"
                                onClick={() => onAction?.(a)}
                                className={`w-full text-left px-3 py-2.5 rounded-xl ring-1 transition-colors ${TONES[a.tone] ?? TONES.neutral}`}
                            >
                                <span className="flex items-start gap-2.5">
                                    <svg className="w-4 h-4 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d={ICONS[a.key] ?? ICONS.stagnant} />
                                    </svg>
                                    <span className="min-w-0">
                                        <span className="block text-xs font-bold">{a.label}</span>
                                        {/* El motivo es la mitad del valor: «llamalo» no se
                                            acciona, «escribió hace 3 h» sí. */}
                                        <span className="block text-[11px] opacity-80 mt-0.5">{a.reason}</span>
                                        {a.action?.cost_after != null && (
                                            <span className="block text-[10px] opacity-70 mt-0.5">
                                                Después costaría ~{a.action.cost_after} Bs.
                                            </span>
                                        )}
                                    </span>
                                </span>
                            </button>
                        ))}
                    </div>
                ) : (
                    <p className="text-xs text-gray-400">Nada pendiente con este lead ahora mismo.</p>
                )}

                {/* 2. El score */}
                {hasScore ? (
                    <div>
                        <div className="flex items-baseline justify-between mb-1.5">
                            <span className="text-[11px] font-semibold uppercase tracking-wider text-gray-400">
                                Probabilidad relativa
                            </span>
                            <span className="text-2xl font-extrabold text-gray-900 tabular-nums leading-none">{score}</span>
                        </div>
                        <div className="h-1.5 rounded-full bg-gray-100 overflow-hidden">
                            <div className={`h-full rounded-full ${tone?.bar ?? 'bg-gray-400'}`} style={{ width: `${score}%` }} />
                        </div>

                        {/* Lo que hace honesto al número: qué cerró de verdad esta
                            banda, o el reconocimiento de que todavía no se sabe. */}
                        <p className="text-[11px] text-gray-500 mt-2">
                            {calibration?.calibrated && calibration?.rate != null ? (
                                <>De los leads «{BANDS[band]?.label.toLowerCase()}» ya cerrados, ganó el{' '}
                                <strong className="text-gray-700">{calibration.rate}%</strong>.</>
                            ) : (
                                <span className="inline-flex items-center gap-1 text-amber-600">
                                    <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                                    </svg>
                                    Sin calibrar — {calibration?.closed ?? 0} leads cerrados, hacen falta 200.
                                </span>
                            )}
                        </p>
                    </div>
                ) : (
                    <p className="text-xs text-gray-400">Todavía sin puntuar. Se calcula cada noche.</p>
                )}

                {/* 3. De dónde sale */}
                {factors.length > 0 && (
                    <details className="group">
                        <summary className="cursor-pointer list-none text-[11px] font-semibold text-gray-400 hover:text-gray-600 flex items-center gap-1">
                            <svg className="w-3 h-3 transition-transform group-open:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                            Por qué este puntaje
                        </summary>
                        <div className="mt-2.5 space-y-2">
                            {factors.map((f) => (
                                <div key={f.key}>
                                    <div className="flex items-baseline justify-between gap-2">
                                        <span className="text-[11px] font-semibold text-gray-600">{f.label}</span>
                                        <span className="text-[11px] tabular-nums text-gray-400 shrink-0">
                                            {f.points} / {f.max}
                                        </span>
                                    </div>
                                    <div className="h-1 rounded-full bg-gray-100 overflow-hidden mt-0.5">
                                        <div
                                            className="h-full rounded-full bg-gradient-to-r from-[#045474] to-[#1c486c]"
                                            style={{ width: `${f.max ? (f.points / f.max) * 100 : 0}%` }}
                                        />
                                    </div>
                                    <p className="text-[10px] text-gray-400 mt-0.5">{f.detail}</p>
                                </div>
                            ))}
                        </div>
                        {scoredAt && (
                            <p className="text-[10px] text-gray-300 mt-3">
                                Calculado el {new Date(scoredAt).toLocaleString('es-BO', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })}
                            </p>
                        )}
                    </details>
                )}
            </div>
        </div>
    );
}
