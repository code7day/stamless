<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FormFieldTypeEnum: string implements HasLabel
{
    case Text = 'text';
    case Email = 'email';
    case Tel = 'tel';

    /**
     * 2026-09-18 (ADR forms por tenant) — generaliza el campo WhatsApp de
     * CICA360 (selector de país + detección por IP + input solo dígitos,
     * ver `cica360/src/components/islands/ContactForm.tsx`) a un tipo de
     * campo reutilizable por cualquier tenant, en vez de dejarlo hardcodeado
     * en un solo frontend. El front resuelve el selector de país + la
     * detección de IP igual que hoy; acá solo se distingue del `Tel` plano
     * para que el builder de formularios sepa qué UX ofrecer.
     */
    case TelCountry = 'tel_country';

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
            self::TelCountry => 'Teléfono con selector de país',
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
