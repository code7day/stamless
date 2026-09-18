<?php

namespace App\Policies;

use App\Enums\UserRoleEnum;

/**
 * Segunda capa de la jerarquía de Policies (ver `TenantRolePolicy`): para
 * los recursos "técnicos/de marca" que el Tech Lead reservó a
 * `Admin`/`Soporte`/`Marketing` — Menús, Sliders, Multimedia, Formularios
 * (personalizar) — `Editor` y `Author` quedan sin acceso a NINGUNA acción,
 * ni siquiera de lectura. `viewAny` en `false` hace que Filament oculte el
 * ítem del sidebar solo, sin tocar nada de navegación a mano.
 *
 * 2026-09-18 (addendum ADR-078, expansión de roles): reemplaza a la clase
 * `EditorManagedPolicy` original. El rol `Editor` PERDIÓ el acceso a este
 * grupo de recursos en la expansión a 5 roles — pedido explícito del Tech
 * Lead: "Editor sin Multimedia/Menús/Sliders/Formularios" (queda acotado a
 * Contenidos/Blog/Servicios/Testimonios + Contactos, ver `ContactPolicy`,
 * que NO extiende esta clase porque Editor sí conserva ese recurso). El
 * nombre viejo (`EditorManagedPolicy`) quedaría engañoso si se mantenía —
 * de ahí el rename.
 *
 * Cada Policy concreta que extiende esta clase queda vacía — toda la
 * diferencia con `TenantRolePolicy` son estos 4 arrays, declarados acá una
 * sola vez.
 */
abstract class SupportManagedPolicy extends TenantRolePolicy
{
    protected array $viewRoles = [
        UserRoleEnum::Admin->value,
        UserRoleEnum::Soporte->value,
        UserRoleEnum::Marketing->value,
    ];

    protected array $createRoles = [
        UserRoleEnum::Admin->value,
        UserRoleEnum::Soporte->value,
        UserRoleEnum::Marketing->value,
    ];

    protected array $updateRoles = [
        UserRoleEnum::Admin->value,
        UserRoleEnum::Soporte->value,
        UserRoleEnum::Marketing->value,
    ];

    protected array $deleteRoles = [
        UserRoleEnum::Admin->value,
        UserRoleEnum::Soporte->value,
        UserRoleEnum::Marketing->value,
    ];
}
