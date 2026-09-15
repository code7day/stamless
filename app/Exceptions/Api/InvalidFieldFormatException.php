<?php

namespace App\Exceptions\Api;

use InvalidArgumentException;

/**
 * Uno o más campos del payload de `POST forms/{slug}/submit` no cumplen el
 * formato esperado (email inválido, nombre con caracteres no permitidos,
 * opción de un `select` fuera del catálogo configurado, etc.) — ver
 * `App\Services\ContactSubmissionService::assertFieldsAreValid()`.
 *
 * Mismo criterio que `MissingRequiredFieldsException` (hermana de esta
 * clase, mismo directorio): extiende `InvalidArgumentException`, no
 * `Illuminate\Validation\ValidationException`, porque las reglas acá se
 * arman en runtime a partir de `FormField` (dinámico por tenant/form), no
 * de un `FormRequest` estático — pero expone el mismo shape `{ campo:
 * [mensajes] }` que exige el envelope de error de la API (ADR-009/ADR-024).
 */
class InvalidFieldFormatException extends InvalidArgumentException
{
    /**
     * @param  array<string, list<string>>  $fieldErrors
     */
    public function __construct(private readonly array $fieldErrors)
    {
        parent::__construct('Uno o más campos no tienen el formato esperado.');
    }

    /**
     * @return array<string, list<string>>
     */
    public function fields(): array
    {
        return $this->fieldErrors;
    }
}
