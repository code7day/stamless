<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PageTypeEnum: string implements HasLabel
{
    case Page = 'page';
    case Landing = 'landing';
    case Header = 'header';
    case Footer = 'footer';
    case Legal = 'legal';

    public function getLabel(): string
    {
        return match ($this) {
            self::Page => 'Página',
            self::Landing => 'Landing Page',
            self::Header => 'Cabecera (Header)',
            self::Footer => 'Pie de página (Footer)',
            self::Legal => 'Aviso Legal',
        };
    }
}
