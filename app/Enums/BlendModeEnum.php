<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Valores de CSS `mix-blend-mode` con soporte estable en todos los
 * navegadores modernos (se excluyen `plus-darker`/`plus-lighter` por
 * soporte todavía limitado). Aplicable a la imagen de fondo de un slide.
 */
enum BlendModeEnum: string implements HasLabel
{
    case Normal = 'normal';
    case Multiply = 'multiply';
    case Screen = 'screen';
    case Overlay = 'overlay';
    case Darken = 'darken';
    case Lighten = 'lighten';
    case ColorDodge = 'color-dodge';
    case ColorBurn = 'color-burn';
    case HardLight = 'hard-light';
    case SoftLight = 'soft-light';
    case Difference = 'difference';
    case Exclusion = 'exclusion';
    case Hue = 'hue';
    case Saturation = 'saturation';
    case Color = 'color';
    case Luminosity = 'luminosity';

    public function getLabel(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::Multiply => 'Multiplicar (Multiply)',
            self::Screen => 'Trama (Screen)',
            self::Overlay => 'Superposición (Overlay)',
            self::Darken => 'Oscurecer (Darken)',
            self::Lighten => 'Aclarar (Lighten)',
            self::ColorDodge => 'Sobreexponer color (Color Dodge)',
            self::ColorBurn => 'Subexponer color (Color Burn)',
            self::HardLight => 'Luz dura (Hard Light)',
            self::SoftLight => 'Luz suave (Soft Light)',
            self::Difference => 'Diferencia',
            self::Exclusion => 'Exclusión',
            self::Hue => 'Matiz (Hue)',
            self::Saturation => 'Saturación',
            self::Color => 'Color',
            self::Luminosity => 'Luminosidad',
        };
    }
}
