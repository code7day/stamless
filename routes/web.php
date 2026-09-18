<?php

use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
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
 * 2026-09-18, fix real (bug encontrado tras el fix de 403 de este mismo
 * día — ver `bootstrap/app.php`): el Tech Lead reportó que, tras arreglar
 * el 403 "crudo" al entrar por URL a una página sin acceso, visitar una URL
 * de Studio que directamente NO MATCHEA NINGUNA RUTA (ej. `/cica360/
 * preferencias`, con "c" — la página real es `/cica360/preferences`, el
 * slug de Filament, en inglés) seguía mostrando un "404 | Not Found" crudo
 * en vez de redirigir.
 *
 * Causa raíz, distinta a la del 403: el handler de `NotFoundHttpException`
 * en `bootstrap/app.php` (que YA existía, pensado para este caso) nunca
 * llega a ver una sesión/usuario autenticado cuando la URL no matchea
 * NINGUNA ruta — el middleware del panel `cms` (`StartSession`,
 * `FilamentAuthenticate`, etc., registrados por `PanelCmsProvider` vía
 * `->middleware()`/`->authMiddleware()`) está atado a LAS RUTAS que
 * Filament registra para ese panel; si el router de Laravel no encuentra
 * ninguna ruta que matchee, esos middlewares JAMÁS corren (no hay ruta a la
 * que atarlos), así que no hay sesión iniciada y `auth()->user()` dentro
 * del exception handler siempre da `null` — el `if ($user instanceof
 * User)` de ese handler nunca es verdadero para este caso puntual, y cae al
 * 404 default de Laravel.
 *
 * Un `Route::fallback()` SÍ es una ruta real (aunque capture cualquier path
 * no matcheado) — y, al vivir en `routes/web.php`, Laravel le aplica el
 * grupo de middleware `web` (`StartSession` incluido) automáticamente por
 * default de `withRouting(web: ...)` en `bootstrap/app.php` — con eso, la
 * sesión SÍ está disponible acá adentro y `auth()->user()` funciona.
 * Redirige a un usuario autenticado a su Escritorio (o a Platform si es
 * Super Admin sin tenant). Para un guest: `Panel::getLoginUrl()` en vez de
 * `route('login')` — a propósito, NO existe una ruta nombrada literal
 * `login` en esta app (ver el comentario de `redirectGuestsTo()` más
 * arriba en `bootstrap/app.php`, que documenta esto mismo); Filament
 * nombra la suya `filament.{panelId}.auth.login`. `getLoginUrl()` es el
 * método público que resuelve ese nombre real sin hardcodearlo acá.
 */
Route::domain(parse_url(config('stamless.urls.studio'), PHP_URL_HOST))->group(function () {
    Route::fallback(function () {
        $user = auth()->user();
        $cmsPanel = Filament::getPanel('cms', isStrict: false);

        if ($user instanceof User && $cmsPanel) {
            if ($user->tenant instanceof Tenant) {
                return redirect()->to($cmsPanel->getUrl($user->tenant));
            }

            if ($user->is_super_admin) {
                return redirect()->to(config('stamless.urls.platform'));
            }
        }

        $loginUrl = $cmsPanel?->getLoginUrl();
        if ($loginUrl) {
            return redirect()->to($loginUrl);
        }

        return redirect()->to(config('stamless.urls.studio'));
    });
});

/**
 * ──────────────────────────────────────────────────────────────────────────────
 * RUTAS DE DESARROLLO — Solo disponibles en APP_ENV=local
 * ──────────────────────────────────────────────────────────────────────────────
 */
