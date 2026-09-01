<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Alineación de texto/botones dentro del contenedor de contenido. En mobile
 * se fuerza siempre a `Center` sin importar el valor configurado (regla de
 * negocio fija del MVP, aplicada en frontend).
 */
enum AlignContentEnum: string implements HasLabel
{
    case Left = 'left';
    case Center = 'center';
    case Right = 'right';

    public function getLabel(): string
    {
        return match ($this) {
            self::Left => 'Izquierda',
            self::Center => 'Centro',
            self::Right => 'Derecha',
        };
    }
}
