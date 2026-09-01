<?php

use App\Exceptions\Api\MissingRequiredFieldsException;
use App\Support\Api\ErrorEnvelope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: '',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\ResolveTenant::class);

        // Rate limit básico para la API pública (ver RateLimiter::for('api', ...) en AppServiceProvider).
        $middleware->throttleApi();

        // Sanctum no auto-registra estos alias en apps sin Http/Kernel.php
        // (Laravel 11+): hay que declararlos a mano para poder usar
        // `abilities:content:read` / `ability:...` en routes/api.php.
        $middleware->alias([
            'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
        ]);

        // Bug real detectado: `Illuminate\Foundation\Configuration\
        // ApplicationBuilder::withMiddleware()` registra POR DEFECTO
        // `redirectGuestsTo(fn () => route('login'))` antes de correr este
        // callback — y esta app headless no tiene (ni va a tener) una ruta
        // web nombrada `login`. Cuando un client pega a `/api/*` SIN header
        // `Authorization` y SIN `Accept: application/json` (curl liso,
        // clientes que no declaran Accept), `Authenticate::unauthenticated()`
        // evalúa `expectsJson() ? null : $this->redirectTo($request)` —
        // como `expectsJson()` da false, intenta resolver `route('login')`,
        // que no existe, y tira `RouteNotFoundException` (subclase de
        // `\InvalidArgumentException`) ANTES de que se llegue a construir
        // la `AuthenticationException` real. Nuestro handler de abajo
        // clasifica esa `InvalidArgumentException` como 422, así que el
        // cliente ve `{"message":"Route [login] not defined.",...}` en vez
        // de un 401 limpio. Fix: para `/api/*` el guest jamás redirige a
        // ningún lado (null), sin importar el header Accept — así
        // `AuthenticationException` se construye y tira normal, y la
        // maneja el `$exceptions->render()` de abajo.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->getHost() === parse_url(config('stamless.urls.api'), PHP_URL_HOST) ? null : route('login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->getHost() === parse_url(config('stamless.urls.api'), PHP_URL_HOST) || $request->expectsJson(),
        );

        // Envelope de error uniforme (ADR-009, formalizado en ADR-024) para
        // toda excepción no manejada explícitamente por un controller
        // de la API (auth, abilities, 404 de rutas/tenant, validación,
        // rate limit, errores inesperados, etc.). Nunca redirige a
        // ningún lado ni cita rutas internas — solo JSON.
        $exceptions->render(function (Throwable $e, Request $request) {
            $apiHost = parse_url(config('stamless.urls.api'), PHP_URL_HOST);
            if ($request->getHost() !== $apiHost) {
                return null;
            }

            // `$e instanceof AuthorizationException` casi nunca da `true`
            // acá adentro: `Handler::prepareException()` (core de Laravel)
            // ya convirtió cualquier `AuthorizationException` en
            // `AccessDeniedHttpException`/`HttpException` ANTES de llegar a
            // este callback — se deja la rama igual por claridad/defensiva,
            // pero el 403 real llega por el fallback `HttpExceptionInterface`
            // de abajo (`AccessDeniedHttpException` lo implementa).
            $status = match (true) {
                $e instanceof AuthenticationException => 401,
                $e instanceof AuthorizationException => 403,
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => 404,
                $e instanceof ValidationException => 422,
                $e instanceof ThrottleRequestsException => 429,
                $e instanceof InvalidArgumentException => 422,
                $e instanceof HttpExceptionInterface => $e->getStatusCode(),
                default => 500,
            };

            // Distingue "no mandaste token" de "mandaste un token pero es
            // inválido/expiró/fue revocado" — Sanctum no lanza excepciones
            // distintas para cada caso (ambos terminan en la misma
            // AuthenticationException genérica), así que la única señal
            // disponible acá es si el request traía un Bearer token o no.
            $hadBearerToken = $status === 401 && $request->bearerToken() !== null;

            $code = match (true) {
                $status === 401 && $hadBearerToken => 'token_invalid',
                $status === 401 => 'unauthenticated',
                $status === 403 => 'forbidden',
                $status === 404 => 'not_found',
                $status === 422 => 'validation',
                $status === 429 => 'too_many_requests',
                $status === 500 => 'server_error',
                default => 'error',
            };

            // Mensajes FIJOS por status, sin `$e->getMessage()` como
            // fallback: `Handler::prepareException()` (core de Laravel,
            // corre ANTES de que este callback vea la excepción) convierte
            // CUALQUIER `AuthorizationException` en `AccessDeniedHttpException`
            // — perdiendo el tipo original — así que un `instanceof
            // AuthorizationException` acá adentro nunca da `true`, y confiar
            // en `$e->getMessage() ?: '...'` termina filtrando mensajes
            // internos de librerías (ej. el "Invalid ability provided." de
            // Sanctum, en inglés, sin traducir) tal cual al cliente. Un
            // mensaje fijo por status es más simple Y más seguro.
            $message = match (true) {
                $status === 401 && $hadBearerToken => 'Token inválido o expirado.',
                $status === 401 => 'No autenticado. Enviá un token Bearer en Authorization.',
                $status === 403 => 'No tenés permiso para este recurso.',
                $status === 404 => 'Recurso no encontrado.',
                $status === 422 => 'Revisá los datos enviados.',
                $status === 429 => 'Demasiadas solicitudes. Intentá más tarde.',
                $status === 500 => 'Error interno. Intentá de nuevo.',
                default => 'Error inesperado.',
            };

            $errors = match (true) {
                $e instanceof MissingRequiredFieldsException => ['code' => $code, 'fields' => $e->fields()],
                $e instanceof ValidationException => ['code' => $code, 'fields' => $e->errors()],
                // `detail` con el mensaje real de la excepción SOLO si
                // APP_DEBUG=true — nunca un stack trace, nunca en producción.
                $status === 500 => ['code' => $code, 'detail' => config('app.debug') ? $e->getMessage() : null],
                default => ['code' => $code],
            };

            return ErrorEnvelope::make($message, $status, $errors);
        });
    })->create();
