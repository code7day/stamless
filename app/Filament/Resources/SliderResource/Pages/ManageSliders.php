<?php

namespace App\Filament\Resources\SliderResource\Pages;

use App\Filament\Resources\SliderResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageSliders extends ManageRecords
{
    protected static string $resource = SliderResource::class;

    /**
     * Límite de sliders por plan (2026-09-11, ver
     * `Tenant::maxSliders()`/`SliderResource::isSliderLimitReached()`).
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->slideOver()
                ->modalWidth('5xl')
                ->disabled(fn (): bool => SliderResource::isSliderLimitReached())
                ->tooltip(fn (): ?string => SliderResource::isSliderLimitReached() ? SliderResource::sliderLimitMessage() : null)
                ->before(function (Actions\CreateAction $action) {
                    if (! SliderResource::isSliderLimitReached()) {
                        return;
                    }

                    Notification::make()->danger()->title('Límite del plan alcanzado')->body(SliderResource::sliderLimitMessage())->send();
                    $action->halt();
                }),
        ];
    }
}
