<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum InvoiceStatusEnum: string implements HasLabel
{
    case Draft = 'draft';
    case Open = 'open';
    case Paid = 'paid';
    case Void = 'void';
    case Uncollectible = 'uncollectible';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Open => 'Abierta',
            self::Paid => 'Pagada',
            self::Void => 'Anulada',
            self::Uncollectible => 'Incobrable',
        };
    }
}
