<?php

namespace App\Policies;

use App\Enums\UserRoleEnum;
use App\Models\Contact;
use App\Models\User;

/**
 * Autorización tenant-aware Y por rol sobre `Contact`. El global scope de
 * `HasTenant` ya filtra las queries por el tenant activo; la verificación
 * de tenant que hace `TenantRolePolicy` es la segunda capa (defensa en
 * profundidad) para acciones explícitas (Filament actions, futuros
 * controllers de API/admin) — ver ARCHITECTURE.md §9 ("Policies que
 * validan pertenencia al tenant actual").
 *
 * 2026-09-18 (addendum ADR-078, expansión de roles): `Admin`/`Soporte`/
 * `Marketing`/`Editor` acceden a los contactos — pedido explícito del Tech
 * Lead: "el acceso a contactos... los demás roles sí deberían poder tener
 * acceso" (es decir, NO se le retira a `Editor` al acotar su alcance en
 * Multimedia/Menús/Sliders/Formularios). No extiende `SupportManagedPolicy`
 * a propósito: ese grupo excluye a `Editor`, pero Contactos es la
 * excepción — array propio, explícito. `Author`/Redactor sigue sin acceso
 * (no estaba en la lista de recursos definida para ese rol).
 */
class ContactPolicy extends TenantRolePolicy
{
    protected array $viewRoles = [
        UserRoleEnum::Admin->value,
        UserRoleEnum::Soporte->value,
        UserRoleEnum::Marketing->value,
        UserRoleEnum::Editor->value,
    ];

    protected array $createRoles = [
        UserRoleEnum::Admin->value,
        UserRoleEnum::Soporte->value,
        UserRoleEnum::Marketing->value,
        UserRoleEnum::Editor->value,
    ];

    protected array $updateRoles = [
        UserRoleEnum::Admin->value,
        UserRoleEnum::Soporte->value,
        UserRoleEnum::Marketing->value,
        UserRoleEnum::Editor->value,
    ];

    protected array $deleteRoles = [
        UserRoleEnum::Admin->value,
        UserRoleEnum::Soporte->value,
        UserRoleEnum::Marketing->value,
        UserRoleEnum::Editor->value,
    ];

    /**
     * Ver los campos sensibles descifrados (email/phone/company/data vía
     * `ContactSubmissionService::decryptData()`). Hoy equivale a `view`;
     * se deja como punto de extensión explícito.
     *
     * TODO(seguridad, fuera de alcance de este bloque): antes de habilitar
     * un export o listado masivo de datos sensibles, exigir re-autenticación
     * (password/OTP) en ese flujo puntual. No implementar aquí sin ADR.
     */
    public function viewSensitive(User $user, Contact $contact): bool
    {
        return $this->view($user, $contact);
    }
}
