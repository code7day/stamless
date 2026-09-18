<?php

namespace App\Policies;

/**
 * `Admin`/`Editor`/`Author` pueden ver, crear y editar testimonios. Borrar
 * queda reservado a `Admin`/`Editor`. El bloqueo de ACTIVAR (`is_visible`)
 * para `Author` vive en `TestimonialResource::form()`/`table()` (el toggle
 * del form Y el `ToggleColumn` inline de la tabla, ambos deshabilitados) —
 * ver docblock de `PagePolicy` y ADR-078.
 */
class TestimonialPolicy extends TenantRolePolicy
{
    // Usa los defaults de TenantRolePolicy.
}
