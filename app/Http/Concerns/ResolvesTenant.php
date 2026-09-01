<?php

namespace App\Http\Concerns;

use App\Models\Tenant;
use App\Services\TenantManager;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resuelve el tenant desde el segmento `{tenant_slug}` de la ruta y lo
 * activa en el `TenantManager` singleton — el mismo mecanismo que ya usan
 * `HasTenant`/`TenantScope`/`ResolveTenant` (middleware) y los tests
 * existentes (`TenantIsolationTest::$tenantManager->setTenant(...)`).
 *
 * No se hace desde el middleware global `ResolveTenant` porque este corre
 * antes de que el router matchee la ruta (Laravel resuelve el pipeline de
 * middleware global antes de `findRoute()`), así que `{tenant_slug}`
 * todavía no está disponible ahí. Resolverlo al inicio de cada acción del
 * controller es simple, explícito y 100% correcto sin depender de orden
 * de middleware.
 *
 * Desde ADR-018, además valida que el token Bearer autenticado (guard
 * `sanctum`, aplicado por el middleware de ruta) pertenezca al mismo
 * tenant que `{tenant_slug}` — nunca se confía en que un token válido de
 * OTRO tenant pueda leer/escribir acá, aunque el tenant del path exista.
 */
trait ResolvesTenant
{
    /**
     * @throws NotFoundHttpException  Si el tenant no existe o está inactivo (nunca se revela cuál de las dos razones, para no filtrar información).
     * @throws AccessDeniedHttpException  Si el token autenticado pertenece a otro tenant.
     */
    protected function resolveTenant(string $tenantSlug): Tenant
    {
        $tenant = Tenant::where('slug', $tenantSlug)
            ->where('is_active', true)
            ->first();

        if (! $tenant) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        }

        $user = request()->user('sanctum');

        if ($user && $user->tenant_id !== $tenant->id) {
            throw new AccessDeniedHttpException('El token no pertenece a este tenant.');
        }

        app(TenantManager::class)->setTenant($tenant);

        return $tenant;
    }
}
