<?php

namespace App\Http\Middleware;

use App\Models\AppNotification;
use App\Services\Wacrm\AiStatusProbe;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
            ],
            'unreadNotifications' => $request->user()
                ? AppNotification::where('user_id', $request->user()->id)->delivered()->whereNull('read_at')->count()
                : 0,
            // Lazy: solo se resuelve cuando la página lo pide, y el header lo
            // pide siempre. Cacheado 2 min — es un HTTP al wacrm y no puede
            // colgar el render de cada pantalla.
            'aiStatus' => fn () => $this->aiStatus($request),
        ];
    }

    /**
     * Estado de la IA del wacrm para el indicador del header.
     *
     * Nunca lanza: si el wacrm no responde, el indicador muestra el motivo en
     * vez de tumbar la página entera.
     *
     * La lógica vive en `AiStatusProbe` porque el comando de diagnóstico
     * (`komo:ai-status`) tiene que dar exactamente el mismo veredicto que el
     * header — si no, se diagnostica algo distinto de lo que se ve.
     *
     * @return array<string, mixed>|null
     */
    private function aiStatus(Request $request): ?array
    {
        // Un usuario recién registrado todavía no tiene cuenta: sin account_id
        // no hay integración que consultar.
        return app(AiStatusProbe::class)->forAccount($request->user()?->account_id);
    }
}
