<?php

namespace App\Filament\Widgets;

use App\Enums\UserRoleEnum;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\AccountWidget;

/**
 * 2026-09-13, pedido del Tech Lead (Nivel 1 del plan de Dashboard): "quitar
 * el widget Filament, dejar el de bienvenida (pero agregar en un badge el
 * tipo de plan)". El "widget Filament" era `Filament\Widgets\
 * FilamentInfoWidget` (promo/versión del framework — sin valor para un
 * cliente del panel, se saca directo de `PanelCmsProvider::widgets()`). El
 * "de bienvenida" es `Filament\Widgets\AccountWidget` (saludo + nombre +
 * botón de salir) — se conserva, pero no se puede tocar su vista vendor sin
 * pisarla en `resources/views/vendor/...`, así que se extiende como clase
 * propia con vista propia (`filament.cms.widgets.welcome-widget`), que
 * reusa el mismo markup. `canView()` se hereda tal cual del padre (requiere
 * sesión autenticada).
 *
 * 2026-09-18, pedido del Tech Lead con captura: el badge junto al nombre
 * dejó de mostrar el PLAN del tenant (`Tenant::planLabel()`, "Auspicio/
 * Convenio" en la captura) para mostrar el ROL del usuario logueado — "si
 * es administrador o si es Propietario (esto solo a nivel de vista...
 * aunque a nivel de rol sea el mismo nivel de acceso Administrador o
 * Propietario"). El plan del tenant sigue visible en otro lado
 * (`PlanStatusWidget`, columna 2 del Escritorio) — no se pierde
 * información, se reubica. Ver `Tenant::isOwnedBy()` para el criterio
 * "dueño de la cuenta" (heurístico, sin campo `owner_id` en el esquema).
 */
class WelcomeWidget extends AccountWidget
{
    protected string $view = 'filament.cms.widgets.welcome-widget';

    /**
     * `AccountWidget` trae `-3` por default; renumerado a `-50` junto con
     * el resto de widgets del Escritorio para dejar hueco de sobra entre
     * cada uno (`PlanStatusWidget` -40, `LeadsOverviewWidget` -30,
     * `PlanUsageWidget` -20, `RecentContactsWidget` -10) — más fácil
     * insertar uno nuevo en el medio a futuro sin tener que renumerar todo
     * de nuevo.
     */
    protected static ?int $sort = -50;

    /**
     * 2026-09-13: quedó en `'full'` por un momento (para tapar el hueco
     * que dejaba al lado, columna 1 de las 2 fijas del Dashboard, sin
     * nada en la columna 2) — revertido de inmediato: el Tech Lead pidió
     * llenar ESE hueco con contenido de verdad (`PlanStatusWidget`, plan
     * actual + botón "Mejorar plan"), no taparlo estirando el saludo.
     * Columna 1 (default de `Widget`), comparte fila con `PlanStatusWidget`
     * (columna 2).
     */
    protected int|string|array $columnSpan = 1;

    /**
     * "Propietario" (el `Admin` que creó el tenant, ver `Tenant::isOwnedBy()`)
     * o el label corto del rol real (`UserRoleEnum`) para cualquier otro
     * colaborador — incluido otro `Admin` no-dueño, que se etiqueta
     * "Administrador" (no "Propietario"). `null` fuera de un tenant válido
     * o sin usuario autenticado (no debería pasar en la práctica, `canView()`
     * ya exige sesión).
     */
    public function getRoleLabel(): ?string
    {
        [$label] = $this->resolveRoleBadge();

        return $label;
    }

    /**
     * Mismos colores que `UserResource::table()` para consistencia visual
     * entre el badge del Escritorio y la columna "Rol" de Usuarios —
     * "Propietario" se distingue del resto de `Admin` con `warning` (dorado)
     * en vez de `success`.
     */
    public function getRoleBadgeColor(): string
    {
        [, $color] = $this->resolveRoleBadge();

        return $color;
    }

    /**
     * @return array{0: ?string, 1: string} [label, color]
     */
    private function resolveRoleBadge(): array
    {
        $user = filament()->auth()->user();
        $tenant = Filament::getTenant();

        if (! $user instanceof User || ! $tenant instanceof Tenant) {
            return [null, 'gray'];
        }

        setPermissionsTeamId($tenant->id);
        $roleName = $user->roles()->first()?->name ?? UserRoleEnum::Admin->value;

        if ($roleName === UserRoleEnum::Admin->value) {
            return $tenant->isOwnedBy($user)
                ? ['Propietario', 'warning']
                : ['Administrador', 'success'];
        }

        return match ($roleName) {
            UserRoleEnum::Soporte->value => ['Soporte', 'info'],
            UserRoleEnum::Marketing->value => ['Marketing', 'purple'],
            UserRoleEnum::Editor->value => ['Editor', 'primary'],
            UserRoleEnum::Author->value => ['Redactor', 'gray'],
            default => [$roleName, 'gray'],
        };
    }
}
