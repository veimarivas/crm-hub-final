import { Head, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

function toLocalIso(date, time) {
    // date = 'YYYY-MM-DD', time = 'HH:MM' → 'YYYY-MM-DDTHH:MM:00'
    return `${date}T${time}:00`;
}

export default function BookingPage({ host, timezone, days }) {
    const [selectedDate, setSelectedDate] = useState(() => days[0]?.date || null);
    const [selectedTime, setSelectedTime] = useState(null);
    const [step, setStep] = useState('slot'); // slot | form

    const form = useForm({
        guest_name: '',
        guest_phone: '',
        guest_email: '',
        notes: '',
        scheduled_at: '',
    });

    const currentDay = days.find((d) => d.date === selectedDate);

    const pick = (date, time) => {
        setSelectedDate(date);
        setSelectedTime(time);
        form.setData('scheduled_at', toLocalIso(date, time));
        setStep('form');
    };

    const submit = (e) => {
        e.preventDefault();
        form.post(route('book.store', host.slug));
    };

    const inputClass = 'w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 focus:bg-white transition-all';

    return (
        <div className="min-h-screen bg-gradient-to-br from-[#042048] via-[#1c486c] to-[#045474] py-8 sm:py-12 px-4">
            <Head title={`Agendar con ${host.name}`} />
            <div className="mx-auto max-w-3xl">
                <div className="text-center mb-6">
                    <div className="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-white/10 backdrop-blur mb-3">
                        <span className="text-3xl">📅</span>
                    </div>
                    <h1 className="text-2xl sm:text-3xl font-bold text-white">Agendar reunión con {host.name}</h1>
                    <p className="text-sm text-white/70 mt-2">{host.duration} minutos · zona horaria {timezone}</p>
                </div>

                <div className="bg-white rounded-2xl shadow-2xl overflow-hidden">
                    {step === 'slot' ? (
                        days.length === 0 ? (
                            <div className="p-10 text-center">
                                <div className="w-16 h-16 mx-auto rounded-2xl bg-amber-50 flex items-center justify-center mb-3">
                                    <svg className="w-7 h-7 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126z" /></svg>
                                </div>
                                <p className="text-base font-bold text-gray-900">Sin horarios disponibles</p>
                                <p className="text-sm text-gray-500 mt-1">Volvé a intentar más tarde o contactá a {host.name} directamente</p>
                            </div>
                        ) : (
                            <div className="grid md:grid-cols-[220px_1fr]">
                                {/* Lista de días */}
                                <div className="border-b md:border-b-0 md:border-r border-gray-100 max-h-[420px] overflow-y-auto">
                                    <div className="px-4 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-400 border-b border-gray-100">Elegí un día</div>
                                    <ul>
                                        {days.map((d) => (
                                            <li key={d.date}>
                                                <button
                                                    onClick={() => setSelectedDate(d.date)}
                                                    className={`w-full text-left px-4 py-3 border-l-4 transition-colors ${selectedDate === d.date ? 'border-emerald-500 bg-emerald-50 text-emerald-900' : 'border-transparent hover:bg-gray-50'}`}
                                                >
                                                    <p className="text-sm font-bold capitalize">{d.label}</p>
                                                    <p className="text-[11px] text-gray-500 mt-0.5">{d.slots.length} horario{d.slots.length !== 1 ? 's' : ''} libre{d.slots.length !== 1 ? 's' : ''}</p>
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                                {/* Slots del día seleccionado */}
                                <div className="p-5">
                                    {currentDay ? (
                                        <>
                                            <p className="text-sm font-bold text-gray-900 capitalize mb-3">{currentDay.weekday} {new Date(currentDay.date).toLocaleDateString('es', { day: 'numeric', month: 'long' })}</p>
                                            <div className="grid grid-cols-3 sm:grid-cols-4 gap-2">
                                                {currentDay.slots.map((s) => (
                                                    <button
                                                        key={s}
                                                        onClick={() => pick(currentDay.date, s)}
                                                        className="px-3 py-2 rounded-lg text-sm font-bold text-gray-700 bg-gray-50 hover:bg-emerald-500 hover:text-white border border-gray-200 hover:border-emerald-500 transition-all tabular-nums"
                                                    >
                                                        {s}
                                                    </button>
                                                ))}
                                            </div>
                                        </>
                                    ) : (
                                        <p className="text-sm text-gray-400 text-center py-8">Elegí un día para ver horarios</p>
                                    )}
                                </div>
                            </div>
                        )
                    ) : (
                        <form onSubmit={submit} className="p-5 sm:p-6 space-y-4">
                            <div className="flex items-center gap-3 pb-4 border-b border-gray-100">
                                <button type="button" onClick={() => setStep('slot')} className="p-2 rounded-lg text-gray-500 hover:bg-gray-100">
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
                                </button>
                                <div>
                                    <p className="text-xs text-gray-500">Horario elegido</p>
                                    <p className="text-sm font-bold text-gray-900 capitalize">
                                        {new Date(selectedDate).toLocaleDateString('es', { weekday: 'long', day: 'numeric', month: 'long' })} · {selectedTime}
                                    </p>
                                </div>
                            </div>

                            <div>
                                <label className="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Tu nombre *</label>
                                <input required maxLength={120} value={form.data.guest_name} onChange={(e) => form.setData('guest_name', e.target.value)} className={inputClass} />
                                {form.errors.guest_name && <p className="mt-1 text-xs text-red-500 font-medium">{form.errors.guest_name}</p>}
                            </div>
                            <div className="grid sm:grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Teléfono *</label>
                                    <input required maxLength={32} value={form.data.guest_phone} onChange={(e) => form.setData('guest_phone', e.target.value)} placeholder="+591 71234567" className={inputClass} />
                                    {form.errors.guest_phone && <p className="mt-1 text-xs text-red-500 font-medium">{form.errors.guest_phone}</p>}
                                </div>
                                <div>
                                    <label className="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Email (opcional)</label>
                                    <input type="email" maxLength={150} value={form.data.guest_email} onChange={(e) => form.setData('guest_email', e.target.value)} className={inputClass} />
                                </div>
                            </div>
                            <div>
                                <label className="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Notas (opcional)</label>
                                <textarea rows={3} maxLength={2000} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} placeholder="¿Sobre qué te gustaría conversar?" className={inputClass} />
                            </div>

                            <button type="submit" disabled={form.processing} className="w-full px-5 py-3 text-sm font-bold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:opacity-90 disabled:opacity-50 shadow-lg shadow-emerald-500/20">
                                {form.processing ? 'Reservando…' : `Confirmar reserva`}
                            </button>
                        </form>
                    )}
                </div>

                <p className="text-center text-[11px] text-white/50 mt-6">Powered by Komo CRM</p>
            </div>
        </div>
    );
}
