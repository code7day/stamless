<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\TenantManager;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puente entre la tenancy propia de Filament (`Filament::getTenant()`,
 * resuelta por su middleware `IdentifyTenant` a partir del segmento
 * `{tenant:slug}` de la URL de Studio) y `App\Services\TenantManager`
 * (el servicio del que depende `App\Traits\HasTenant::bootHasTenant()`
 * para autocompletar `tenant_id` al crear cualquier modelo tenant-scoped).
 *
 * 2026-09-02 — bug real reportado en vivo: crear un `MenuItem` anidado
 * (submenú nivel 2/3) tiraba `SQLSTATE[23502] Not null violation:
 * tenant_id` en `menu_items`. Causa raíz: `App\Http\Middleware\ResolveTenant`
 * (middleware global) SÍ corre en toda request, pero resuelve el tenant por
 * query param/headers o por dominio en la tabla `tenant_domains` — ninguna
 * de esas estrategias aplica a Studio, que vive en un host fijo
 * (`config('stamless.urls.studio')`) y resuelve el tenant por el slug en la
 * URL vía la tenancy nativa de Filament. Nada conectaba ambos sistemas, así
 * que `TenantManager::hasTenant()` era siempre `false` dentro de Studio —
 * afectaba a CUALQUIER modelo `HasTenant` creado por un Repeater anidado con
 * `->relationship()` (además de `MenuItem`, también `Slide` bajo
 * `SliderResource`), no solo al caso reportado.
 *
 * Registrado como `->tenantMiddleware([...])` en `PanelCmsProvider` (no en
 * `->middleware()`/`->authMiddleware()`, que corren ANTES de que Filament
 * resuelva el tenant) — Filament coloca `IdentifyTenant` primero en ese
 * grupo, así que `Filament::getTenant()` ya está disponible acá.
 */
class SyncTenantManagerWithFilament
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        if ($tenant instanceof Tenant) {
            app(TenantManager::class)->setTenant($tenant);
        }

        return $next($request);
    }
}
