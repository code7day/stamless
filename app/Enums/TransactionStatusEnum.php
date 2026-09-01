<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TransactionStatusEnum: string implements HasLabel
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Refunded = 'refunded';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Succeeded => 'Exitosa',
            self::Failed => 'Fallida',
            self::Refunded => 'Reembolsada',
        };
    }
}
