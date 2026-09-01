<?php

namespace App\Exceptions\Api;

use InvalidArgumentException;

/**
 * Campos requeridos de un `Form` (dinámico, definido por tenant vía
 * `FormField`) ausentes en el payload de `POST forms/{slug}/submit` —
 * ver `App\Services\ContactSubmissionService::assertRequiredFieldsPresent()`.
 *
 * Extiende `InvalidArgumentException` a propósito, no
 * `Illuminate\Validation\ValidationException`: los campos requeridos acá
 * son datos (configurables por tenant en runtime), no un rule set estático
 * de Laravel, así que no hay un `FormRequest`/`Validator` real detrás. Al
 * extender `InvalidArgumentException` se mantiene compatible sin cambios
 * con el catch existente en `FormSubmissionController` y con
 * `ContactSubmissionServiceTest::expectException(InvalidArgumentException::class)`.
 *
 * Agrega `fields()` con el shape `{ campo: [mensajes] }` que exige el
 * envelope de error de la API (ADR-009/ADR-024) para respuestas `422` —
 * antes de esto, `forms/submit` solo devolvía un string libre en
 * `errors.detail`, sin desglose por campo.
 */
class MissingRequiredFieldsException extends InvalidArgumentException
{
    /**
     * @param  list<string>  $missingFields
     */
    public function __construct(private readonly array $missingFields)
    {
        parent::__construct('Faltan campos requeridos: '.implode(', ', $missingFields));
    }

    /**
     * @return array<string, list<string>>
     */
    public function fields(): array
    {
        return collect($this->missingFields)
            ->mapWithKeys(fn (string $field): array => [$field => ["El campo {$field} es obligatorio."]])
            ->all();
    }
}
