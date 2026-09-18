<?php

namespace App\Policies;

/**
 * Biblioteca de Medios: solo `Admin`/`Editor` — ver docblock de
 * `EditorManagedPolicy` y ADR-078. No afecta la SELECCIÓN de un archivo ya
 * existente desde un campo `MediaUpload`/`Select` dentro de otro Resource
 * (ej. elegir una imagen destacada al editar un Post) — eso no pasa por
 * esta Policy, solo por la del Resource que se esté editando (`PostPolicy`,
 * etc.), que sí incluye a `Author`. Lo que se restringe acá es la pantalla
 * "Biblioteca de Medios" en sí (listar/subir/editar/borrar archivos sueltos).
 */
class MediaPolicy extends EditorManagedPolicy
{
    // Usa los defaults de EditorManagedPolicy.
}
