<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TransactionTypeEnum: string implements HasLabel
{
    case Charge = 'charge';
    case Refund = 'refund';

    public function getLabel(): string
    {
        return match ($this) {
            self::Charge => 'Cobro',
            self::Refund => 'Reembolso',
        };
    }
}
