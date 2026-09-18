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
 * `{tenant:slug}` de la URL de Studio) y otros dos sistemas que necesitan
 * saber "qué tenant es este" sin depender de Filament directamente:
 * `App\Services\TenantManager` (del que depende `App\Traits\HasTenant::
 * bootHasTenant()` para autocompletar `tenant_id`) y el contexto de
 * "team" de `spatie/laravel-permission` (`setPermissionsTeamId()`, del
 * que depende CUALQUIER `$user->hasRole(...)` en las Policies — ver
 * ADR-078).
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
 * 2026-09-18 — mismo problema estructural, encontrado en el otro sistema:
 * `spatie/laravel-permission` con `teams => true` (`config/permission.php`,
 * `team_foreign_key = tenant_id`) exige que `setPermissionsTeamId($id)` se
 * llame ANTES de cualquier `$user->hasRole(...)`/`->hasPermissionTo(...)`
 * para que la consulta filtre por el tenant correcto — sin eso, esas
 * llamadas resuelven contra un contexto de team vacío/incorrecto. Antes de
 * este fix, `setPermissionsTeamId()` solo se llamaba en 3 puntos sueltos de
 * `UserResource`/`ManageUsers` (al asignar o formatear el rol de un
 * usuario) — cualquier Policy que quisiera chequear el rol del usuario
 * ACTUAL en cualquier OTRA página de Studio no tenía el contexto seteado.
 * Se centraliza acá, en el mismo punto exacto donde ya se resuelve el
 * tenant de Filament, para que quede listo ANTES de que corra cualquier
 * Policy de cualquier Resource/Page.
 *
 * Registrado como `->tenantMiddleware([...])` en `PanelCmsProvider` (no en
 * `->middleware()`/`->authMiddleware()`, que corren ANTES de que Filament
 * resuelva el tenant) — Filament coloca `IdentifyTenant` primero en ese
 * grupo, así que `Filament::getTenant()` ya está disponible acá.
 * `isPersistent: true` es igual de obligatorio para esto que para el
 * `TenantManager`: los guardados vía Livewire (POST AJAX) no vuelven a
 * correr el middleware normal del panel, así que sin el flag las Policies
 * evaluadas en un `->action()` de guardado tendrían el team de permisos sin
 * setear.
 */
class SyncTenantManagerWithFilament
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        if ($tenant instanceof Tenant) {
            app(TenantManager::class)->setTenant($tenant);
            setPermissionsTeamId($tenant->id);
        }

        return $next($request);
    }
}
