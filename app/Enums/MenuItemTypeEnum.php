<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Determina cómo se resuelve el destino de un `menu_item`:
 * - Page/Post/Service: usa `reference_id` para apuntar a un registro tenant-aware.
 * - External/Custom: usa la columna `url` directamente.
 */
enum MenuItemTypeEnum: string implements HasLabel
{
    case Page = 'page';
    case Post = 'post';
    // 2026-09-02 (ver ADR-044): "Servicio" como tipo de enlace de menú,
    // pedido junto con el rediseño del menu builder. Requirió primero
    // exponer un endpoint público propio para `Service`
    // (`GET /v1/{tenant}/services/{slug}`) — antes un servicio no tenía
    // URL pública propia, solo existía como card dentro del bloque
    // `services_grid` de otra página.
    case Service = 'service';
    case External = 'external';
    case Custom = 'custom';

    public function getLabel(): string
    {
        return match ($this) {
            self::Page => 'Página',
            self::Post => 'Entrada',
            self::Service => 'Servicio',
            self::External => 'URL externa',
            self::Custom => 'Personalizado',
        };
    }
}
