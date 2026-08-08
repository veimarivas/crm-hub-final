/**
 * Paleta semántica de gráficos (semántica Komo, no inventar otra):
 *  emerald  = ganado / positivo / dentro de SLA
 *  amber    = advertencia
 *  rose     = perdido / peligro / fuera de SLA
 *  purple   = IA
 *  brand    = serie principal (gradiente de marca de la app)
 *  slate    = neutral
 */
export const TONE = {
    positive: '#10b981',
    warning: '#f59e0b',
    danger: '#f43f5e',
    ai: '#8b5cf6',
    brand: '#045474',
    brandDark: '#1c486c',
    slate: '#64748b',
    blue: '#3b82f6',
    violet: '#8b5cf6',
    pink: '#ec4899',
};

/** Series predefinidas para usar en cada chart (significado compartido). */
export const SERIES = {
    creados: TONE.brand,
    ganado: TONE.positive,
    perdido: TONE.danger,
    ia: TONE.ai,
    facturado: TONE.brand,
    cobrado: TONE.positive,
    objetivo: TONE.warning,
    dentroSla: TONE.positive,
    sobreSla: TONE.warning,
    vencido: TONE.danger,
};

/** Estilo compartido de tooltip para todos los charts. */
export const tooltipStyle = {
    backgroundColor: '#fff',
    border: '1px solid #e2e8f0',
    borderRadius: '0.75rem',
    boxShadow: '0 10px 25px -5px rgb(0 0 0 / 0.1)',
    fontSize: 12,
};

export const axisStyle = {
    tick: { fill: '#94a3b8', fontSize: 11 },
    axisLine: false,
    tickLine: false,
};