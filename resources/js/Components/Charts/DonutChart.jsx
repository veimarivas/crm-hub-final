/**
 * Gráfico de donut para distribución (fuentes, orígenes, repartos...).
 * Centro con el total, leyenda al costado con valor absoluto y % de
 * participación, clicable para ocultar segmentos.
 *
 * data: [{ name, value, color }]
 */

import { useMemo, useState } from 'react';
import { Cell, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts';
import { fmtNumber } from './format';
import { EmptyChart } from './ChartCard';
import ChartTip from './ChartTip';

export default function DonutChart({
    data = [],
    centerLabel = 'Total',
    valueFormatter = fmtNumber,
    emptyMessage = 'Sin datos en este periodo.',
    className = '',
    onSliceClick,
}) {
    const [hidden, setHidden] = useState(() => new Set());
    const visible = useMemo(() => data.filter((s) => !hidden.has(s.name)), [data, hidden]);
    const total = useMemo(() => visible.reduce((acc, s) => acc + (Number(s.value) || 0), 0), [visible]);

    if (total === 0) return <EmptyChart message={emptyMessage} />;

    const toggle = (name) => {
        setHidden((prev) => {
            const next = new Set(prev);
            if (next.has(name)) next.delete(name);
            else next.add(name);
            return next;
        });
    };

    return (
        <div className={`${className} flex items-center gap-6 flex-wrap`}>
            <div className="relative shrink-0 w-44 h-44">
                <ResponsiveContainer width="100%" height="100%">
                    <PieChart>
                        <Tooltip content={<ChartTip valueFormatter={valueFormatter} />} />
                        <Pie
                            data={visible}
                            dataKey="value"
                            nameKey="name"
                            innerRadius={58}
                            outerRadius={82}
                            paddingAngle={2}
                            stroke="#fff"
                            strokeWidth={2}
                            onClick={onSliceClick ? (entry) => onSliceClick(entry?.payload ?? null) : undefined}
                            cursor={onSliceClick ? 'pointer' : undefined}
                        >
                            {visible.map((s) => (
                                <Cell key={s.name} fill={s.color || '#045474'} className="transition-opacity hover:opacity-80" />
                            ))}
                        </Pie>
                    </PieChart>
                </ResponsiveContainer>
                <div className="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                    <span className="text-2xl font-extrabold text-gray-900 tabular-nums leading-none">{fmtNumber(total)}</span>
                    <span className="text-[10px] font-semibold uppercase tracking-wider text-gray-400 mt-0.5">{centerLabel}</span>
                </div>
            </div>

            <ul className="space-y-2 min-w-0 flex-1">
                {data.map((s) => {
                    const isHidden = hidden.has(s.name);
                    const share = total > 0 ? ((Number(s.value) || 0) / total) * 100 : 0;
                    return (
                        <li key={s.name}>
                            <button
                                onClick={() => toggle(s.name)}
                                title={isHidden ? 'Mostrar segmento' : 'Ocultar segmento'}
                                className={`w-full flex items-center gap-2 text-sm ${isHidden ? 'opacity-40 line-through' : ''}`}
                            >
                                <span className="w-2.5 h-2.5 rounded-sm shrink-0" style={{ background: isHidden ? '#cbd5e1' : s.color }} />
                                <span className="text-gray-600 truncate">{s.name}</span>
<span className="ml-auto pl-1 font-bold text-gray-900 tabular-nums shrink-0">{valueFormatter(s.value)}</span>
                                <span className="text-xs text-gray-400 tabular-nums w-10 text-right shrink-0">
                                    {share.toFixed(0)}%
                                </span>
                            </button>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}