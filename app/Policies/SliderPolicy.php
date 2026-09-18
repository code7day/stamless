<?php

namespace App\Policies;

/**
 * Sliders: `Admin`/`Soporte`/`Marketing` — ver docblock de
 * `SupportManagedPolicy` y el addendum de ADR-078 (2026-09-18, expansión a
 * 5 roles). `Editor`/`Author` quedan fuera de este recurso.
 */
class SliderPolicy extends SupportManagedPolicy
{
    // Usa los defaults de SupportManagedPolicy.
}
