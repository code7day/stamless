<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum LinkTargetEnum: string implements HasLabel
{
    case SameWindow = '_self';
    case NewWindow = '_blank';

    public function getLabel(): string
    {
        return match ($this) {
            self::SameWindow => 'Misma ventana',
            self::NewWindow => 'Nueva ventana',
        };
    }
}
