<?php

namespace App\Filament\Resources\ContactResource\Pages;

use App\Filament\Resources\ContactResource;
use Filament\Resources\Pages\ManageRecords;

/**
 * Sin `getHeaderActions()` a propósito: `ContactResource::canCreate()`
 * devuelve `false` (ver docblock del Resource), así que Filament ya no
 * ofrece el `CreateAction` por defecto de `ManageRecords` — no hace falta
 * sobreescribir nada acá.
 */
class ManageContacts extends ManageRecords
{
    protected static string $resource = ContactResource::class;

    /**
     * Clave de sesión versionada para invalidar estado previo almacenado en el navegador
     * y asegurar que las nuevas columnas ocultas por defecto (Origen, Formulario) se apliquen de inmediato.
     */
    public function getTableColumnsSessionKey(): string
    {
        $table = md5(static::class.'_v2');

        return "tables.{$table}_columns";
    }
}
