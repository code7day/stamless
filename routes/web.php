<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::domain(parse_url(config('app.url'), PHP_URL_HOST))->group(function () {
    Route::get('/', fn () => view('public.home'))->name('stamless.home');

    Route::any('/api/{any?}', function () {
        return response('', 404);
    })->where('any', '.*');

    Route::any('/graphql/{any?}', function () {
        return response('', 404);
    })->where('any', '.*');
});

Route::domain(parse_url(config('stamless.urls.api'), PHP_URL_HOST))->group(function () {
    Route::get('/', function () {
        return response('', 404);
    })->name('api.root');

    Route::any('/graphql/{any?}', function () {
        return response('', 404);
    })->where('any', '.*');

    Route::get('/v1/health', function () {
        try {
            DB::connection()->getPdo();
            $dbStatus = 'up';
        } catch (Exception $e) {
            $dbStatus = 'down';
        }

        $status = $dbStatus === 'up' ? 'ok' : 'error';
        $httpStatus = $status === 'ok' ? 200 : 503;

        return response()->json([
            'status' => $status,
            'timestamp' => now()->toIso8601String(),
            'services' => [
                'database' => $dbStatus,
            ],
        ], $httpStatus);
    })->name('api.health');
});

Route::get('/', function () {
    return redirect(config('app.url'));
});

/**
 * Sirve el spec OpenAPI crudo (`docs/api/openapi.v1.yaml`) para que se
 * pueda cargar en Swagger UI/Postman/etc. Sin auth a propósito: es un
 * contrato de API público (no datos de tenant), pensado para consumirse
 * fuera de Console igual que cualquier spec OpenAPI estándar.
 *
 * Registrado en el dominio de Console porque el link que lo referencia
 * vive en `App\Filament\Pages\ApiDocumentation`: el markdown fuente usa
 * un link relativo `./openapi.v1.yaml` que, al renderizarse dentro de
 * una page anidada bajo `/{tenant}/...`, resolvía a una URL sin ruta
 * (404). Esta ruta le da un destino real.
 */
Route::get('/openapi.v1.yaml', function () {
    $path = base_path('docs/api/openapi.v1.yaml');

    abort_unless(is_file($path), 404);

    return response(file_get_contents($path), 200, [
        'Content-Type' => 'application/yaml; charset=UTF-8',
    ]);
})
    ->domain(parse_url(config('stamless.urls.studio'), PHP_URL_HOST))
    ->name('docs.openapi-yaml');

/**
 * Silenciador del dominio GraphQL (reservado, no implementado en MVP).
 * graphql.stamless.host → 404 vacío en cualquier path.
 * Ver ADR-026: no instalar Lighthouse, no playground público.
 */
Route::domain(parse_url(config('stamless.urls.graphql'), PHP_URL_HOST))->group(function () {
    Route::any('/{any?}', function () {
        return response('', 404);
    })->where('any', '.*');
});
/**
 * ──────────────────────────────────────────────────────────────────────────────
 * RUTAS DE DESARROLLO — Solo disponibles en APP_ENV=local
 * ──────────────────────────────────────────────────────────────────────────────
 */
