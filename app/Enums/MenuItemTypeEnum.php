<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Determina cómo se resuelve el destino de un `menu_item`:
 * - Page/Post: usa `reference_id` para apuntar a un registro tenant-aware.
 * - External/Custom: usa la columna `url` directamente.
 */
enum MenuItemTypeEnum: string implements HasLabel
{
    case Page = 'page';
    case Post = 'post';
    case External = 'external';
    case Custom = 'custom';

    public function getLabel(): string
    {
        return match ($this) {
            self::Page => 'Página',
            self::Post => 'Entrada',
            self::External => 'URL externa',
            self::Custom => 'Personalizado',
        };
    }
}
