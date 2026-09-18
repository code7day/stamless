<?php

namespace App\Filament\Concerns;

use App\Enums\UserRoleEnum;

/**
 * Helper compartido para bloquear los campos de "publicar/activar" al rol
 * `Author` (Redactor) en `PageResource`/`PostResource`/`ServiceResource`
 * (campos `status`+`published_at`) y `TestimonialResource` (campo
 * `is_visible`, tanto en el form como en la columna editable inline de la
 * tabla) — ver ADR-078.
 *
 * El Tech Lead fue explícito: "el rol de redactor solo debería tener
 * acceso a redactar contenidos y entradas de blog y en modo borrador, no
 * puede borrar y solo eso". `Author` SÍ puede crear/editar el resto de
 * campos de estos 4 Resources (están en su `viewRoles`/`createRoles`/
 * `updateRoles` en `TenantRolePolicy`) — lo único que se restringe acá es
 * el/los campo(s) de publicación, con `->disabled()` (no con una Policy,
 * porque no es una acción CRUD completa sino un campo puntual dentro de un
 * form que el resto sí puede editar).
 */
trait LocksPublishingForAuthor
{
    protected static function currentUserIsAuthor(): bool
    {
        return auth()->user()?->hasRole(UserRoleEnum::Author->value) ?? false;
    }
}
