<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rechaza cualquier valor que contenga etiquetas HTML — defensa en
 * profundidad contra XSS almacenado (2026-09-12, pedido del Tech Lead:
 * "quiero evitar XSS, injection ... en el submit del formulario").
 *
 * Contexto: `ContactSubmissionService` guarda los campos dinámicos del
 * formulario tal cual llegan en `Contact::data` (jsonb) — sin `strip_tags`/
 * `htmlspecialchars`/purify alguno hasta ahora. Ninguno de los 2 lugares
 * que luego MUESTRAN ese valor (la tabla de Contactos en Filament, el email
 * de notificación vía `ContactFormSubmitted`) hace un purify explícito
 * tampoco, así que la validación de ENTRADA es la barrera real hoy: mejor
 * rechazar de entrada un valor con markup que confiar en que cada
 * consumidor futuro escape correctamente.
 *
 * Implementación deliberadamente simple: `strip_tags($value) !== $value`
 * alcanza para detectar cualquier `<tag>`/`</tag>` bien formado (el vector
 * real de XSS almacenado — `<script>`, `<img onerror=...>`, etc.); no
 * intenta ser un sanitizador completo (no normaliza entidades HTML, no
 * bloquea JS sin tags como `javascript:` en un campo tipo URL — fuera de
 * alcance de este form, ningún campo de "Contactame" es un link).
 */
class NoHtmlTags implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (strip_tags($value) !== $value) {
            $fail('El campo :attribute no puede contener etiquetas HTML.');
        }
    }
}
