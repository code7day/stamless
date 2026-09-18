<?php

namespace App\Policies;

use App\Enums\UserRoleEnum;

/**
 * Segunda capa de la jerarquía de Policies (ver `TenantRolePolicy`): para
 * los recursos que el Tech Lead dejó explícitamente FUERA de lo que puede
 * tocar un Redactor (`Author`) — Menús, Sliders, Multimedia, Formularios,
 * Contactos — `Author` queda sin acceso a NINGUNA acción, ni siquiera de
 * lectura. `viewAny` en `false` hace que Filament oculte el ítem del
 * sidebar solo, sin tocar nada de navegación a mano.
 *
 * Cada Policy concreta que extiende esta clase queda vacía — toda la
 * diferencia con `TenantRolePolicy` son estos 4 arrays, declarados acá una
 * sola vez. Ver ADR-078.
 */
abstract class EditorManagedPolicy extends TenantRolePolicy
{
    protected array $viewRoles = [
        UserRoleEnum::Admin->value,
        UserRoleEnum::Editor->value,
    ];

    protected array $createRoles = [
        UserRoleEnum::Admin->value,
        UserRoleEnum::Editor->value,
    ];

    protected array $updateRoles = [
        UserRoleEnum::Admin->value,
        UserRoleEnum::Editor->value,
    ];

    protected array $deleteRoles = [
        UserRoleEnum::Admin->value,
        UserRoleEnum::Editor->value,
    ];
}
