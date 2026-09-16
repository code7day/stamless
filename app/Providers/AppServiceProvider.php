<?php

namespace App\Providers;

use App\Http\Responses\FilamentLoginResponse;
use App\Services\SettingService;
use App\Services\TenantManager;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantManager::class, function () {
            return new TenantManager;
        });

        $this->app->singleton(SettingService::class, function ($app) {
            return new SettingService($app->make(TenantManager::class));
        });

        $this->app->singleton(LoginResponse::class, FilamentLoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (str_starts_with(config('app.url', ''), 'https://') || str_starts_with(config('stamless.urls.studio', ''), 'https://')) {
            URL::forceScheme('https');
        }

        // MAMP PRO sirve la app en https://stamless.host
        // → excluimos 'server' (php artisan serve) de `composer dev`
        DevCommands::except('server');

        Gate::before(function ($user, $ability) {
            return $user->is_super_admin ? true : null;
        });

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
            $key = $request->user('sanctum')?->tenant_id
                ? 'tenant:'.$request->user('sanctum')->tenant_id
                : 'ip:'.$request->ip();

            return Limit::perMinute(60)->by($key);
        });

        RateLimiter::for('forms', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    }
}
