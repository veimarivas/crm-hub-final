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
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
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

                {broadcasts.length === 0 ? (
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-16 text-center">
                        <div className="w-14 h-14 mx-auto rounded-2xl bg-gray-50 flex items-center justify-center mb-3">
                            <svg className="w-6 h-6 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 110-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 01-1.44-4.282m3.102.069a18.03 18.03 0 01-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 018.835 2.535M10.34 6.66a23.847 23.847 0 008.835-2.535m0 0A23.74 23.74 0 0018.795 3m.38 1.125a23.91 23.91 0 011.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 001.014-5.395m0-3.46c.495.413.811 1.035.811 1.73 0 .695-.316 1.317-.811 1.73m0-3.46a24.347 24.347 0 010 3.46" /></svg>
                        </div>
                        <p className="text-sm text-gray-500 font-medium">Sin broadcasts todavía</p>
                        <p className="text-xs text-gray-400 mt-1">Creá el primero — usá una lista guardada del Kanban como destino</p>
                    </div>
                ) : (
                    <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                        {broadcasts.map((b) => {
                            const pct = b.total_recipients > 0 ? Math.round(((b.sent_count + b.failed_count) / b.total_recipients) * 100) : 0;
                            return (
                                <Link
                                    key={b.id}
                                    href={route('broadcasts.show', b.id)}
                                    className="bg-white rounded-2xl border border-gray-100 p-4 flex flex-col gap-3 transition-shadow hover:shadow-md group"
                                >
                                    <div className="flex items-center gap-2 min-w-0">
                                        <p className="text-sm font-bold text-gray-900 truncate group-hover:text-emerald-700 transition-colors">{b.name}</p>
                                        <StatusBadge status={b.status} />
                                    </div>
                                    <p className="text-xs text-gray-500 truncate">
                                        <svg className="w-3.5 h-3.5 inline-block shrink-0 text-gray-300 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" /></svg>
                                        {b.message}
                                    </p>
                                    <div className="mt-auto space-y-2.5 pt-1 border-t border-gray-50">
                                        <div className="flex items-center justify-between text-[11px] text-gray-500">
                                            <span className="font-semibold tabular-nums">{b.sent_count} / {b.total_recipients} enviados</span>
                                            <span className="tabular-nums">{pct}%</span>
                                        </div>
                                        <div className="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                                            <div className="h-full bg-gradient-to-r from-emerald-500 to-teal-600" style={{ width: `${pct}%` }} />
                                        </div>
                                        <div className="flex items-center justify-between text-[10px] text-gray-400">
                                            <span>{b.failed_count > 0 ? <span className="text-red-500 font-bold">{b.failed_count} fallidos</span> : 'Sin fallos'}</span>
                                            <span>{b.user?.name} · {new Date(b.created_at).toLocaleDateString('es', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</span>
                                        </div>
                                    </div>
                                </Link>
                            );
                        })}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}