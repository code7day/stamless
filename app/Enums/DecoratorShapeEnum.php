<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Forma SVG usada como decorador superior/inferior de una sección o bloque
 * (p. ej. la onda sobrepuesta en la parte inferior del Hero). Compartido
 * entre `decorator_top` y `decorator_bottom` en `properties`.
 */
enum DecoratorShapeEnum: string implements HasLabel
{
    case None = 'none';
    case Wave = 'wave';
    case Zigzag = 'zigzag';
    case Curve = 'curve';
    case Diagonal = 'diagonal';
    case Triangle = 'triangle';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => 'Ninguno',
            self::Wave => 'Onda (Wave)',
            self::Zigzag => 'Zigzag',
            self::Curve => 'Curva',
            self::Diagonal => 'Diagonal',
            self::Triangle => 'Triangular',
        };
    }
}
