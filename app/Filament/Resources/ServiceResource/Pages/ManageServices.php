<?php

namespace App\Filament\Resources\ServiceResource\Pages;

use App\Filament\Resources\ServiceResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageServices extends ManageRecords
{
    protected static string $resource = ServiceResource::class;

    /**
     * Límite de servicios por plan (2026-09-11, ver
     * `Tenant::maxServices()`/`ServiceResource::isServiceLimitReached()`).
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->slideOver()
                ->modalWidth('3xl')
                ->disabled(fn (): bool => ServiceResource::isServiceLimitReached())
                ->tooltip(fn (): ?string => ServiceResource::isServiceLimitReached() ? ServiceResource::serviceLimitMessage() : null)
                ->before(function (Actions\CreateAction $action) {
                    if (! ServiceResource::isServiceLimitReached()) {
                        return;
                    }

                    Notification::make()->danger()->title('Límite del plan alcanzado')->body(ServiceResource::serviceLimitMessage())->send();
                    $action->halt();
                }),
        ];
    }
}
