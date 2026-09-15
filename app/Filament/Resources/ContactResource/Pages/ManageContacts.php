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
}
