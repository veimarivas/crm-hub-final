import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

function tomorrowAt(hour = 9) {
    const d = new Date();
    d.setDate(d.getDate() + 1);
    d.setHours(hour, 0, 0, 0);
    return d;
}

function nextMonday(hour = 9) {
    const d = new Date();
    const daysUntilMonday = (8 - d.getDay()) % 7 || 7;
    d.setDate(d.getDate() + daysUntilMonday);
    d.setHours(hour, 0, 0, 0);
    return d;
}

function toLocalIso(d) {
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString();
}

const PRESETS = [
    { label: '+15 min', minutes: 15 },
    { label: '+1 hora', minutes: 60 },
    { label: '+4 horas', minutes: 240 },
    { label: 'Mañana 9am', until: () => tomorrowAt(9) },
    { label: 'Próximo lunes 9am', until: () => nextMonday(9) },
];

export default function SnoozeButton({ taskId, size = 'md', variant = 'ghost' }) {
    const [open, setOpen] = useState(false);
    const ref = useRef(null);

    useEffect(() => {
        if (!open) return;
        const onClick = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
        document.addEventListener('mousedown', onClick);
        return () => document.removeEventListener('mousedown', onClick);
    }, [open]);

    const doSnooze = (preset) => {
        const payload = preset.until ? { until: toLocalIso(preset.until()) } : { minutes: preset.minutes };
        router.post(route('tasks.snooze', taskId), payload, { preserveScroll: true });
        setOpen(false);
    };

    const sizes = {
        sm: 'p-1 text-[10px]',
        md: 'p-1.5 text-xs',
    };
    const variants = {
        ghost: 'text-gray-400 hover:text-amber-600 hover:bg-amber-50',
        solid: 'text-amber-700 bg-amber-50 border border-amber-200 hover:bg-amber-100 rounded-lg font-semibold',
    };

    return (
        <div ref={ref} className="relative inline-block">
            <button
                type="button"
                onClick={(e) => { e.stopPropagation(); e.preventDefault(); setOpen(!open); }}
                title="Posponer tarea"
                className={`rounded-lg transition-colors inline-flex items-center gap-1 ${sizes[size]} ${variants[variant]}`}
            >
                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                    <path strokeLinecap="round" strokeLinejoin="round" d="M16 3l2 2M8 3L6 5" />
                </svg>
                {variant === 'solid' && 'Posponer'}
            </button>
            {open && (
                <div className="absolute right-0 top-full mt-1 z-30 w-44 rounded-xl bg-white shadow-xl border border-gray-100 py-1 text-sm">
                    <div className="px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-gray-400 border-b border-gray-50">Posponer para</div>
                    {PRESETS.map((p) => (
                        <button
                            key={p.label}
                            type="button"
                            onClick={(e) => { e.stopPropagation(); e.preventDefault(); doSnooze(p); }}
                            className="w-full text-left px-3 py-1.5 hover:bg-amber-50 hover:text-amber-700 transition-colors"
                        >
                            {p.label}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
