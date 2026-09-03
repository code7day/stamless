<?php

namespace App\Filament\Resources\MenuResource\Pages;

use App\Filament\Resources\MenuResource;
use Filament\Resources\Pages\ManageRecords;

class ManageMenus extends ManageRecords
{
    protected static string $resource = MenuResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // 2026-09-02 — usa el mismo `->using()` custom que el botón de
            // estado vacío (`MenuResource::createAction()`): el guardado
            // default de `CreateAction` no sabe sincronizar `itemsTree`
            // (no es una columna real de `Menu` ni una relación nativa).
            // Ver el docblock de `MenuResource::createAction()`.
            MenuResource::createAction(),
        ];
    }
}
