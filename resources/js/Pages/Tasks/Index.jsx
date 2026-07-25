import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const TYPE_META = {
    call: { icon: '📞', label: 'Llamada', color: 'bg-blue-100 text-blue-700 border-blue-200' },
    meet: { icon: '🤝', label: 'Reunión', color: 'bg-purple-100 text-purple-700 border-purple-200' },
    follow_up: { icon: '🔔', label: 'Seguimiento', color: 'bg-amber-100 text-amber-700 border-amber-200' },
    email: { icon: '✉️', label: 'Email', color: 'bg-emerald-100 text-emerald-700 border-emerald-200' },
    other: { icon: '📋', label: 'Otro', color: 'bg-gray-100 text-gray-700 border-gray-200' },
};

const WEEKDAYS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
const MONTHS = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

function ymd(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

function sameDate(a, b) {
    return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}

function NewTaskModal({ open, onClose, members, defaultDate }) {
    const { data, setData, post, processing, reset, errors } = useForm({
        lead_id: '',
        task_type: 'call',
        text: '',
        due_at: defaultDate || '',
        assigned_to: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('tasks.store'), { onSuccess: () => { reset(); onClose(); } });
    };
    const inputClass = 'w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 focus:bg-white transition-all';

    return (
        <Modal show={open} onClose={() => { reset(); onClose(); }}>
            <form onSubmit={submit}>
                <div className="px-6 pt-6 pb-4 border-b border-gray-100 flex items-center gap-3">
                    <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-400 to-orange-500 flex items-center justify-center text-white shadow-lg shadow-amber-500/20">
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    </div>
                    <div>
                        <h2 className="text-base font-bold text-gray-900">Nueva tarea</h2>
                        <p className="text-xs text-gray-400 mt-0.5">Sin lead vinculado — para tareas por lead, usá la ficha del lead</p>
                    </div>
                </div>
                <div className="px-6 py-5 space-y-4">
                    <div>
                        <label className="block text-sm font-semibold text-gray-700 mb-1.5">Descripción <span className="text-red-500">*</span></label>
                        <input value={data.text} onChange={(e) => setData('text', e.target.value)} required maxLength={2000} className={inputClass} placeholder="ej. Llamar a Juan para confirmar reunión" />
                        {errors.text && <p className="mt-1 text-xs text-red-500 font-medium">{errors.text}</p>}
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-semibold text-gray-700 mb-1.5">Tipo</label>
                            <select value={data.task_type} onChange={(e) => setData('task_type', e.target.value)} className={inputClass}>
                                {Object.entries(TYPE_META).map(([k, v]) => <option key={k} value={k}>{v.icon} {v.label}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="block text-sm font-semibold text-gray-700 mb-1.5">Vence</label>
                            <input type="datetime-local" required value={data.due_at} onChange={(e) => setData('due_at', e.target.value)} className={inputClass} />
                            {errors.due_at && <p className="mt-1 text-xs text-red-500 font-medium">{errors.due_at}</p>}
                        </div>
                    </div>
                    <div>
                        <label className="block text-sm font-semibold text-gray-700 mb-1.5">Asignada a</label>
                        <select value={data.assigned_to} onChange={(e) => setData('assigned_to', e.target.value)} className={inputClass}>
                            <option value="">Yo</option>
                            {members.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                        </select>
                    </div>
                </div>
                <div className="px-6 py-4 bg-gray-50/80 border-t border-gray-100 rounded-b-2xl flex justify-end gap-3">
                    <button type="button" onClick={() => { reset(); onClose(); }} className="px-4 py-2.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 shadow-sm">Cancelar</button>
                    <button type="submit" disabled={processing} className="px-5 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-amber-500 to-orange-600 rounded-xl hover:opacity-90 disabled:opacity-50 shadow-lg shadow-amber-500/20">Crear tarea</button>
                </div>
            </form>
        </Modal>
    );
}

function TaskDot({ task, onClick }) {
    const meta = TYPE_META[task.task_type] ?? TYPE_META.other;
    const overdue = !task.completed_at && new Date(task.due_at) < new Date();
    const done = !!task.completed_at;
    const cls = done ? 'bg-emerald-500 text-white' : overdue ? 'bg-red-500 text-white' : meta.color;

    return (
        <button
            type="button"
            onClick={onClick}
            title={task.text}
            className={`w-full text-left px-1.5 py-0.5 rounded text-[10px] font-semibold border truncate transition-all hover:brightness-95 ${cls}`}
        >
            <span className="mr-0.5">{meta.icon}</span>
            {new Date(task.due_at).toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' })} · {task.text}
        </button>
    );
}

function CalendarView({ calendar, onDayClick, onTaskClick, activeDay }) {
    const from = new Date(calendar.from);
    const to = new Date(calendar.to);
    const anchor = new Date(calendar.anchor + '-01');
    const today = new Date();

    // Generar todos los días entre from y to
    const days = useMemo(() => {
        const list = [];
        const cur = new Date(from);
        while (cur <= to) {
            list.push(new Date(cur));
            cur.setDate(cur.getDate() + 1);
        }
        return list;
    }, [calendar.from, calendar.to]);

    // Agrupar tareas por yyyy-mm-dd
    const tasksByDay = useMemo(() => {
        const map = {};
        (calendar.tasks || []).forEach((t) => {
            const k = ymd(new Date(t.due_at));
            (map[k] = map[k] || []).push(t);
        });
        return map;
    }, [calendar.tasks]);

    return (
        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            {/* Header de días de la semana */}
            <div className="grid grid-cols-7 border-b border-gray-100 bg-gray-50/60">
                {WEEKDAYS.map((d) => (
                    <div key={d} className="px-2 py-2 text-[11px] font-bold uppercase tracking-wider text-gray-500 text-center">{d}</div>
                ))}
            </div>
            {/* Grid de días */}
            <div className="grid grid-cols-7 auto-rows-fr">
                {days.map((d) => {
                    const key = ymd(d);
                    const dayTasks = tasksByDay[key] || [];
                    const inMonth = d.getMonth() === anchor.getMonth();
                    const isToday = sameDate(d, today);
                    const isActive = activeDay && sameDate(d, activeDay);
                    const overdue = dayTasks.filter((t) => !t.completed_at && new Date(t.due_at) < new Date()).length;

                    return (
                        <button
                            key={key}
                            type="button"
                            onClick={() => onDayClick(d)}
                            className={`min-h-[96px] p-1.5 border-r border-b border-gray-50 text-left flex flex-col gap-1 transition-colors ${!inMonth ? 'bg-gray-50/50' : 'bg-white hover:bg-emerald-50/40'} ${isActive ? 'ring-2 ring-emerald-500 ring-inset bg-emerald-50/60' : ''}`}
                        >
                            <div className="flex items-center justify-between">
                                <span className={`text-xs font-bold tabular-nums w-6 h-6 rounded-full flex items-center justify-center ${isToday ? 'bg-[#045474] text-white' : inMonth ? 'text-gray-800' : 'text-gray-400'}`}>
                                    {d.getDate()}
                                </span>
                                {overdue > 0 && (
                                    <span className="text-[9px] font-bold px-1 py-0 rounded-full bg-red-500 text-white">{overdue}</span>
                                )}
                            </div>
                            <div className="flex flex-col gap-0.5 overflow-hidden">
                                {dayTasks.slice(0, 3).map((t) => <TaskDot key={t.id} task={t} onClick={(e) => { e.stopPropagation(); onTaskClick(t); }} />)}
                                {dayTasks.length > 3 && (
                                    <span className="text-[9px] text-gray-500 font-semibold px-1">+{dayTasks.length - 3} más</span>
                                )}
                            </div>
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

function DayPanel({ day, tasks, onClose, onAddClick }) {
    if (!day) return null;
    const dayTasks = tasks.filter((t) => ymd(new Date(t.due_at)) === ymd(day)).sort((a, b) => new Date(a.due_at) - new Date(b.due_at));
    const label = day.toLocaleDateString('es', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

    return (
        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div className="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-2">
                <div>
                    <h3 className="text-sm font-bold text-gray-900 capitalize">{label}</h3>
                    <p className="text-xs text-gray-400 mt-0.5">{dayTasks.length} tarea{dayTasks.length !== 1 ? 's' : ''}</p>
                </div>
                <div className="flex items-center gap-1">
                    <button
                        onClick={() => onAddClick(day)}
                        title="Nueva tarea este día"
                        className="p-1.5 rounded-lg text-emerald-600 hover:bg-emerald-50"
                    >
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    </button>
                    <button onClick={onClose} className="p-1.5 rounded-lg text-gray-400 hover:bg-gray-100">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>
            </div>
            {dayTasks.length === 0 ? (
                <div className="p-8 text-center">
                    <p className="text-sm text-gray-400 mb-3">Sin tareas este día</p>
                    <button onClick={() => onAddClick(day)} className="text-xs font-bold text-emerald-600 hover:text-emerald-700">+ Crear una</button>
                </div>
            ) : (
                <ul className="divide-y divide-gray-50 max-h-[500px] overflow-y-auto">
                    {dayTasks.map((task) => {
                        const meta = TYPE_META[task.task_type] ?? TYPE_META.other;
                        const overdue = !task.completed_at && new Date(task.due_at) < new Date();
                        return (
                            <li key={task.id} className={`flex items-start gap-3 px-5 py-3 ${overdue ? 'bg-red-50/40' : ''}`}>
                                {!task.completed_at ? (
                                    <button
                                        onClick={() => {
                                            const note = prompt('Resultado (opcional):');
                                            if (note !== null) router.post(route('tasks.complete', task.id), { result_note: note || null }, { preserveScroll: true });
                                        }}
                                        className="mt-0.5 w-5 h-5 shrink-0 rounded-full border-2 border-gray-300 hover:border-emerald-500 hover:bg-emerald-50 transition-all"
                                        title="Completar"
                                    />
                                ) : (
                                    <span className="mt-0.5 w-5 h-5 shrink-0 rounded-full bg-emerald-500 text-white flex items-center justify-center text-[10px]">✓</span>
                                )}
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2 mb-0.5">
                                        <span className={`text-[9px] font-bold px-1.5 py-0.5 rounded-full border ${meta.color}`}>{meta.icon} {meta.label}</span>
                                        <span className={`text-xs font-bold tabular-nums ${overdue ? 'text-red-600' : 'text-gray-500'}`}>
                                            {new Date(task.due_at).toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' })}
                                        </span>
                                        {overdue && <span className="text-[9px] font-bold text-red-500 uppercase">vencida</span>}
                                    </div>
                                    <p className={`text-sm ${task.completed_at ? 'line-through text-gray-400' : 'text-gray-900'}`}>{task.text}</p>
                                    <p className="text-[11px] text-gray-400 mt-0.5 truncate">
                                        {task.lead ? <Link href={route('leads.show', task.lead.id)} className="text-emerald-600 hover:underline">→ {task.lead.title}</Link> : (task.contact?.name ?? 'Sin lead')}
                                        {task.assignee && ` · 👤 ${task.assignee.name}`}
                                        {task.result_note && ` · ${task.result_note}`}
                                    </p>
                                </div>
                                <button
                                    onClick={() => { if (confirm('¿Eliminar esta tarea?')) router.delete(route('tasks.destroy', task.id), { preserveScroll: true }); }}
                                    className="p-1 text-gray-300 hover:text-red-600 hover:bg-red-50 rounded shrink-0"
                                >
                                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166" /></svg>
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}

function ListView({ tasks }) {
    return (
        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <ul className="divide-y divide-gray-50">
                {tasks.data.map((task) => {
                    const meta = TYPE_META[task.task_type] ?? TYPE_META.other;
                    const overdue = !task.completed_at && new Date(task.due_at) < new Date();
                    return (
                        <li key={task.id} className={`flex items-center gap-4 px-5 py-4 ${overdue ? 'bg-red-50/40' : ''}`}>
                            {!task.completed_at ? (
                                <button
                                    onClick={() => { const note = prompt('Resultado (opcional):'); if (note !== null) router.post(route('tasks.complete', task.id), { result_note: note || null }, { preserveScroll: true }); }}
                                    className="w-5 h-5 shrink-0 rounded-full border-2 border-gray-300 hover:border-emerald-500 hover:bg-emerald-50 transition-all"
                                />
                            ) : (
                                <span className="w-5 h-5 shrink-0 rounded-full bg-emerald-500 text-white flex items-center justify-center text-[10px]">✓</span>
                            )}
                            <span className="text-lg shrink-0">{meta.icon}</span>
                            <div className="flex-1 min-w-0">
                                <p className={`text-sm font-medium ${task.completed_at ? 'line-through text-gray-400' : 'text-gray-900'}`}>{task.text}</p>
                                <p className="text-xs text-gray-400 truncate">
                                    {task.lead ? <Link href={route('leads.show', task.lead.id)} className="text-emerald-600 hover:underline font-medium">{task.lead.title}</Link> : (task.contact?.name ?? 'Sin lead')}
                                    {' · '}{task.assignee?.name ?? '—'}
                                    {task.result_note && ` · ${task.result_note}`}
                                </p>
                            </div>
                            <div className="text-right shrink-0">
                                <p className={`text-xs font-bold tabular-nums ${overdue ? 'text-red-500' : 'text-gray-500'}`}>
                                    {new Date(task.due_at).toLocaleString('es', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}
                                </p>
                                {overdue && <p className="text-[10px] font-bold text-red-400 uppercase">vencida</p>}
                            </div>
                            <button
                                onClick={() => { if (confirm('¿Eliminar esta tarea?')) router.delete(route('tasks.destroy', task.id), { preserveScroll: true }); }}
                                className="p-1.5 text-gray-300 hover:text-red-600 hover:bg-red-50 rounded-lg shrink-0"
                            >
                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166" /></svg>
                            </button>
                        </li>
                    );
                })}
                {tasks.data.length === 0 && (
                    <li className="px-5 py-14 text-center text-sm text-gray-400">Nada por aquí 🎉</li>
                )}
            </ul>
            {(tasks.prev_page_url || tasks.next_page_url) && (
                <div className="px-5 py-4 bg-gray-50/80 border-t border-gray-100 flex justify-end gap-3 text-sm">
                    {tasks.prev_page_url && <Link href={tasks.prev_page_url} className="text-emerald-600 font-medium">← Anterior</Link>}
                    {tasks.next_page_url && <Link href={tasks.next_page_url} className="text-emerald-600 font-medium">Siguiente →</Link>}
                </div>
            )}
        </div>
    );
}

export default function Index({ view = 'calendar', calendar, tasks, filters, counts, members = [] }) {
    const { flash } = usePage().props;
    const [activeDay, setActiveDay] = useState(view === 'calendar' ? new Date() : null);
    const [showNew, setShowNew] = useState(false);
    const [modalDefault, setModalDefault] = useState('');

    const apply = (patch) => router.get(route('tasks.index'), { ...filters, ...patch }, { preserveState: true, replace: true });

    const shiftMonth = (delta) => {
        const [y, m] = (calendar?.anchor || '').split('-').map(Number);
        const d = new Date(y, m - 1 + delta, 1);
        apply({ month: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}` });
    };

    const openNewForDay = (d) => {
        const dt = d || new Date();
        const local = new Date(dt.getTime() - dt.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
        setModalDefault(local);
        setShowNew(true);
    };

    const anchor = calendar ? new Date(calendar.anchor + '-01') : new Date();
    const monthLabel = `${MONTHS[anchor.getMonth()]} ${anchor.getFullYear()}`;

    return (
        <AuthenticatedLayout>
            <Head title="Agenda" />

            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-5">
                <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Agenda</h1>
                        <p className="text-sm text-gray-500 mt-1 flex flex-wrap gap-x-3 items-center">
                            <span>Calendario de tareas y seguimientos</span>
                            {counts.today > 0 && (<><span className="text-gray-300">·</span><span className="inline-flex items-center px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 text-[11px] font-bold">{counts.today} hoy</span></>)}
                            {counts.overdue > 0 && (<><span className="text-gray-300">·</span><span className="inline-flex items-center px-2 py-0.5 rounded-full bg-red-100 text-red-700 text-[11px] font-bold">{counts.overdue} vencidas</span></>)}
                        </p>
                    </div>
                    <div className="flex items-center gap-2 flex-wrap">
                        <label className="flex items-center gap-2 text-sm text-gray-600">
                            <input
                                type="checkbox"
                                checked={filters.mine}
                                onChange={(e) => apply({ mine: e.target.checked ? 1 : 0 })}
                                className="w-4 h-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500"
                            />
                            Solo mías
                        </label>
                        {/* Toggle vista */}
                        <div className="inline-flex bg-white border border-gray-200 rounded-xl shadow-sm p-0.5">
                            <button onClick={() => apply({ view: 'calendar' })} className={`px-3 py-1.5 rounded-lg text-xs font-semibold inline-flex items-center gap-1 ${view === 'calendar' ? 'bg-gradient-to-r from-[#045474] to-[#1c486c] text-white shadow' : 'text-gray-600'}`}>
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                                Calendario
                            </button>
                            <button onClick={() => apply({ view: 'list' })} className={`px-3 py-1.5 rounded-lg text-xs font-semibold inline-flex items-center gap-1 ${view === 'list' ? 'bg-gradient-to-r from-[#045474] to-[#1c486c] text-white shadow' : 'text-gray-600'}`}>
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 12h.007v.008H3.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 17.25h.007v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" /></svg>
                                Lista
                            </button>
                        </div>
                        <button onClick={() => openNewForDay(activeDay)} className="px-4 py-2 text-sm font-semibold text-white bg-gradient-to-r from-amber-500 to-orange-600 rounded-xl hover:opacity-90 shadow-lg shadow-amber-500/20 inline-flex items-center gap-1.5">
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            Nueva tarea
                        </button>
                    </div>
                </div>

                {flash?.success && (
                    <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 shadow-sm">{flash.success}</div>
                )}

                {view === 'calendar' ? (
                    <>
                        {/* Nav de mes */}
                        <div className="flex items-center justify-between bg-white rounded-xl border border-gray-100 shadow-sm p-3">
                            <button onClick={() => shiftMonth(-1)} className="p-2 rounded-lg text-gray-500 hover:bg-gray-100">
                                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
                            </button>
                            <div className="flex items-center gap-3">
                                <h2 className="text-lg font-bold text-gray-900 capitalize tabular-nums">{monthLabel}</h2>
                                <button onClick={() => apply({ month: `${new Date().getFullYear()}-${String(new Date().getMonth() + 1).padStart(2, '0')}` })} className="text-xs font-bold text-emerald-600 hover:text-emerald-700 px-2 py-1 rounded-lg border border-emerald-200 bg-emerald-50">Hoy</button>
                            </div>
                            <button onClick={() => shiftMonth(1)} className="p-2 rounded-lg text-gray-500 hover:bg-gray-100">
                                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                            </button>
                        </div>

                        <div className="grid gap-5 lg:grid-cols-3">
                            <div className="lg:col-span-2">
                                <CalendarView
                                    calendar={calendar}
                                    onDayClick={(d) => setActiveDay(d)}
                                    onTaskClick={(t) => setActiveDay(new Date(t.due_at))}
                                    activeDay={activeDay}
                                />
                                {/* Leyenda */}
                                <div className="mt-3 flex flex-wrap gap-3 text-[11px] text-gray-500 px-1">
                                    {Object.entries(TYPE_META).map(([k, v]) => (
                                        <span key={k} className="inline-flex items-center gap-1"><span className={`w-2.5 h-2.5 rounded ${v.color.split(' ')[0]}`} />{v.label}</span>
                                    ))}
                                    <span className="inline-flex items-center gap-1"><span className="w-2.5 h-2.5 rounded bg-red-500" /> Vencida</span>
                                    <span className="inline-flex items-center gap-1"><span className="w-2.5 h-2.5 rounded bg-emerald-500" /> Completada</span>
                                </div>
                            </div>
                            <div className="lg:col-span-1">
                                <DayPanel
                                    day={activeDay}
                                    tasks={calendar?.tasks || []}
                                    onClose={() => setActiveDay(null)}
                                    onAddClick={openNewForDay}
                                />
                            </div>
                        </div>
                    </>
                ) : (
                    <>
                        <div className="flex gap-1.5 flex-wrap">
                            {[['pending', 'Pendientes'], ['today', `Hoy${counts.today ? ` (${counts.today})` : ''}`], ['overdue', `Vencidas${counts.overdue ? ` (${counts.overdue})` : ''}`], ['done', 'Completadas']].map(([key, label]) => (
                                <button
                                    key={key}
                                    onClick={() => apply({ filter: key })}
                                    className={`px-3.5 py-2 rounded-xl text-sm font-semibold transition-all ${filters.filter === key ? 'bg-gradient-to-r from-[#045474] to-[#1c486c] text-white shadow-lg shadow-[#045474]/20' : 'bg-white text-gray-600 border border-gray-200 hover:border-gray-300 shadow-sm'}`}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                        <ListView tasks={tasks} />
                    </>
                )}
            </div>

            <NewTaskModal
                open={showNew}
                onClose={() => setShowNew(false)}
                members={members}
                defaultDate={modalDefault}
            />
        </AuthenticatedLayout>
    );
}
