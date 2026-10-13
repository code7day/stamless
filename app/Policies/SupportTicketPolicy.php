<?php

namespace App\Policies;

use App\Enums\UserRoleEnum;

class SupportTicketPolicy extends TenantRolePolicy
{
    protected array $viewRoles = [
        UserRoleEnum::Admin->value,
        UserRoleEnum::Soporte->value,
        UserRoleEnum::Marketing->value,
        UserRoleEnum::Editor->value,
        UserRoleEnum::Author->value,
    ];

    protected array $createRoles = [
        UserRoleEnum::Admin->value,
        UserRoleEnum::Soporte->value,
        UserRoleEnum::Marketing->value,
        UserRoleEnum::Editor->value,
        UserRoleEnum::Author->value,
    ];

    protected array $updateRoles = [
        UserRoleEnum::Admin->value,
        UserRoleEnum::Soporte->value,
        UserRoleEnum::Marketing->value,
        UserRoleEnum::Editor->value,
    ];

    protected array $deleteRoles = [
        UserRoleEnum::Admin->value,
    ];
}
