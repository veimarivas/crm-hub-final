import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Transition } from '@headlessui/react';
import { Link, useForm, usePage } from '@inertiajs/react';

export default function UpdateProfileInformation({
    mustVerifyEmail,
    status,
    className = '',
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
        <section className={className}>
            <header>
                <h2 className="text-lg font-medium text-gray-900">
                    Profile Information
                </h2>

                <p className="mt-1 text-sm text-gray-600">
                    Update your account's profile information and email address.
                </p>
            </header>

            <form onSubmit={submit} className="mt-6 space-y-6">
                <div>
                    <InputLabel htmlFor="name" value="Name" />

                    <TextInput
                        id="name"
                        className="mt-1 block w-full"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        required
                        isFocused
                        autoComplete="name"
                    />

                    <InputError className="mt-2" message={errors.name} />
                </div>

                <div>
                    <InputLabel htmlFor="email" value="Email" />

                    <TextInput
                        id="email"
                        type="email"
                        className="mt-1 block w-full"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        required
                        autoComplete="username"
                    />

                    <InputError className="mt-2" message={errors.email} />
                </div>

                <div>
                    <InputLabel htmlFor="phone" value="Teléfono WhatsApp (para recordatorios)" />
                    <TextInput
                        id="phone"
                        type="tel"
                        className="mt-1 block w-full"
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
                                <div className="mt-1 flex items-center rounded-md shadow-sm">
                                    <span className="inline-flex items-center rounded-l-md border border-r-0 border-gray-300 bg-gray-50 px-3 text-sm text-gray-500">{window.location.origin}/book/</span>
                                    <input
                                        id="booking_slug"
                                        value={data.booking_slug}
                                        onChange={(e) => setData('booking_slug', e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''))}
                                        placeholder="tu-nombre"
                                        maxLength={60}
                                        className="block w-full rounded-none rounded-r-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm"
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
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm"
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
                            Your email address is unverified.
                            <Link
                                href={route('verification.send')}
                                method="post"
                                as="button"
                                className="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                            >
                                Click here to re-send the verification email.
                            </Link>
                        </p>

                        {status === 'verification-link-sent' && (
                            <div className="mt-2 text-sm font-medium text-green-600">
                                A new verification link has been sent to your
                                email address.
                            </div>
                        )}
                    </div>
                )}

                <div className="flex items-center gap-4">
                    <PrimaryButton disabled={processing}>Save</PrimaryButton>

                    <Transition
                        show={recentlySuccessful}
                        enter="transition ease-in-out"
                        enterFrom="opacity-0"
                        leave="transition ease-in-out"
                        leaveTo="opacity-0"
                    >
                        <p className="text-sm text-gray-600">
                            Saved.
                        </p>
                    </Transition>
                </div>
            </form>
        </section>
    );
}
