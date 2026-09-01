<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Estado de publicación reutilizado por `pages` y `posts`.
 */
enum PublishStatusEnum: string implements HasLabel
{
    case Draft = 'draft';
    case Published = 'published';
    case Scheduled = 'scheduled';
    case Archived = 'archived';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Published => 'Publicado',
            self::Scheduled => 'Programado',
            self::Archived => 'Archivado',
        };
    }
}
