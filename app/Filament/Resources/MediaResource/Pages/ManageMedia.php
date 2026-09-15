<?php

namespace App\Filament\Resources\MediaResource\Pages;

use App\Filament\Resources\MediaResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;

class ManageMedia extends ManageRecords
{
    protected static string $resource = MediaResource::class;

    /**
     * 2026-09-13, pedido del Tech Lead: "fullwidth el container de
     * filament" — el ancho de página default de Filament (pensado para
     * tablas/forms de texto) le restaba espacio real a la galería de
     * tarjetas. Solo se toca ESTA página (no el panel entero): el resto de
     * los resources del Studio siguen con el ancho estándar de Filament,
     * pensado para su propio contenido.
     */
    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    /**
     * Límite de archivos multimedia por plan (2026-09-11, ver
     * `Tenant::maxMedia()`/`MediaResource::isMediaLimitReached()`).
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->slideOver()
                ->disabled(fn (): bool => MediaResource::isMediaLimitReached())
                ->tooltip(fn (): ?string => MediaResource::isMediaLimitReached() ? MediaResource::mediaLimitMessage() : null)
                ->before(function (Actions\CreateAction $action) {
                    if (! MediaResource::isMediaLimitReached()) {
                        return;
                    }

                    Notification::make()->danger()->title('Límite del plan alcanzado')->body(MediaResource::mediaLimitMessage())->send();
                    $action->halt();
                }),
        ];
    }
}
