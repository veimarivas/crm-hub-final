import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import { Transition } from '@headlessui/react';
import { Link, useForm, usePage } from '@inertiajs/react';

const inputClass = 'w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 focus:bg-white transition-all';

export default function UpdateProfileInformation({
    mustVerifyEmail,
    status,
}) {
    const user = usePage().props.auth.user;

    const { data, setData, patch, errors, processing, recentlySuccessful } =
        useForm({
            name: user.name,
            email: user.email,
            phone: user.phone ?? '',
            booking_enabled: !!user.booking_enabled,
            booking_slug: user.booking_slug ?? '',
            booking_duration_min: user.booking_duration_min ?? 30,
        });

    const submit = (e) => {
        e.preventDefault();

        patch(route('profile.update'));
    };

    return (
        <section className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div className="p-5 border-b border-gray-100 flex items-center gap-3 bg-gradient-to-r from-gray-50 to-transparent">
                <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-[#045474] to-[#1c486c] flex items-center justify-center text-white shadow-md">
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                    </svg>
                </div>
                <div>
                    <h3 className="text-base font-bold text-gray-900">Información del perfil</h3>
                    <p className="text-xs text-gray-400 mt-0.5">Actualizá tus datos de perfil y dirección de correo.</p>
                </div>
            </div>

            <div className="p-5 sm:p-8">
                <form onSubmit={submit} className="space-y-6">
                    <div>
                        <InputLabel htmlFor="name" value="Nombre" />

                        <input
                            id="name"
                            className={`mt-1.5 ${inputClass}`}
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            required
                            autoComplete="name"
                        />

                        <InputError className="mt-2" message={errors.name} />
                    </div>

                    <div>
                        <InputLabel htmlFor="email" value="Correo electrónico" />

                        <input
                            id="email"
                            type="email"
                            className={`mt-1.5 ${inputClass}`}
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            required
                            autoComplete="username"
                        />

                        <InputError className="mt-2" message={errors.email} />
                    </div>

                    <div>
                        <InputLabel htmlFor="phone" value="Teléfono WhatsApp (para recordatorios)" />
                        <input
                            id="phone"
                            type="tel"
                            className={`mt-1.5 ${inputClass}`}
                            value={data.phone}
                            onChange={(e) => setData('phone', e.target.value)}
                            placeholder="591xxxxxxxx (con código de país, sin +)"
                            autoComplete="tel"
                        />
                        <p className="mt-1 text-xs text-gray-500">
                            Si lo cargás, cada mañana a las 8:00 recibís por WhatsApp un resumen de tus tareas del día.
                        </p>
                        <InputError className="mt-2" message={errors.phone} />
                    </div>

                    {/* Booking: link publico para agendar reuniones */}
                    <div className="pt-6 border-t border-gray-100 space-y-4">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <h3 className="text-base font-semibold text-gray-900 flex items-center gap-2">📅 Aceptar reservas</h3>
                                <p className="text-xs text-gray-500 mt-1">Habilitá una página pública para que clientes agenden reuniones con vos según el horario de atención de la cuenta.</p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setData('booking_enabled', !data.booking_enabled)}
                                className={`shrink-0 relative inline-flex h-7 w-14 items-center rounded-full transition-colors ${data.booking_enabled ? 'bg-emerald-500' : 'bg-gray-300'}`}
                                role="switch"
                                aria-checked={data.booking_enabled}
                            >
                                <span className={`inline-block h-5 w-5 transform rounded-full bg-white shadow transition ${data.booking_enabled ? 'translate-x-8' : 'translate-x-1'}`} />
                            </button>
                        </div>

                        {data.booking_enabled && (
                            <div className="space-y-3 pl-4 border-l-2 border-emerald-200">
                                <div>
                                    <InputLabel htmlFor="booking_slug" value="Slug (URL amigable)" />
                                    <div className="mt-1.5 flex items-center rounded-xl shadow-sm">
                                        <span className="inline-flex items-center rounded-l-xl border border-r-0 border-gray-200 bg-gray-100 px-3 text-sm text-gray-500">{window.location.origin}/book/</span>
                                        <input
                                            id="booking_slug"
                                            value={data.booking_slug}
                                            onChange={(e) => setData('booking_slug', e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''))}
                                            placeholder="tu-nombre"
                                            maxLength={60}
                                            className="block w-full rounded-none rounded-r-xl border-gray-200 bg-gray-50 text-sm focus:border-emerald-500 focus:ring-emerald-500 focus:bg-white transition-all"
                                        />
                                    </div>
                                    <p className="mt-1 text-xs text-gray-500">Solo letras minúsculas, números y guiones. Debe ser único.</p>
                                    <InputError className="mt-2" message={errors.booking_slug} />
                                </div>
                                <div>
                                    <InputLabel htmlFor="booking_duration" value="Duración por reunión" />
                                    <select
                                        id="booking_duration"
                                        value={data.booking_duration_min}
                                        onChange={(e) => setData('booking_duration_min', Number(e.target.value))}
                                        className="mt-1.5 block w-full rounded-xl border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 focus:bg-white transition-all"
                                    >
                                        <option value={15}>15 minutos</option>
                                        <option value={30}>30 minutos</option>
                                        <option value={45}>45 minutos</option>
                                        <option value={60}>60 minutos</option>
                                    </select>
                                </div>
                            </div>
                        )}
                    </div>

                    {mustVerifyEmail && user.email_verified_at === null && (
                        <div>
                            <p className="mt-2 text-sm text-gray-800">
                                Tu correo electrónico no está verificado.
                                <Link
                                    href={route('verification.send')}
                                    method="post"
                                    as="button"
                                    className="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2"
                                >
                                    Hacé clic acá para reenviar el correo de verificación.
                                </Link>
                            </p>

                            {status === 'verification-link-sent' && (
                                <div className="mt-2 text-sm font-medium text-green-600">
                                    Se envió un nuevo enlace de verificación a tu correo.
                                </div>
                            )}
                        </div>
                    )}

                    <div className="flex items-center gap-4 pt-2">
                        <button
                            type="submit"
                            disabled={processing}
                            className="px-5 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl hover:from-emerald-500 hover:to-teal-500 disabled:opacity-50 transition-all shadow-lg shadow-emerald-500/20"
                        >
                            {processing ? 'Guardando...' : 'Guardar'}
                        </button>

                        <Transition
                            show={recentlySuccessful}
                            enter="transition ease-in-out"
                            enterFrom="opacity-0"
                            leave="transition ease-in-out"
                            leaveTo="opacity-0"
                        >
                            <p className="text-sm text-emerald-600 font-medium">
                                Guardado.
                            </p>
                        </Transition>
                    </div>
                </form>
            </div>
        </section>
    );
}
