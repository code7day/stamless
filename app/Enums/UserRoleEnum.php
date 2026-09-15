<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum UserRoleEnum: string implements HasLabel
{
    case Admin = 'Admin';
    case Editor = 'Editor';
    case Author = 'Author';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Admin => 'Administrador (Control total)',
            self::Editor => 'Editor (Gestión de contenidos)',
            self::Author => 'Redactor (Borradores y blog)',
        };
    }
}
