<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;

/**
 * Autorización tenant-aware Y por rol sobre `Contact`. El global scope de
 * `HasTenant` ya filtra las queries por el tenant activo; la verificación
 * de tenant que hace `EditorManagedPolicy`/`TenantRolePolicy` es la segunda
 * capa (defensa en profundidad) para acciones explícitas (Filament
 * actions, futuros controllers de API/admin) — ver ARCHITECTURE.md §9
 * ("Policies que validan pertenencia al tenant actual").
 *
 * 2026-09-18 (ADR-078): pasa a extender `EditorManagedPolicy` — el rol
 * `Author`/Redactor no está en la lista de recursos que el Tech Lead
 * definió para ese rol (contenidos/blog/servicios/testimonios), así que
 * los leads/contactos que llegan por el formulario quedan fuera de su
 * acceso, igual que Menús/Sliders/Multimedia/Formularios. Antes de este
 * cambio esta Policy solo chequeaba tenant, nunca rol — mismo bug de fondo
 * que el resto de Resources (ver `TenantRolePolicy`).
 *
 * Se resuelve por convención (`App\Models\Contact` → `App\Policies\ContactPolicy`),
 * sin necesidad de registro manual en Laravel 13.
 */
class ContactPolicy extends EditorManagedPolicy
{
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
