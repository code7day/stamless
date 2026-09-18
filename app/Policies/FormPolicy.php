<?php

namespace App\Policies;

/**
 * Formularios (`FormResource` — definición de campos/notificaciones, no
 * los envíos): solo `Admin`/`Editor` — ver docblock de `EditorManagedPolicy`
 * y ADR-078.
 */
class FormPolicy extends EditorManagedPolicy
{
    // Usa los defaults de EditorManagedPolicy.
}
