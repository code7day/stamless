<?php

namespace App\Filament\Resources\FormResource\Pages;

use App\Filament\Resources\FormResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageForms extends ManageRecords
{
    protected static string $resource = FormResource::class;

    /**
     * Límite de formularios por plan (ver `Tenant::maxForms()`/
     * `FormResource::isFormLimitReached()`) — mismo patrón que
     * `ManageSliders`/`ManageTestimonials`.
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->slideOver()
                ->modalWidth('4xl')
                ->disabled(fn (): bool => FormResource::isFormLimitReached())
                ->tooltip(fn (): ?string => FormResource::isFormLimitReached() ? FormResource::formLimitMessage() : null)
                ->before(function (Actions\CreateAction $action) {
                    if (! FormResource::isFormLimitReached()) {
                        return;
                    }

                    Notification::make()->danger()->title('Límite del plan alcanzado')->body(FormResource::formLimitMessage())->send();
                    $action->halt();
                }),
        ];
    }
}
