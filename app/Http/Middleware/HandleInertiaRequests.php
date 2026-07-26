<?php

namespace App\Http\Middleware;

use App\Models\AppNotification;
use App\Models\Integration;
use App\Services\Wacrm\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
     * Nunca lanza: si el wacrm no responde, el indicador dice "sin conexión"
     * en vez de tumbar la página entera.
     *
     * @return array<string, mixed>|null
     */
    private function aiStatus(Request $request): ?array
    {
        $user = $request->user();

        // Un usuario recién registrado todavía no tiene cuenta: sin account_id
        // no hay integración que consultar (y `forAccount(null)` revienta).
        if (! $user || ! $user->account_id) {
            return null;
        }

        return Cache::remember(
            "ai_status:{$user->account_id}",
            now()->addMinutes(2),
            function () use ($user) {
                $integration = Integration::forAccount($user->account_id)->first();

                if (! $integration || ! $integration->wacrm_url || ! $integration->wacrm_api_key) {
                    return null;
                }

                try {
                    return Client::for($integration)->aiStatus();
                } catch (\Throwable $e) {
                    return ['configured' => true, 'available' => false, 'reason' => 'unreachable'];
                }
            },
        );
    }
}
