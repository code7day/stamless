<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Posición del contenedor de contenido (pretitle/title/subtitle/CTA) dentro
 * de un slide u otro bloque con fondo propio. En mobile se fuerza siempre a
 * `BottomCenter` sin importar el valor configurado (regla de negocio fija
 * del MVP, aplicada en frontend).
 */
enum PositionContainerEnum: string implements HasLabel
{
    case TopLeft = 'top-left';
    case MiddleLeft = 'middle-left';
    case BottomLeft = 'bottom-left';
    case TopCenter = 'top-center';
    case MiddleCenter = 'middle-center';
    case BottomCenter = 'bottom-center';
    case TopRight = 'top-right';
    case MiddleRight = 'middle-right';
    case BottomRight = 'bottom-right';

    public function getLabel(): string
    {
        return match ($this) {
            self::TopLeft => 'Arriba - Izquierda',
            self::MiddleLeft => 'Medio - Izquierda',
            self::BottomLeft => 'Abajo - Izquierda',
            self::TopCenter => 'Arriba - Centro',
            self::MiddleCenter => 'Medio - Centro',
            self::BottomCenter => 'Abajo - Centro',
            self::TopRight => 'Arriba - Derecha',
            self::MiddleRight => 'Medio - Derecha',
            self::BottomRight => 'Abajo - Derecha',
        };
    }
}
