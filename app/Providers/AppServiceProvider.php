<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\TenantManager::class, function () {
            return new \App\Services\TenantManager();
        });

        $this->app->singleton(\App\Services\SettingService::class, function ($app) {
            return new \App\Services\SettingService($app->make(\App\Services\TenantManager::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // MAMP PRO sirve la app en https://stamless.host
        // → excluimos 'server' (php artisan serve) de `composer dev`
        DevCommands::except('server');

        $this->configureApiRateLimiting();
    }

    /**
     * Rate limit básico de la API pública (ver bootstrap/app.php:
     * `$middleware->throttleApi()` aplica el limiter `api` a todo el
     * grupo). `forms` es más estricto porque es un endpoint de escritura
     * pública (envío de formularios), propenso a spam/abuso.
     */
    private function configureApiRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        RateLimiter::for('forms', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    }
}
