import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect } from 'react';

const STATUS_ROW = {
    pending: { label: 'Pendiente', color: 'bg-gray-100 text-gray-600 ring-gray-200' },
    sent: { label: 'Enviado', color: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    failed: { label: 'Fallido', color: 'bg-red-50 text-red-700 ring-red-200' },
};

export default function Show({ broadcast, recipients }) {
    useEffect(() => {
        if (broadcast.status !== 'running') return;
        const id = setInterval(() => router.reload({ only: ['broadcast', 'recipients'] }), 4000);
        return () => clearInterval(id);
    }, [broadcast.status]);

    const pct = broadcast.total_recipients > 0 ? Math.round(((broadcast.sent_count + broadcast.failed_count) / broadcast.total_recipients) * 100) : 0;
    const successPct = broadcast.total_recipients > 0 ? Math.round((broadcast.sent_count / broadcast.total_recipients) * 100) : 0;

    const statusBadge =
        broadcast.status === 'completed' ? { label: '✓ Completado', cls: 'bg-emerald-50 text-emerald-700 border-emerald-200' }
        : broadcast.status === 'running' ? { label: '⏳ Enviando…', cls: 'bg-amber-50 text-amber-700 border-amber-200 animate-pulse' }
        : { label: broadcast.status, cls: 'bg-gray-100 text-gray-600 border-gray-200' };

    return (
        <AuthenticatedLayout>
            <Head title={broadcast.name} />
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-5">
                <div>
                    <Link href={route('broadcasts.index')} className="text-sm text-emerald-600 hover:text-emerald-700 font-medium inline-flex items-center gap-1">← Volver a broadcasts</Link>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-5 items-start">
                    {/* Columna izquierda — resumen */}
                    <div className="lg:col-span-1 space-y-5">
                        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                            <div className={`h-1.5 ${broadcast.status === 'completed' ? 'bg-gradient-to-r from-emerald-500 to-teal-600' : broadcast.status === 'running' ? 'bg-gradient-to-r from-amber-400 to-orange-500 animate-pulse' : 'bg-gray-200'}`} />
                            <div className="p-5 sm:p-6">
                                <div className="flex items-start justify-between gap-3 mb-4">
                                    <div className="min-w-0 flex-1">
                                        <h1 className="text-xl font-bold text-gray-900">{broadcast.name}</h1>
                                        <p className="text-xs text-gray-500 mt-1">Por {broadcast.user?.name}</p>
                                        <p className="text-[11px] text-gray-400">{new Date(broadcast.created_at).toLocaleString('es', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</p>
                                    </div>
                                    <span className={`inline-flex items-center px-3 py-1 rounded-full text-xs font-bold border shrink-0 ${statusBadge.cls}`}>{statusBadge.label}</span>
                                </div>

                                <div className="space-y-3">
                                    <div className="grid grid-cols-3 gap-3">
                                        <div className="p-3 rounded-xl bg-gray-50 text-center">
                                            <div className="text-2xl font-extrabold text-gray-900 tabular-nums">{broadcast.total_recipients}</div>
                                            <div className="text-[10px] font-bold text-gray-500 uppercase tracking-wide mt-1">Total</div>
                                        </div>
                                        <div className="p-3 rounded-xl bg-emerald-50 text-center">
                                            <div className="text-2xl font-extrabold text-emerald-700 tabular-nums">{broadcast.sent_count}</div>
                                            <div className="text-[10px] font-bold text-emerald-600 uppercase tracking-wide mt-1">{successPct}%</div>
                                        </div>
                                        <div className="p-3 rounded-xl bg-red-50 text-center">
                                            <div className="text-2xl font-extrabold text-red-700 tabular-nums">{broadcast.failed_count}</div>
                                            <div className="text-[10px] font-bold text-red-600 uppercase tracking-wide mt-1">Fallidos</div>
                                        </div>
                                    </div>

                                    <div>
                                        <div className="h-2 bg-gray-100 rounded-full overflow-hidden">
                                            <div className="h-full bg-gradient-to-r from-emerald-500 to-teal-600 transition-all" style={{ width: `${pct}%` }} />
                                        </div>
                                        <p className="text-[11px] text-gray-500 text-center mt-1.5 tabular-nums">
                                            {pct}% procesado {broadcast.status === 'running' && <span className="text-amber-600 font-semibold">· actualizando cada 4s</span>}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                            <div className="px-5 pt-5 pb-4">
                                <p className="text-xs font-bold text-gray-500 uppercase tracking-wide mb-2">Mensaje enviado</p>
                                {broadcast.media_path && (
                                    <img
                                        src={route('broadcasts.media', broadcast.id)}
                                        alt="Imagen del broadcast"
                                        className="w-full rounded-xl border border-gray-200 mb-3 shadow-sm object-cover"
                                    />
                                )}
                                <div className="p-3 rounded-xl bg-emerald-50 border border-emerald-100 text-sm text-gray-800 whitespace-pre-wrap">
                                    {broadcast.message}
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Columna derecha — destinatarios */}
                    <div className="lg:col-span-2 bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="px-5 sm:px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-4">
                            <div>
                                <h3 className="text-base font-bold text-gray-900">Destinatarios</h3>
                                <p className="text-xs text-gray-400 mt-0.5">Fallidos y pendientes primero · máximo 200 mostrados</p>
                            </div>
                            <span className="text-xs font-bold text-gray-400 tabular-nums">{recipients.length}</span>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="bg-gray-50/80">
                                        <th className="text-left px-5 sm:px-6 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Estado</th>
                                        <th className="text-left px-5 sm:px-6 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Destinatario</th>
                                        <th className="text-right px-5 sm:px-6 py-3 text-[11px] font-bold text-gray-500 uppercase tracking-wider">Enviado</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-50">
                                    {recipients.map((r) => {
                                        const s = STATUS_ROW[r.status] ?? STATUS_ROW.pending;
                                        return (
                                            <tr key={r.id} className="hover:bg-gray-50 transition-colors">
                                                <td className="px-5 sm:px-6 py-3">
                                                    <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold ring-1 ${s.color}`}>{s.label}</span>
                                                </td>
                                                <td className="px-5 sm:px-6 py-3">
                                                    <div className="flex flex-col min-w-0">
                                                        <span className="font-semibold text-gray-800 truncate">
                                                            {r.contact?.name || r.phone_normalized}
                                                            <span className="text-xs text-gray-400 font-mono ml-2">{r.phone_normalized}</span>
                                                        </span>
                                                        {r.lead && (
                                                            <Link href={route('leads.show', r.lead.id)} className="text-[11px] text-emerald-600 hover:underline">
                                                                → {r.lead.title}
                                                            </Link>
                                                        )}
                                                        {r.error && <span className="text-[11px] text-red-600 mt-0.5 truncate" title={r.error}>❌ {r.error}</span>}
                                                    </div>
                                                </td>
                                                <td className="px-5 sm:px-6 py-3 text-right text-[11px] text-gray-400 tabular-nums whitespace-nowrap">
                                                    {r.sent_at ? new Date(r.sent_at).toLocaleString('es', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—'}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                    {recipients.length === 0 && (
                                        <tr><td colSpan={3} className="px-5 py-12 text-center text-sm text-gray-400">Sin destinatarios mostrados.</td></tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}