<?php

namespace App\Filament\Concerns;

use App\Enums\UserRoleEnum;

/**
 * Gate de acceso por rol para Pages de Filament SIN modelo Eloquent
 * propio (`ApiTokens`, `ApiPlayground`, `ApiDocumentation`, `Preferences`)
 * — ver ADR-078.
 *
 * `Filament\Pages\Concerns\CanAuthorizeAccess::canAccess()` (el default de
 * cualquier Page "custom") siempre devuelve `true` para cualquier usuario
 * autenticado del panel: a diferencia de un Resource respaldado por
 * Eloquent, no hay convención de Policy (`App\Models\X` → `App\Policies\
 * XPolicy`) que Filament pueda resolver solo, porque no hay ningún `$model`.
 * Por eso estas 4 Pages necesitan su propio `canAccess()` explícito en vez
 * de heredar una Policy — este trait centraliza el "cómo" (mismo fallback
 * que `TenantRolePolicy::hasAnyRole()`: un usuario sin rol asignado se
 * trata como `Admin`) para no repetirlo en cada Page.
 */
trait RestrictsPageToRoles
{
    /**
     * @param  list<string>  $roles  Valores de UserRoleEnum permitidos.
     */
    protected static function userHasAnyRole(array $roles): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        $roleName = $user->roles()->first()?->name ?? UserRoleEnum::Admin->value;

        return in_array($roleName, $roles, true);
    }
}
