<?php

use App\Http\Controllers\Api\V1\FormSubmissionController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\MenuController;
use App\Http\Controllers\Api\V1\PageController;
use App\Http\Controllers\Api\V1\PostController;
use App\Http\Controllers\Api\V1\SliderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API pública v1 — Stamless Headless
|--------------------------------------------------------------------------
|
| Servida en el subdominio dedicado `api.stamless.host` (local) /
| `api.stamless.com` (prod) (ver ADR-012, ADR-026),
| sin el prefijo `/api` (ver ADR-025: `apiPrefix: ''` en
| bootstrap/app.php + este archivo envuelto en `Route::domain(...)`).
| Las URLs reales quedan `api.stamless.host/v1/{tenant_slug}/...`.
| El tenant se resuelve explícitamente en cada controller (trait
| `ResolvesTenant`, reutiliza `TenantManager`/`HasTenant`/`TenantScope`
| ya existentes — no es un sistema de tenancy nuevo) porque el middleware
| global `ResolveTenant` corre antes de que el router matchee la ruta y no
| puede leer el segmento `{tenant_slug}` todavía. `ResolvesTenant` también
| valida ahí mismo que el token autenticado pertenezca a ese tenant
| (ver ADR-018).
|
| Todas las rutas exigen `Authorization: Bearer {token}` (guard `sanctum`,
| ADR-018): sin token → 401, token inválido/revocado → 401, token de otro
| tenant → 403 (ver `ResolvesTenant`). Los endpoints de lectura exigen la
| ability `content:read`; el submit de forms exige `forms:submit`.
|
| Solo contenido publicado/activo, `lang_iso = es` fijo (sin selector).
|
*/

Route::domain(parse_url(config('stamless.urls.api'), PHP_URL_HOST))->group(function () {
    Route::prefix('v1/{tenant_slug}')
        ->name('api.v1.')
        ->middleware('auth:sanctum')
        ->group(function () {
            Route::middleware('abilities:content:read')->group(function () {
                Route::get('pages', [PageController::class, 'index'])->name('pages.index');
                Route::get('pages/{slug}', [PageController::class, 'show'])->name('pages.show');

                Route::get('posts', [PostController::class, 'index'])->name('posts.index');
                Route::get('posts/{slug}', [PostController::class, 'show'])->name('posts.show');

                Route::get('menus/{slug}', [MenuController::class, 'show'])->name('menus.show');

                Route::get('sliders/{slug}', [SliderController::class, 'show'])->name('sliders.show');

                Route::get('media/{uuid}', [MediaController::class, 'show'])->name('media.show');
            });

            Route::post('forms/{slug}/submit', [FormSubmissionController::class, 'store'])
                ->middleware(['throttle:forms', 'abilities:forms:submit'])
                ->name('forms.submit');
        });
});
