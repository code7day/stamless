<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ContactActivityTypeEnum: string implements HasLabel
{
    case Note = 'note';
    case StatusChange = 'status_change';
    case EmailSent = 'email_sent';
    case Call = 'call';
    case Meeting = 'meeting';
    case FormSubmitted = 'form_submitted';

    public function getLabel(): string
    {
        return match ($this) {
            self::Note => 'Nota',
            self::StatusChange => 'Cambio de estado',
            self::EmailSent => 'Email enviado',
            self::Call => 'Llamada',
            self::Meeting => 'Reunión',
            self::FormSubmitted => 'Formulario enviado',
        };
    }
}
