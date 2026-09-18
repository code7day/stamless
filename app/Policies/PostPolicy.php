<?php

namespace App\Policies;

/**
 * `Admin`/`Editor`/`Author` pueden ver, crear y editar posts de blog.
 * Borrar queda reservado a `Admin`/`Editor`. El bloqueo de PUBLICAR para
 * `Author` vive en `PostResource::form()` (campo `status`/`published_at`
 * deshabilitado), no acá — ver docblock de `PagePolicy` y ADR-078.
 */
class PostPolicy extends TenantRolePolicy
{
    // Usa los defaults de TenantRolePolicy.
}
