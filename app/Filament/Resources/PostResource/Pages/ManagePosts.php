<?php

namespace App\Filament\Resources\PostResource\Pages;

use App\Filament\Resources\PostResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManagePosts extends ManageRecords
{
    protected static string $resource = PostResource::class;

    /**
     * Límite de publicaciones por plan (2026-09-11, ver
     * `Tenant::maxPosts()`/`PostResource::isPostLimitReached()`) — mismo
     * patrón que `ManagePages::getHeaderActions()`.
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->slideOver()
                ->disabled(fn (): bool => PostResource::isPostLimitReached())
                ->tooltip(fn (): ?string => PostResource::isPostLimitReached() ? PostResource::postLimitMessage() : null)
                ->before(function (Actions\CreateAction $action) {
                    if (! PostResource::isPostLimitReached()) {
                        return;
                    }

                    Notification::make()->danger()->title('Límite del plan alcanzado')->body(PostResource::postLimitMessage())->send();
                    $action->halt();
                }),
        ];
    }
}
