<?php

namespace App\Policies;

/**
 * Formularios (`FormResource` — definición de campos/notificaciones, no
 * los envíos): `Admin`/`Soporte`/`Marketing` — ver docblock de
 * `SupportManagedPolicy` y el addendum de ADR-078 (2026-09-18, expansión a
 * 5 roles). `Editor` puede ver los CONTACTOS que llegan (ver
 * `ContactPolicy`) pero no personalizar el diseño del formulario en sí.
 */
class FormPolicy extends SupportManagedPolicy
{
    // Usa los defaults de SupportManagedPolicy.
}
