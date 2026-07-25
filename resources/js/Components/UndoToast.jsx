import { useEffect, useState } from 'react';

let showFn = null;

/** API global — llamable desde cualquier página: showUndo({message, onUndo, durationMs}). */
export function showUndo(payload) {
    if (showFn) showFn(payload);
}

export default function UndoToast() {
    const [toast, setToast] = useState(null);

    useEffect(() => {
        showFn = (payload) => setToast({ ...payload, id: Date.now() });
        return () => { showFn = null; };
    }, []);

    useEffect(() => {
        if (!toast) return;
        const t = setTimeout(() => setToast(null), toast.durationMs || 5000);
        return () => clearTimeout(t);
    }, [toast]);

    if (!toast) return null;

    return (
        <div className="fixed bottom-4 left-1/2 -translate-x-1/2 z-50 pointer-events-auto">
            <div className="bg-slate-900 text-white rounded-xl shadow-2xl border border-slate-700 pl-4 pr-2 py-2 flex items-center gap-3 min-w-[280px]">
                <span className="text-sm font-medium">{toast.message}</span>
                {toast.onUndo && (
                    <button
                        type="button"
                        onClick={() => { toast.onUndo(); setToast(null); }}
                        className="px-3 py-1 rounded-lg text-xs font-bold bg-amber-500 hover:bg-amber-400 text-slate-900 transition-colors"
                    >
                        Deshacer
                    </button>
                )}
                <button
                    type="button"
                    onClick={() => setToast(null)}
                    className="text-slate-400 hover:text-white p-1"
                >
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>
        </div>
    );
}
