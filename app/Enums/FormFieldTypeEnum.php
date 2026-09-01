<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FormFieldTypeEnum: string implements HasLabel
{
    case Text = 'text';
    case Email = 'email';
    case Tel = 'tel';
    case Textarea = 'textarea';
    case Select = 'select';
    case Checkbox = 'checkbox';
    case Radio = 'radio';
    case Number = 'number';
    case Date = 'date';
    case File = 'file';
    case Hidden = 'hidden';

    public function getLabel(): string
    {
        return match ($this) {
            self::Text => 'Texto',
            self::Email => 'Email',
            self::Tel => 'Teléfono',
            self::Textarea => 'Área de texto',
            self::Select => 'Selección',
            self::Checkbox => 'Casilla',
            self::Radio => 'Opción única',
            self::Number => 'Número',
            self::Date => 'Fecha',
            self::File => 'Archivo',
            self::Hidden => 'Oculto',
        };
    }
}
