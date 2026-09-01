<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ContactStatusEnum: string implements HasLabel
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Closed = 'closed';
    case Spam = 'spam';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => 'Nuevo',
            self::InProgress => 'En proceso',
            self::Closed => 'Cerrado',
            self::Spam => 'Spam',
        };
    }
}
