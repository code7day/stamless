<?php

namespace App\Policies;

/**
 * Menús: `Admin`/`Soporte`/`Marketing` — ver docblock de
 * `SupportManagedPolicy` y el addendum de ADR-078 (2026-09-18, expansión a
 * 5 roles). `Editor`/`Author` quedan fuera de este recurso.
 */
class MenuPolicy extends SupportManagedPolicy
{
    // Usa los defaults de SupportManagedPolicy.
}
