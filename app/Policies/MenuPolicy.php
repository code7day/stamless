<?php

namespace App\Policies;

/**
 * Menús: solo `Admin`/`Editor` — ver docblock de `EditorManagedPolicy` y
 * ADR-078. `Author` no está en la lista de recursos que el Tech Lead
 * definió para el rol Redactor (contenidos/blog/servicios/testimonios).
 */
class MenuPolicy extends EditorManagedPolicy
{
    // Usa los defaults de EditorManagedPolicy: Admin/Editor en las 4 acciones.
}
