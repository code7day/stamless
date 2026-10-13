<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SupportTicketStatusEnum: string implements HasColor, HasLabel
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Answered = 'answered';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Nuevo / Abierto',
            self::InProgress => 'En atención',
            self::Answered => 'Respondido',
            self::Closed => 'Cerrado / Resuelto',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Open => 'warning',
            self::InProgress => 'info',
            self::Answered => 'primary',
            self::Closed => 'success',
        };
    }
}
