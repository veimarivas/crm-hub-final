import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

/**
 * Selector de etiquetas: buscar, marcar y crear en el mismo lugar.
 *
 * El anterior era una grilla con TODAS las etiquetas de la cuenta y un botón
 * "+ Nueva" al final: con diez etiquetas ya no se encontraba ninguna, y crear
 * una obligaba a acertar el nombre de memoria para no duplicarla. Acá se
 * escribe, se ve si ya existe, y recién si no existe aparece «Crear».
 *
 * @param {Array}    allTags   etiquetas de la cuenta [{id,name,color}]
 * @param {Array}    value     etiquetas puestas [{id,name,color}]
 * @param {Function} onChange  recibe el array de ids resultante
 * @param {boolean}  canCreate permite crear al vuelo (POST tags.store)
 */
export default function TagPicker({ allTags = [], value = [], onChange, canCreate = true, compact = false }) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const boxRef = useRef(null);

    const activos = new Set(value.map((t) => t.id));

    // Cerrar al hacer clic afuera: el desplegable tapa contenido y quedarse
    // abierto molesta más de lo que ayuda.
    useEffect(() => {
        if (!open) return undefined;
        const fuera = (e) => { if (boxRef.current && !boxRef.current.contains(e.target)) { setOpen(false); setQuery(''); } };
        document.addEventListener('mousedown', fuera);
        return () => document.removeEventListener('mousedown', fuera);
    }, [open]);

    const filtradas = allTags.filter((t) => t.name.toLowerCase().includes(query.trim().toLowerCase()));
    const existeExacta = allTags.some((t) => t.name.toLowerCase() === query.trim().toLowerCase());

    const toggle = (tagId) => {
        const next = activos.has(tagId) ? value.filter((t) => t.id !== tagId).map((t) => t.id) : [...activos, tagId];
        onChange(Array.from(next));
    };

    const crear = () => {
        const name = query.trim();
        if (!name || existeExacta) return;
        // La etiqueta se crea en el servidor y el listado vuelve por Inertia;
        // el usuario la marca en el mismo desplegable que ya tiene abierto.
        router.post(route('tags.store'), { name }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setQuery(''),
        });
    };

    return (
        <div ref={boxRef} className="relative">
            <div className="flex flex-wrap items-center gap-1.5">
                {value.map((t) => (
                    <span
                        key={t.id}
                        className="group inline-flex items-center gap-1 pl-2.5 pr-1 py-1 rounded-full text-xs font-bold text-white shadow-sm"
                        style={{ backgroundColor: t.color }}
                    >
                        {t.name}
                        <button
                            type="button"
                            onClick={() => toggle(t.id)}
                            className="w-4 h-4 rounded-full inline-flex items-center justify-center hover:bg-black/20 transition-colors"
                            title="Quitar"
                        >
                            ×
                        </button>
                    </span>
                ))}

                <button
                    type="button"
                    onClick={() => setOpen((o) => !o)}
                    className={`inline-flex items-center gap-1 rounded-full border border-dashed px-2.5 py-1 text-xs font-semibold transition-all ${
                        open ? 'border-emerald-500 text-emerald-700 bg-emerald-50' : 'border-gray-300 text-gray-500 hover:border-emerald-400 hover:text-emerald-600'
                    }`}
                >
                    🏷 {value.length === 0 && !compact ? 'Etiquetar' : '+'}
                </button>
            </div>

            {open && (
                <div className="absolute z-30 mt-2 w-64 rounded-xl border border-gray-200 bg-white shadow-xl overflow-hidden">
                    <div className="p-2 border-b border-gray-100">
                        <input
                            autoFocus
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') { e.preventDefault(); if (canCreate) crear(); }
                                if (e.key === 'Escape') { setOpen(false); setQuery(''); }
                            }}
                            placeholder="Buscar o crear…"
                            className="w-full px-2.5 py-1.5 rounded-lg border border-gray-200 bg-gray-50 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:bg-white"
                        />
                    </div>

                    <div className="max-h-56 overflow-y-auto py-1">
                        {filtradas.map((t) => (
                            <button
                                key={t.id}
                                type="button"
                                onClick={() => toggle(t.id)}
                                className="w-full flex items-center gap-2 px-3 py-1.5 text-left hover:bg-gray-50 transition-colors"
                            >
                                <span className="w-4 h-4 rounded border flex items-center justify-center text-white text-[10px] font-bold shrink-0"
                                    style={activos.has(t.id) ? { backgroundColor: t.color, borderColor: t.color } : { borderColor: '#d1d5db' }}>
                                    {activos.has(t.id) ? '✓' : ''}
                                </span>
                                <span className="w-2 h-2 rounded-full shrink-0" style={{ backgroundColor: t.color }} />
                                <span className="text-sm text-gray-700 truncate">{t.name}</span>
                            </button>
                        ))}

                        {filtradas.length === 0 && !query && (
                            <p className="px-3 py-4 text-center text-xs text-gray-400">No hay etiquetas todavía.</p>
                        )}
                    </div>

                    {canCreate && query.trim() && !existeExacta && (
                        <button
                            type="button"
                            onClick={crear}
                            className="w-full px-3 py-2.5 text-left text-sm font-semibold text-emerald-700 bg-emerald-50 hover:bg-emerald-100 border-t border-emerald-100"
                        >
                            + Crear «{query.trim()}»
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}
