<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Formularios públicos: 10 envíos por minuto por IP.
        RateLimiter::for('web-form', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('book', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        // API pública: 120 req/min por API key (o IP si aún no autenticó).
        RateLimiter::for('public-api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->bearerToken() ?: $request->ip()));
    }
}
