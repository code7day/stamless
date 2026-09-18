<?php

namespace App\Policies;

/**
 * Biblioteca de Medios: `Admin`/`Soporte`/`Marketing` — ver docblock de
 * `SupportManagedPolicy` y el addendum de ADR-078 (2026-09-18, expansión a
 * 5 roles). No afecta la SELECCIÓN de un archivo ya existente desde un
 * campo `MediaUpload`/`Select` dentro de otro Resource (ej. elegir una
 * imagen destacada al editar un Post) — eso no pasa por esta Policy, solo
 * por la del Resource que se esté editando (`PostPolicy`, etc.), que sí
 * incluye a `Author`/`Editor`. Lo que se restringe acá es la pantalla
 * "Biblioteca de Medios" en sí (listar/subir/editar/borrar archivos sueltos).
 */
class MediaPolicy extends SupportManagedPolicy
{
    // Usa los defaults de SupportManagedPolicy.
}
