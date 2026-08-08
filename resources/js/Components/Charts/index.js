/**
 * Capa base de gráficos (Paso 0). Importa desde aquí y no desde los archivos
 * individuales: export { ChartCard, EmptyChart } from '@/components/Charts';
 */

import { axisStyle, SERIES, TONE, tooltipStyle } from './chartTheme';
import ChartCard, { EmptyChart } from './ChartCard';
import ChartTip from './ChartTip';
import CompareBars from './CompareBars';
import DonutChart from './DonutChart';
import FunnelSteps from './FunnelSteps';
import HeatmapGrid from './HeatmapGrid';
import TrendArea from './TrendArea';
import WindowPicker, { DEFAULT_RANGES } from './WindowPicker';

export {
    TONE,
    SERIES,
    tooltipStyle,
    axisStyle,
    ChartCard,
    EmptyChart,
    ChartTip,
    CompareBars,
    DonutChart,
    FunnelSteps,
    HeatmapGrid,
    TrendArea,
    WindowPicker,
    DEFAULT_RANGES,
};
export { fmtInteger, fmtMoney, fmtNumber, fmtDuration, fmtPct } from './format';