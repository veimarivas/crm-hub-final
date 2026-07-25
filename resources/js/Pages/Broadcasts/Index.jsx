import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, usePage } from '@inertiajs/react';

function StatusBadge({ status }) {
    const map = {
        draft: { label: 'Borrador', color: 'bg-gray-100 text-gray-700 border-gray-200' },
        running: { label: 'Enviando', color: 'bg-amber-100 text-amber-700 border-amber-200 animate-pulse' },
        completed: { label: 'Completado', color: 'bg-emerald-100 text-emerald-700 border-emerald-200' },
        failed: { label: 'Fallido', color: 'bg-red-100 text-red-700 border-red-200' },
    };
    const s = map[status] ?? map.draft;
    return <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold border ${s.color}`}>{s.label}</span>;
}

export default function Index({ broadcasts }) {
    const { flash } = usePage().props;

    return (
        <AuthenticatedLayout>
            <Head title="Broadcasts" />
            <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-5">
                <div className="flex items-end justify-between gap-4 flex-wrap">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Broadcasts</h1>
                        <p className="text-sm text-gray-500 mt-1">Envío masivo de WhatsApp usando listas segmentadas</p>
                    </div>
                    <Link href={route('broadcasts.create')} className="px-4 py-2 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:opacity-90 shadow-lg shadow-emerald-500/20 inline-flex items-center gap-1.5">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        Nuevo broadcast
                    </Link>
                </div>

                {flash?.success && <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 shadow-sm">{flash.success}</div>}

                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    {broadcasts.length === 0 ? (
                        <div className="p-14 text-center">
                            <div className="w-14 h-14 mx-auto rounded-2xl bg-gray-50 flex items-center justify-center mb-3">
                                <svg className="w-6 h-6 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 110-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 01-1.44-4.282m3.102.069a18.03 18.03 0 01-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 018.835 2.535M10.34 6.66a23.847 23.847 0 008.835-2.535m0 0A23.74 23.74 0 0018.795 3m.38 1.125a23.91 23.91 0 011.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 001.014-5.395m0-3.46c.495.413.811 1.035.811 1.73 0 .695-.316 1.317-.811 1.73m0-3.46a24.347 24.347 0 010 3.46" /></svg>
                            </div>
                            <p className="text-sm text-gray-500 font-medium">Sin broadcasts todavía</p>
                            <p className="text-xs text-gray-400 mt-1">Creá el primero — usá una lista guardada del Kanban como destino</p>
                        </div>
                    ) : (
                        <ul className="divide-y divide-gray-50">
                            {broadcasts.map((b) => {
                                const pct = b.total_recipients > 0 ? Math.round(((b.sent_count + b.failed_count) / b.total_recipients) * 100) : 0;
                                return (
                                    <li key={b.id}>
                                        <Link href={route('broadcasts.show', b.id)} className="flex items-center gap-4 px-5 py-4 hover:bg-gray-50 transition-colors">
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center gap-2 mb-1">
                                                    <p className="text-sm font-bold text-gray-900 truncate">{b.name}</p>
                                                    <StatusBadge status={b.status} />
                                                </div>
                                                <p className="text-xs text-gray-500 truncate">{b.message}</p>
                                                <div className="flex items-center gap-3 mt-2">
                                                    <div className="flex-1 h-1.5 bg-gray-100 rounded-full overflow-hidden max-w-xs">
                                                        <div className="h-full bg-gradient-to-r from-emerald-500 to-teal-600" style={{ width: `${pct}%` }} />
                                                    </div>
                                                    <span className="text-[10px] font-bold text-gray-500 tabular-nums">
                                                        {b.sent_count} / {b.total_recipients}
                                                        {b.failed_count > 0 && <span className="text-red-500"> · {b.failed_count} fallidos</span>}
                                                    </span>
                                                </div>
                                            </div>
                                            <div className="text-right shrink-0">
                                                <p className="text-[11px] text-gray-500">{b.user?.name}</p>
                                                <p className="text-[10px] text-gray-400 mt-0.5">{new Date(b.created_at).toLocaleDateString('es', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</p>
                                            </div>
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
