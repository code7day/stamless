<?php

namespace App\Policies;

/**
 * Sliders: solo `Admin`/`Editor` — ver docblock de `EditorManagedPolicy` y
 * ADR-078.
 */
class SliderPolicy extends EditorManagedPolicy
{
    // Usa los defaults de EditorManagedPolicy.
}
