import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';

const DAYS = [
    { key: 'mon', label: 'Lunes' },
    { key: 'tue', label: 'Martes' },
    { key: 'wed', label: 'Miércoles' },
    { key: 'thu', label: 'Jueves' },
    { key: 'fri', label: 'Viernes' },
    { key: 'sat', label: 'Sábado' },
    { key: 'sun', label: 'Domingo' },
];

function Toggle({ value, onChange, disabled = false }) {
    return (
        <button
            type="button"
            onClick={() => !disabled && onChange(!value)}
            disabled={disabled}
            className={`relative inline-flex h-7 w-14 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-emerald-500/30 ${value ? 'bg-emerald-500' : 'bg-gray-300'} ${disabled ? 'opacity-40 cursor-not-allowed' : ''}`}
            role="switch"
            aria-checked={value}
        >
            <span className={`inline-block h-5 w-5 transform rounded-full bg-white shadow transition ${value ? 'translate-x-8' : 'translate-x-1'}`} />
        </button>
    );
}

export default function BusinessHoursPage({ settings, isOpenNow, timezones }) {
    const form = useForm({
        business_hours_enabled: settings.business_hours_enabled,
        out_of_hours_reply_enabled: settings.out_of_hours_reply_enabled,
        out_of_hours_message: settings.out_of_hours_message,
        business_hours_timezone: settings.business_hours_timezone,
        schedule: settings.schedule,
    });

    const submit = (e) => {
        e.preventDefault();
        form.patch(route('settings.business-hours.update'), { preserveScroll: true });
    };

    const setSlot = (day, field, value) => {
        form.setData('schedule', {
            ...form.data.schedule,
            [day]: {
                from: field === 'from' ? value : form.data.schedule[day]?.from || '09:00',
                to: field === 'to' ? value : form.data.schedule[day]?.to || '18:00',
            },
        });
    };

    const toggleDay = (day) => {
        const current = form.data.schedule[day];
        form.setData('schedule', {
            ...form.data.schedule,
            [day]: current ? null : { from: '09:00', to: '18:00' },
        });
    };

    const copyToAllWeekdays = (day) => {
        const source = form.data.schedule[day];
        if (!source) return;
        const updated = { ...form.data.schedule };
        ['mon', 'tue', 'wed', 'thu', 'fri'].forEach((d) => {
            updated[d] = { ...source };
        });
        form.setData('schedule', updated);
    };

    return (
        <AuthenticatedLayout>
            <Head title="Horario de atención" />

            <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8">
                <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4 mb-6">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900">Horario de atención</h1>
                        <p className="text-sm text-gray-500 mt-1">Define cuándo tu equipo está disponible y qué responder fuera de hora</p>
                    </div>
                    <div>
                        {form.data.business_hours_enabled && (
                            <span className={`inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-sm font-bold ${isOpenNow ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-700 border border-slate-300'}`}>
                                <span className={`w-2 h-2 rounded-full ${isOpenNow ? 'bg-emerald-500 animate-pulse' : 'bg-slate-400'}`} />
                                {isOpenNow ? 'Abiertos ahora' : 'Cerrados ahora'}
                            </span>
                        )}
                    </div>
                </div>

                {form.recentlySuccessful && (
                    <div className="mb-4 rounded-xl bg-emerald-50 border border-emerald-200 px-4 py-2.5 text-sm font-medium text-emerald-800">
                        ✓ Cambios guardados
                    </div>
                )}

                <form onSubmit={submit} className="space-y-5">
                    {/* Card: activar horario */}
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 sm:p-6">
                        <div className="flex items-start justify-between gap-4">
                            <div className="min-w-0">
                                <h3 className="text-base font-bold text-gray-900 flex items-center gap-2">🕐 Activar horario de atención</h3>
                                <p className="text-xs text-gray-500 mt-1">Si está desactivado, se considera que atendés 24/7 y nunca se envía auto-respuesta.</p>
                            </div>
                            <Toggle value={form.data.business_hours_enabled} onChange={(v) => form.setData('business_hours_enabled', v)} />
                        </div>
                    </div>

                    {/* Card: schedule semanal */}
                    <div className={`bg-white rounded-2xl shadow-sm border border-gray-100 p-5 sm:p-6 transition-opacity ${!form.data.business_hours_enabled ? 'opacity-50' : ''}`}>
                        <h3 className="text-base font-bold text-gray-900 mb-1">Horario semanal</h3>
                        <p className="text-xs text-gray-500 mb-4">Los mensajes que entren fuera de estas franjas se consideran fuera de hora.</p>

                        <div className="mb-4">
                            <label className="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Zona horaria</label>
                            <select
                                value={form.data.business_hours_timezone}
                                onChange={(e) => form.setData('business_hours_timezone', e.target.value)}
                                disabled={!form.data.business_hours_enabled}
                                className="w-full sm:w-72 px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 focus:bg-white"
                            >
                                {timezones.map((tz) => <option key={tz} value={tz}>{tz}</option>)}
                            </select>
                        </div>

                        <ul className="space-y-2">
                            {DAYS.map(({ key, label }) => {
                                const slot = form.data.schedule[key];
                                const active = !!slot;
                                return (
                                    <li key={key} className={`flex flex-wrap items-center gap-3 p-3 rounded-xl border ${active ? 'border-emerald-200 bg-emerald-50/40' : 'border-gray-200 bg-gray-50/50'}`}>
                                        <button
                                            type="button"
                                            onClick={() => toggleDay(key)}
                                            disabled={!form.data.business_hours_enabled}
                                            className={`w-28 shrink-0 text-left text-sm font-semibold ${active ? 'text-emerald-700' : 'text-gray-500'}`}
                                        >
                                            <span className={`inline-block w-3 h-3 rounded-full mr-2 ${active ? 'bg-emerald-500' : 'bg-gray-300'}`} />
                                            {label}
                                        </button>
                                        {active ? (
                                            <>
                                                <input
                                                    type="time"
                                                    value={slot.from}
                                                    onChange={(e) => setSlot(key, 'from', e.target.value)}
                                                    disabled={!form.data.business_hours_enabled}
                                                    className="px-3 py-1.5 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 tabular-nums"
                                                />
                                                <span className="text-gray-400 text-sm">—</span>
                                                <input
                                                    type="time"
                                                    value={slot.to}
                                                    onChange={(e) => setSlot(key, 'to', e.target.value)}
                                                    disabled={!form.data.business_hours_enabled}
                                                    className="px-3 py-1.5 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 tabular-nums"
                                                />
                                                {['mon', 'tue', 'wed', 'thu', 'fri'].includes(key) && (
                                                    <button
                                                        type="button"
                                                        onClick={() => copyToAllWeekdays(key)}
                                                        disabled={!form.data.business_hours_enabled}
                                                        title="Copiar este horario a los demás días laborales"
                                                        className="ml-auto text-[11px] font-semibold text-[#045474] hover:text-[#1c486c] underline"
                                                    >
                                                        Copiar a L–V
                                                    </button>
                                                )}
                                            </>
                                        ) : (
                                            <span className="text-xs text-gray-400 italic">Cerrado</span>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    </div>

                    {/* Card: auto-respuesta */}
                    <div className={`bg-white rounded-2xl shadow-sm border border-gray-100 p-5 sm:p-6 transition-opacity ${!form.data.business_hours_enabled ? 'opacity-50' : ''}`}>
                        <div className="flex items-start justify-between gap-4 mb-3">
                            <div className="min-w-0">
                                <h3 className="text-base font-bold text-gray-900 flex items-center gap-2">🤖 Auto-respuesta fuera de hora</h3>
                                <p className="text-xs text-gray-500 mt-1">
                                    Se envía UNA vez por lead cada 8h para no spamear conversaciones largas. Requiere que el número WhatsApp esté dentro de la ventana de 24h de Meta.
                                </p>
                            </div>
                            <Toggle
                                value={form.data.out_of_hours_reply_enabled}
                                onChange={(v) => form.setData('out_of_hours_reply_enabled', v)}
                                disabled={!form.data.business_hours_enabled}
                            />
                        </div>
                        <label className="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Mensaje</label>
                        <textarea
                            value={form.data.out_of_hours_message}
                            onChange={(e) => form.setData('out_of_hours_message', e.target.value)}
                            disabled={!form.data.business_hours_enabled || !form.data.out_of_hours_reply_enabled}
                            rows={4}
                            maxLength={1000}
                            placeholder="¡Hola! Nuestro horario es de lunes a viernes de 9 a 18h…"
                            className="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 focus:bg-white disabled:opacity-60 disabled:cursor-not-allowed"
                        />
                        <p className="text-[11px] text-gray-400 mt-1 text-right tabular-nums">{form.data.out_of_hours_message?.length || 0} / 1000</p>
                    </div>

                    <div className="flex justify-end gap-3">
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="px-5 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-[#045474] to-[#1c486c] rounded-xl hover:opacity-90 disabled:opacity-50 shadow-lg shadow-[#045474]/20 inline-flex items-center gap-2"
                        >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            {form.processing ? 'Guardando…' : 'Guardar cambios'}
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
