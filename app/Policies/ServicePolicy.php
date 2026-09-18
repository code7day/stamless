<?php

namespace App\Policies;

/**
 * `Admin`/`Editor`/`Author` pueden ver, crear y editar servicios. Borrar
 * queda reservado a `Admin`/`Editor`. El bloqueo de PUBLICAR para `Author`
 * vive en `ServiceResource::form()` (campo `status`/`published_at`
 * deshabilitado), no acá — ver docblock de `PagePolicy` y ADR-078.
 */
class ServicePolicy extends TenantRolePolicy
{
    // Usa los defaults de TenantRolePolicy.
}
