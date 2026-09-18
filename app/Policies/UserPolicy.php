<?php

namespace App\Policies;

use App\Enums\UserRoleEnum;

/**
 * Gestión de colaboradores (`UserResource`): SOLO `Admin`. Pedido explícito
 * del Tech Lead — el límite de "seguridad" del rol Editor es justamente
 * este: puede tener acceso a "casi todo" (contenidos, multimedia, grupo de
 * Desarrolladores, Ajustes generales) EXCEPTO crear/gestionar otros
 * usuarios, que es el vector de escalación de privilegios más obvio (un
 * Editor podría, si tuviera acceso, crearse a sí mismo un colaborador con
 * rol Admin). `Author` tampoco tiene acceso, ni falta que hacía —no estaba
 * en la lista de recursos definida para ese rol. Ver ADR-078.
 */
class UserPolicy extends TenantRolePolicy
{
    protected array $viewRoles = [
        UserRoleEnum::Admin->value,
    ];

    protected array $createRoles = [
        UserRoleEnum::Admin->value,
    ];

    protected array $updateRoles = [
        UserRoleEnum::Admin->value,
    ];

    protected array $deleteRoles = [
        UserRoleEnum::Admin->value,
    ];
}
