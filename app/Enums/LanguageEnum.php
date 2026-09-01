<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Idiomas soportados por el contenido tenant-aware (columna `lang_iso`).
 * Sin tabla `languages`: el catálogo vive únicamente en este enum.
 */
enum LanguageEnum: string implements HasLabel
{
    case Spanish = 'es';
    case English = 'en';
    case Portuguese = 'pt';

    public function getLabel(): string
    {
        return match ($this) {
            self::Spanish => 'Español',
            self::English => 'English',
            self::Portuguese => 'Português',
        };
    }
}
