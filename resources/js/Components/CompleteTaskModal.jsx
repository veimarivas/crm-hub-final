import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import Modal from '@/Components/Modal';

let openFn = null;

/**
 * API global — llamable desde cualquier página, igual que `showUndo`.
 * Las tareas se completan desde el calendario, desde la lista y desde la
 * ficha del lead, todas dentro de subcomponentes: pasar el estado por props
 * hasta cada una obligaba a encadenarlo por tres niveles.
 *
 * completeTask(task, { onCompleted })
 */
export function completeTask(task, options = {}) {
    if (openFn) openFn(task, options);
}

/**
 * Registro del resultado al completar una tarea.
 *
 * Reemplaza al `prompt()` nativo que se usaba antes: se veía como un aviso
 * del navegador (y en algunos se lee como si fuera un script sospechoso),
 * no dejaba escribir varias líneas ni mostraba de qué tarea se trataba.
 *
 * Se monta una sola vez en el layout, como UndoToast.
 */
export default function CompleteTaskModal() {
    const [task, setTask] = useState(null);
    const [options, setOptions] = useState({});
    const [note, setNote] = useState('');
    const [saving, setSaving] = useState(false);
    const textarea = useRef(null);

    const onClose = () => setTask(null);
    const onCompleted = options.onCompleted;

    useEffect(() => {
        openFn = (t, opts) => { setTask(t); setOptions(opts || {}); };
        return () => { openFn = null; };
    }, []);

    useEffect(() => {
        if (task) {
            setNote('');
            // El foco tiene que esperar a la transición de apertura.
            const id = setTimeout(() => textarea.current?.focus(), 120);
            return () => clearTimeout(id);
        }
    }, [task]);

    const submit = (e) => {
        e?.preventDefault();
        if (!task || saving) return;

        setSaving(true);
        router.post(route('tasks.complete', task.id), { result_note: note.trim() || null }, {
            preserveScroll: true,
            onSuccess: () => onCompleted?.(task),
            onFinish: () => { setSaving(false); onClose(); },
        });
    };

    return (
        <Modal show={!!task} onClose={onClose} maxWidth="lg">
            <form onSubmit={submit} className="p-6">
                <div className="flex items-start gap-3">
                    <div className="w-10 h-10 shrink-0 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center text-white shadow-md">
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    </div>
                    <div className="min-w-0">
                        <h2 className="text-lg font-bold text-gray-900">Completar tarea</h2>
                        <p className="text-sm text-gray-500 mt-0.5 break-words">{task?.text}</p>
                    </div>
                </div>

                <div className="mt-5">
                    <label htmlFor="result_note" className="block text-xs font-semibold uppercase tracking-wider text-gray-400 mb-1.5">
                        Resultado <span className="normal-case tracking-normal font-normal text-gray-400">(opcional)</span>
                    </label>
                    <textarea
                        id="result_note"
                        ref={textarea}
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                        onKeyDown={(e) => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) submit(e); }}
                        rows={4}
                        maxLength={2000}
                        placeholder="¿Cómo salió? Ej: contestó, pidió que le llame el lunes…"
                        className="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 focus:bg-white transition-all resize-none"
                    />
                    <p className="text-[11px] text-gray-400 mt-1.5">Queda en el historial de la tarea. Ctrl+Enter para guardar.</p>
                </div>

                <div className="mt-6 flex justify-end gap-2">
                    <button type="button" onClick={onClose} className="px-4 py-2 rounded-xl text-sm font-semibold text-gray-600 hover:bg-gray-100 transition-colors">
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        disabled={saving}
                        className="px-4 py-2 rounded-xl text-sm font-bold text-white bg-emerald-600 hover:bg-emerald-700 shadow-sm disabled:opacity-50 transition-colors"
                    >
                        {saving ? 'Guardando…' : 'Completar'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}
