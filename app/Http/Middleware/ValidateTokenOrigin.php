<?php

namespace App\Http\Middleware;

use App\Enums\ApiTokenPlatformEnum;
use App\Support\Api\ErrorEnvelope;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2026-09-12, pedido del Tech Lead: "también validar que el formulario
 * solo reciba de un dominio de la app (website cliente) que fue
 * configurada al crear un token, por seguridad" — corregido después de
 * una primera vuelta a nivel `Tenant::domains()`: "me refiero a stamless,
 * la proteccion es para stamless, no para el sitio web, porque lo pueden
 * usar solo con node o react o app... por eso tambien un select si es una
 * web o es app desde donde se usará el api, si es app mobile ya no se
 * valida porque es diferente la comunicacion, pero desde desktop via web
 * creo que si porque el cliente estara alojado en un server identificado
 * por un dominio". Ver ADR-059 en docs/context/DECISIONS.md.
 *
 * Aplicado a TODAS las rutas `v1/{tenant_slug}` (routes/api.php, después
 * de `auth:sanctum`) — es una protección de PLATAFORMA (Stamless), no de
 * un endpoint puntual: cualquier token, de cualquier tenant, usado contra
 * cualquier ability, puede declarar su propia plataforma/origen.
 *
 * Comportamiento:
 * - Token sin `platform` seteado (creado antes de esta feature, o el
 *   admin del tenant lo dejó sin definir a propósito) → sin restricción,
 *   0 cambio de comportamiento respecto a antes de este middleware.
 * - `platform = 'app'` → NUNCA se valida — "si es app mobile ya no se
 *   valida porque es diferente la comunicacion" (un dispositivo mobile no
 *   tiene un `Origin`/`Referer` de navegador significativo que declarar).
 * - `platform = 'web'` sin `allowed_origin` seteado → tratado igual que
 *   "sin restricción" (nada contra qué comparar); es responsabilidad del
 *   admin completar el dominio si quiere que esto tenga efecto real.
 * - `platform = 'web'` CON `allowed_origin`: se exige que el request
 *   declare un origen (`Origin` → `Referer` → `X-Forwarded-Host`, en ese
 *   orden — el mismo cliente puede ser un navegador real O un server
 *   propio de un tenant, ej. un proxy Node/PHP, que reenvía el suyo en
 *   `X-Forwarded-Host` por no tener un `Origin` de navegador que
 *   reenviar) que matchee EXACTO (host, sin mayúsculas ni puerto) contra
 *   `allowed_origin` — salvo que `config('stamless.security.
 *   strict_origin_check')` sea `false` (default fuera de producción, ver
 *   `config/stamless.php`), en cuyo caso `localhost`/`127.0.0.1`/`[::1]`
 *   y la ausencia total de header quedan permitidos sin tener que
 *   registrar nada — "permitir al desarrollador que use el api desde
 *   cualquier lado" (pedido explícito), sin fricción para probar local.
 *
 * Límite real de esto (documentado para que quede explícito, no una falsa
 * sensación de seguridad dura): `Referer`/`X-Forwarded-Host` los arma el
 * propio llamador — no son una garantía criptográfica. Alguien con el
 * token robado y un cliente HTTP directo (no un navegador) puede escribir
 * cualquier valor ahí. Es defensa en profundidad y protección contra
 * error de configuración (un token de un tenant usado, por accidente,
 * desde el servidor de otro) — NO reemplaza el scope de abilities, el
 * rate limiting, ni la validación de formato, ya existentes. Donde SÍ es
 * una barrera dura es cuando el token termina expuesto directo en un
 * bundle de navegador (ADR-002 de cica360): ahí un navegador real no deja
 * que un script de otro dominio falsee `Origin`.
 *
 * 2026-09-12, pregunta del Tech Lead: "también debería validarse por
 * tenant, si no imagínate que otro se conecte a tenant diferente" — ESTE
 * middleware NO valida tenant (solo origen), pero esa protección ya existe
 * de forma completamente independiente: `App\Http\Concerns\ResolvesTenant`
 * (usado por TODOS los controllers de `Api\V1`, ver `resolveTenant()`)
 * rechaza con 403 (`El token no pertenece a este tenant.`) cualquier
 * request cuyo token Bearer autenticado no pertenezca al tenant del
 * segmento `{tenant_slug}` de la URL — sin importar qué `platform`/
 * `allowed_origin` tenga configurado ese token. Este middleware corre
 * ANTES en la cadena (`auth:sanctum` → `validate-origin` → ability →
 * controller), así que un token de OTRO tenant que además pasa la
 * validación de origen (porque su propio `allowed_origin` matchea) igual
 * termina rechazado un paso después, dentro del controller. Cubierto por
 * `ApiAuthTest::test_token_from_a_different_tenant_is_forbidden` (a nivel
 * API) y por `ApiTokensRegenerateTest::
 * test_a_user_cannot_regenerate_a_token_from_a_different_tenant` (a nivel
 * Filament/Console).
 */
class ValidateTokenOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        // `Sanctum::actingAs()` en tests usa `TransientToken`, no un
        // `PersonalAccessToken` real — no tiene (ni puede tener)
        // `platform`/`allowed_origin`, así que no hay nada que validar.
        if (! $token instanceof PersonalAccessToken) {
            return $next($request);
        }

        if ($token->platform !== ApiTokenPlatformEnum::Web->value || ! $token->allowed_origin) {
            return $next($request);
        }

        $claimedHost = $this->resolveClaimedOriginHost($request);
        $allowedHost = strtolower((string) $token->allowed_origin);

        if (! config('stamless.security.strict_origin_check')) {
            if (
                $claimedHost === null
                || in_array($claimedHost, ['localhost', '127.0.0.1', '[::1]'], true)
                || str_ends_with($claimedHost, '.host')
                || str_ends_with($claimedHost, '.test')
                || str_ends_with($claimedHost, '.local')
            ) {
                return $next($request);
            }
        }

        if ($claimedHost === $allowedHost) {
            return $next($request);
        }

        return ErrorEnvelope::make(
            'Este token no está autorizado para usarse desde este origen.',
            403,
            ['code' => 'origin_not_allowed'],
        );
    }

    /**
     * @return string|null Host en minúsculas, sin esquema ni puerto.
     */
    private function resolveClaimedOriginHost(Request $request): ?string
    {
        foreach (['Origin', 'Referer', 'X-Forwarded-Host'] as $header) {
            $value = $request->header($header);

            if (! $value) {
                continue;
            }

            // `X-Forwarded-Host` ya es un host "pelado" (sin esquema); los
            // otros dos son URLs completas.
            $host = str_contains($value, '://') ? parse_url($value, PHP_URL_HOST) : $value;

            if (is_string($host) && $host !== '') {
                return strtolower($host);
            }
        }

        return null;
    }
}
