<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum UserRoleEnum: string implements HasLabel
{
    case Admin = 'Admin';
    case Soporte = 'Soporte';
    case Marketing = 'Marketing';
    case Editor = 'Editor';
    case Author = 'Author';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Admin => 'Administrador (Control total)',
            self::Soporte => 'Soporte (Técnico, todo excepto Usuarios)',
            self::Marketing => 'Marketing (Contenidos, medios y clientes)',
            self::Editor => 'Editor (Contenidos y contactos)',
            self::Author => 'Redactor (Borradores y blog)',
        };
    }
}
