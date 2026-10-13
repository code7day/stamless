<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SupportTicketCategoryEnum: string implements HasColor, HasLabel
{
    case PlanUpgrade = 'plan_upgrade';
    case Inquiry = 'inquiry';
    case Improvement = 'improvement';
    case BugReport = 'bug_report';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::PlanUpgrade => 'Ampliación de Plan',
            self::Inquiry => 'Consulta General',
            self::Improvement => 'Sugerencia / Mejora',
            self::BugReport => 'Reporte de Error',
            self::Other => 'Otro',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::PlanUpgrade => 'warning',
            self::Inquiry => 'gray',
            self::Improvement => 'info',
            self::BugReport => 'danger',
            self::Other => 'gray',
        };
    }
}
