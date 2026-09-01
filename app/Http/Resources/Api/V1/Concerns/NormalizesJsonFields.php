<?php

namespace App\Http\Resources\Api\V1\Concerns;

/**
 * Contrato público de responses (ADR-018): `properties`/`content` son
 * mapas asociativos (jsonb) que, vacíos, deben serializar como objeto
 * JSON (`{}`), nunca como array (`[]`) — un frontend tipado no debería
 * tener que manejar dos formas distintas para "sin datos" según si el
 * bloque tiene o no propiedades cargadas.
 */
trait NormalizesJsonFields
{
    /**
     * @param  array<string, mixed>|null  $value
     */
    protected static function asObject(?array $value): object|array
    {
        return empty($value) ? (object) [] : $value;
    }
}
