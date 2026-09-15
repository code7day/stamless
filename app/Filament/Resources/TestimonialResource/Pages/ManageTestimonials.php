<?php

namespace App\Filament\Resources\TestimonialResource\Pages;

use App\Filament\Resources\TestimonialResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageTestimonials extends ManageRecords
{
    protected static string $resource = TestimonialResource::class;

    /**
     * Límite de testimonios por plan (2026-09-11, ver
     * `Tenant::maxTestimonials()`/`TestimonialResource::isTestimonialLimitReached()`).
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->slideOver()
                ->modalWidth('2xl')
                ->disabled(fn (): bool => TestimonialResource::isTestimonialLimitReached())
                ->tooltip(fn (): ?string => TestimonialResource::isTestimonialLimitReached() ? TestimonialResource::testimonialLimitMessage() : null)
                ->before(function (Actions\CreateAction $action) {
                    if (! TestimonialResource::isTestimonialLimitReached()) {
                        return;
                    }

                    Notification::make()->danger()->title('Límite del plan alcanzado')->body(TestimonialResource::testimonialLimitMessage())->send();
                    $action->halt();
                }),
        ];
    }
}
