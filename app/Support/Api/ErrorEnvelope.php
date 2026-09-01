<?php

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;

/**
 * Construye el envelope de error JSON estándar de la API (ADR-009,
 * refinado en ADR-024). Un solo lugar define la forma exacta de un error
 * — evita que un 4xx armado a mano en un controller (vía
 * `App\Http\Concerns\ApiResponds::error()`) se desincronice del que arma
 * el handler global de excepciones no capturadas en `bootstrap/app.php`.
 *
 * Es una clase estática (no un trait) a propósito: el handler global vive
 * en un Closure de `bootstrap/app.php` sin `$this`, así que no puede usar
 * un trait — pero sí puede llamar un método estático directamente.
 * `ApiResponds::error()` delega acá para que los controllers no tengan que
 * cambiar su forma de llamar (`$this->error(...)` sigue funcionando igual).
 */
final class ErrorEnvelope
{
    /**
     * @param  array<string, mixed>  $errors  Ej. `['code' => 'validation', 'fields' => [...]]`. Se omiten las claves con valor `null`.
     */
    public static function make(string $message, int $status, array $errors = []): JsonResponse
    {
        return response()->json(array_filter([
            'success' => false,
            'message' => $message,
            'status_code' => $status,
            'errors' => array_filter($errors, fn ($value) => ! is_null($value)) ?: null,
        ], fn ($value) => ! is_null($value)), $status);
    }
}
