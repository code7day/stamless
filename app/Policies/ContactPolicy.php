<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;

/**
 * Autorización tenant-aware sobre `Contact`. El global scope de
 * `HasTenant` ya filtra las queries por el tenant activo; esta policy es
 * la segunda capa (defensa en profundidad) para acciones explícitas
 * (Filament actions, futuros controllers de API/admin) — ver
 * ARCHITECTURE.md §9 ("Policies que validan pertenencia al tenant actual").
 *
 * Se resuelve por convención (`App\Models\Contact` → `App\Policies\ContactPolicy`),
 * sin necesidad de registro manual en Laravel 13.
 */
class ContactPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tenant_id !== null;
    }

    public function view(User $user, Contact $contact): bool
    {
        return $user->tenant_id === $contact->tenant_id;
    }

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

    public function create(User $user): bool
    {
        return $user->tenant_id !== null;
    }

    public function update(User $user, Contact $contact): bool
    {
        return $user->tenant_id === $contact->tenant_id;
    }

    public function delete(User $user, Contact $contact): bool
    {
        return $user->tenant_id === $contact->tenant_id;
    }
}
