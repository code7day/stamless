<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentMethodTypeEnum: string implements HasLabel
{
    case Card = 'card';
    case BankAccount = 'bank_account';
    case Paypal = 'paypal';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Card => 'Tarjeta',
            self::BankAccount => 'Cuenta bancaria',
            self::Paypal => 'PayPal',
            self::Other => 'Otro',
        };
    }
}
