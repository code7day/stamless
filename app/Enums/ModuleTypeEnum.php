<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ModuleTypeEnum: string implements HasLabel
{
    case Vertical = 'vertical';
    case Integration = 'integration';
    case Utility = 'utility';

    public function getLabel(): string
    {
        return match ($this) {
            self::Vertical => 'Vertical de industria',
            self::Integration => 'Integración',
            self::Utility => 'Utilidad',
        };
    }
}
