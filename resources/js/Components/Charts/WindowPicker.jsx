/**
 * Selector de ventana (?days= 7/15/30/90) que navega con Inertia y preserva
 * el scroll. Se usa en cada página de analítica para que el periodo elegido
 * quede en la URL y sea compartible.
 *
 * `routeName` es lo más común; también se puede pasar `onSelect(days)` si la
 * página controla la navegación, o `preserve` para params adicionales que
 * no deben perderse (p. ej. el pipeline seleccionado).
 */

import { router } from '@inertiajs/react';

export const DEFAULT_RANGES = [7, 15, 30, 90];

export default function WindowPicker({
    days,
    ranges = DEFAULT_RANGES,
    routeName,
    preserve = {},
    className = '',
}) {
    const go = (r) => {
        if (r === days) return;
        router.get(route(routeName), { days: r, ...preserve }, { preserveScroll: true, preserveState: false });
    };

    return (
        <div className={`flex gap-1 bg-white rounded-xl border border-gray-200 p-1 shrink-0 ${className}`}>
            {ranges.map((r) => (
                <button
                    key={r}
                    onClick={() => go(r)}
                    className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors ${r === days ? 'bg-emerald-600 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-100'}`}
                >
                    {r}d
                </button>
            ))}
        </div>
    );
}