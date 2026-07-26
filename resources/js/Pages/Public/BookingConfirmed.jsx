import { Head } from '@inertiajs/react';

export default function BookingConfirmed({ host, scheduled_at }) {
    return (
        <div className="min-h-screen bg-gradient-to-br from-[#042048] via-[#1c486c] to-[#045474] flex items-center justify-center px-4">
            <Head title="Reserva confirmada" />
            <div className="bg-white rounded-2xl shadow-2xl p-8 sm:p-10 max-w-md text-center">
                <div className="inline-flex items-center justify-center w-16 h-16 rounded-full bg-emerald-100 mb-4">
                    <svg className="w-8 h-8 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                </div>
                <h1 className="text-2xl font-bold text-gray-900">¡Reserva confirmada!</h1>
                <p className="text-sm text-gray-500 mt-2">Te esperamos el</p>
                <p className="text-lg font-bold text-gray-900 capitalize mt-1">{scheduled_at}</p>
                <p className="text-sm text-gray-500 mt-4">
                    {host.name} recibió tu solicitud y te va a contactar por WhatsApp para coordinar los detalles.
                </p>
            </div>
        </div>
    );
}
