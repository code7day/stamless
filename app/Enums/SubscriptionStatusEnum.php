<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SubscriptionStatusEnum: string implements HasLabel
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Expired = 'expired';

    public function getLabel(): string
    {
        return match ($this) {
            self::Trialing => 'En prueba',
            self::Active => 'Activa',
            self::PastDue => 'Pago vencido',
            self::Canceled => 'Cancelada',
            self::Expired => 'Expirada',
        };
    }
}
