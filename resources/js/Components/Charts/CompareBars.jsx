/**
 * Barras comparativas con una línea de objetivo (SLA, meta, media…) y soporte
 * para barras apiladas (serie por etapa por agente).
 *
 * layout:
 *   'horizontal' — una fila por categoría (agentes, campañas); línea vertical.
 *   'vertical'    — barras verticales por período/bucket; línea horizontal.
 *
 * `<CompareBars data rows />` pinta la columna `value` de cada fila y la tiña
 * contra `target`. Con `series [{ key, name, color }]` pinta una barra apilada
 * por categoría (`xKey`) compuesta por cada serie (stackId compartido).
 */

import { Bar, BarChart, CartesianGrid, Cell, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { axisStyle, TONE } from './chartTheme';
import { fmtNumber } from './format';
import { EmptyChart } from './ChartCard';
import ChartTip from './ChartTip';

const autoTone = (value, target) => {
    if (target === undefined || target === null) return TONE.brand;
    if (value <= target) return TONE.positive;
    if (value <= target * 1.2) return TONE.warning;
    return TONE.danger;
};

export default function CompareBars({
    data = [],
    xKey = 'name',
    series,
    height = 240,
    layout = 'horizontal',
    target,
    targetLabel,
    targetColor = TONE.warning,
    valueFormatter = fmtNumber,
    emptyMessage = 'Sin datos en este periodo.',
    className = '',
    onBarClick,
}) {
    if (data.length === 0) return <EmptyChart message={emptyMessage} />;

    const isHorizontal = layout === 'horizontal';
    const stacked = Array.isArray(series) && series.length > 0;

    const chart = (
        <BarChart
            data={data}
            layout={layout}
            margin={{ top: 4, right: 8, left: 0, bottom: 0 }}
            barSize={isHorizontal ? 16 : 14}
            onClick={onBarClick ? (entry) => onBarClick((entry && entry.activePayload?.[0]?.payload) ?? null) : undefined}
        >
            <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" vertical={isHorizontal} horizontal={!isHorizontal} />
            {isHorizontal ? (
                <>
                    <XAxis type="number" {...axisStyle} tickFormatter={valueFormatter} />
                    <YAxis type="category" dataKey={xKey} {...axisStyle} width={120} />
                </>
            ) : (
                <>
                    <XAxis dataKey={xKey} {...axisStyle} />
                    <YAxis {...axisStyle} width={40} tickFormatter={valueFormatter} allowDecimals={false} />
                </>
            )}
            <Tooltip content={<ChartTip valueFormatter={valueFormatter} showPct={!stacked} />} cursor={{ fill: 'rgba(15,118,110,0.06)' }} />
            {target !== undefined && (
                <ReferenceLine
                    x={isHorizontal ? target : undefined}
                    y={isHorizontal ? undefined : target}
                    stroke={targetColor}
                    strokeDasharray="4 4"
                    label={{
                        value: targetLabel || 'Objetivo',
                        position: isHorizontal ? 'top' : 'insideTopRight',
                        fill: targetColor,
                        fontSize: 10,
                        fontWeight: 700,
                    }}
                />
            )}
            {stacked ? series.map((s) => (
                <Bar key={s.key} dataKey={s.key} name={s.name} stackId="a" fill={s.color} radius={[0, 0, 0, 0]} />
            )) : (
                <Bar dataKey="value" radius={isHorizontal ? [0, 4, 4, 0] : [4, 4, 0, 0]} cursor={onBarClick ? 'pointer' : undefined}>
                    {data.map((d, i) => (
                        <Cell
                            key={`${d.id ?? d[xKey] ?? i}`}
                            fill={d.color || autoTone(d.value, target)}
                            className="transition-opacity hover:opacity-80"
                        />
                    ))}
                </Bar>
            )}
        </BarChart>
    );

    return <div className={className}><ResponsiveContainer width="100%" height={height}>{chart}</ResponsiveContainer></div>;
}