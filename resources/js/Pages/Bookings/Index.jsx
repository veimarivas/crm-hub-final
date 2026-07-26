import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

function StatusBadge({ status }) {
    const map = {
        confirmed: { label: 'Confirmada', color: 'bg-emerald-100 text-emerald-700 border-emerald-200' },
        cancelled: { label: 'Cancelada', color: 'bg-gray-100 text-gray-500 border-gray-200' },
        completed: { label: 'Completada', color: 'bg-sky-100 text-sky-700 border-sky-200' },
    };
    const s = map[status] ?? map.confirmed;
    return <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold border ${s.color}`}>{s.label}</span>;
}

export default function BookingsIndex({ bookings, showAll, isAdmin, bookingUrl, bookingEnabled, slug }) {
    const { flash } = usePage().props;
    const [copied, setCopied] = useState(false);

    const copyUrl = () => {
        if (!bookingUrl) return;
        navigator.clipboard.writeText(bookingUrl);
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };

    return (
        <AuthenticatedLayout>
            <Head title="Reservas" />
            <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-5">
                <div className="flex items-end justify-between gap-4 flex-wrap">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Reservas</h1>
                        <p className="text-sm text-gray-500 mt-1">Reuniones agendadas por clientes a través de tu link público</p>
                    </div>
                    {isAdmin && (
                        <label className="flex items-center gap-2 text-sm text-gray-600">
                            <input
                                type="checkbox"
                                checked={showAll}
                                onChange={(e) => router.get(route('bookings.index'), { all: e.target.checked ? 1 : 0 }, { preserveState: true })}
                                className="w-4 h-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500"
                            />
                            Ver todo el equipo
                        </label>
                    )}
                </div>

                {flash?.success && <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 shadow-sm">{flash.success}</div>}

                {/* Link público del user */}
                {bookingEnabled && bookingUrl ? (
                    <div className="bg-gradient-to-br from-emerald-50 to-teal-50 border-2 border-emerald-200 rounded-2xl p-5">
                        <div className="flex items-center gap-3 mb-3">
                            <div className="w-10 h-10 rounded-xl bg-emerald-500 flex items-center justify-center text-white shadow-md">
                                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" /></svg>
                            </div>
                            <div>
                                <h3 className="text-base font-bold text-gray-900">Tu link público</h3>
                                <p className="text-xs text-gray-500 mt-0.5">Compartilo por WhatsApp, email o en tu firma para que agenden solos</p>
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <input readOnly value={bookingUrl} className="flex-1 px-3.5 py-2 bg-white border border-emerald-200 rounded-lg text-sm font-mono text-gray-800" onFocus={(e) => e.target.select()} />
                            <button onClick={copyUrl} className="px-4 py-2 rounded-lg text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-500 shadow inline-flex items-center gap-1.5">
                                {copied ? '✓ Copiado' : (<><svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 01-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 00-3.375-3.375h-1.5a1.125 1.125 0 01-1.125-1.125v-1.5a3.375 3.375 0 00-3.375-3.375H9.75" /></svg>Copiar</>)}
                            </button>
                            <a href={bookingUrl} target="_blank" rel="noreferrer" className="px-4 py-2 rounded-lg text-xs font-bold text-emerald-700 bg-white border border-emerald-200 hover:bg-emerald-50 inline-flex items-center gap-1.5">
                                Abrir ↗
                            </a>
                        </div>
                        <p className="text-[11px] text-gray-500 mt-2">
                            Los slots libres se calculan según el <Link href={route('settings.business-hours')} className="text-emerald-600 hover:underline font-semibold">horario de atención</Link> de la cuenta.
                        </p>
                    </div>
                ) : (
                    <div className="bg-amber-50 border-2 border-dashed border-amber-200 rounded-2xl p-5">
                        <div className="flex items-start gap-3">
                            <span className="text-2xl">📅</span>
                            <div className="flex-1">
                                <h3 className="text-sm font-bold text-gray-900">Activá tu link de reservas</h3>
                                <p className="text-xs text-gray-600 mt-1">Andá a <Link href={route('profile.edit')} className="text-emerald-600 hover:underline font-semibold">tu perfil</Link>, activá el toggle "Aceptar reservas" y elegí un slug (ej. <code className="bg-white px-1 rounded">tu-nombre</code>).</p>
                            </div>
                        </div>
                    </div>
                )}

                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div className="px-5 py-4 border-b border-gray-100">
                        <h3 className="text-base font-bold text-gray-900">
                            {showAll ? 'Todas las reservas' : 'Mis reservas'}
                            <span className="ml-2 text-xs font-medium text-gray-400 tabular-nums">{bookings.length}</span>
                        </h3>
                    </div>
                    {bookings.length === 0 ? (
                        <div className="p-12 text-center">
                            <div className="w-14 h-14 mx-auto rounded-2xl bg-gray-50 flex items-center justify-center mb-3">
                                <svg className="w-6 h-6 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25" /></svg>
                            </div>
                            <p className="text-sm text-gray-500 font-medium">Sin reservas todavía</p>
                            <p className="text-xs text-gray-400 mt-1">Cuando alguien reserve por tu link va a aparecer acá</p>
                        </div>
                    ) : (
                        <ul className="divide-y divide-gray-50">
                            {bookings.map((b) => {
                                const when = new Date(b.scheduled_at);
                                const past = when < new Date();
                                return (
                                    <li key={b.id} className={`flex items-center gap-4 px-5 py-4 ${past ? 'opacity-60' : ''}`}>
                                        <div className={`w-14 shrink-0 text-center rounded-xl p-2 ${b.status === 'cancelled' ? 'bg-gray-100' : 'bg-emerald-50'}`}>
                                            <div className="text-[10px] font-bold uppercase text-gray-500">{when.toLocaleDateString('es', { month: 'short' })}</div>
                                            <div className={`text-xl font-extrabold tabular-nums ${b.status === 'cancelled' ? 'text-gray-500' : 'text-emerald-700'}`}>{when.getDate()}</div>
                                            <div className="text-[10px] font-bold text-gray-500 tabular-nums">{when.toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' })}</div>
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2 mb-0.5">
                                                <p className="text-sm font-bold text-gray-900 truncate">{b.guest_name}</p>
                                                <StatusBadge status={b.status} />
                                            </div>
                                            <p className="text-xs text-gray-500 truncate">
                                                <span className="font-mono">{b.guest_phone}</span>
                                                {b.guest_email && <span> · {b.guest_email}</span>}
                                                <span> · {b.duration_min} min</span>
                                            </p>
                                            {b.notes && <p className="text-[11px] text-gray-500 italic mt-1 truncate">"{b.notes}"</p>}
                                            <div className="flex items-center gap-3 mt-1 text-[10px] text-gray-400">
                                                {showAll && b.host && <span>👤 {b.host.name}</span>}
                                                {b.lead && <Link href={route('leads.show', b.lead.id)} className="text-emerald-600 hover:underline font-semibold">→ Ver lead</Link>}
                                            </div>
                                        </div>
                                        {b.status === 'confirmed' && !past && (
                                            <button
                                                onClick={() => { if (confirm('¿Cancelar esta reserva?')) router.post(route('bookings.cancel', b.id), {}, { preserveScroll: true }); }}
                                                className="p-2 text-gray-300 hover:text-red-600 hover:bg-red-50 rounded-lg shrink-0"
                                                title="Cancelar"
                                            >
                                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                            </button>
                                        )}
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
