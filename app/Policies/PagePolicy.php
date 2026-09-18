<?php

namespace App\Policies;

/**
 * `Admin`/`Editor`/`Author` pueden ver, crear y editar páginas. Borrar
 * queda reservado a `Admin`/`Editor` — el Tech Lead fue explícito: un
 * Redactor "no puede borrar y solo eso" (crear/editar en borrador). El
 * bloqueo de PUBLICAR (no solo de guardar) vive en `PageResource::form()`,
 * deshabilitando el campo `status`/`published_at` para `Author` — una
 * Policy de Laravel autoriza la acción sobre el registro completo, no un
 * campo puntual dentro del form. Ver ADR-078.
 */
class PagePolicy extends TenantRolePolicy
{
    // Usa los defaults de TenantRolePolicy: view/create/update para los 3
    // roles, delete solo Admin/Editor.
}
