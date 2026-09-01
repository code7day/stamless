<?php

namespace App\Filament\Resources\PageResource\Pages;

use App\Enums\PageTypeEnum;
use App\Filament\Resources\PageResource;
use App\Models\Page;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ManagePages extends ManageRecords
{
    protected static string $resource = PageResource::class;

    protected function getHeaderActions(): array
    {
        $createActions = [
            Actions\CreateAction::make('create_page')
                ->label('Crear Página')
                ->modalHeading('Crear Página')
                ->icon('heroicon-o-document-text')
                ->model(Page::class)
                ->form(fn (Schema $schema) => static::getResource()::form($schema))
                ->fillForm(['type' => PageTypeEnum::Page->value])
                ->mutateFormDataUsing(fn (array $data): array => array_merge($data, ['type' => PageTypeEnum::Page->value]))
                ->slideOver(),

            // Comentado para MVP:
            // Actions\CreateAction::make('create_landing')
            //     ->label('Crear Landing Page')
            //     ->modalHeading('Crear Landing Page')
            //     ->icon('heroicon-o-rocket-launch')
            //     ->model(Page::class)
            //     ->form(fn (Schema $schema) => static::getResource()::form($schema))
            //     ->fillForm(['type' => PageTypeEnum::Landing->value])
            //     ->mutateFormDataUsing(fn (array $data): array => array_merge($data, ['type' => PageTypeEnum::Landing->value]))
            //     ->slideOver(),

            Actions\CreateAction::make('create_header')
                ->label('Crear Cabecera (Header)')
                ->modalHeading('Crear Cabecera (Header)')
                ->icon('heroicon-o-bars-3')
                ->model(Page::class)
                ->form(fn (Schema $schema) => static::getResource()::form($schema))
                ->fillForm(['type' => PageTypeEnum::Header->value])
                ->mutateFormDataUsing(fn (array $data): array => array_merge($data, ['type' => PageTypeEnum::Header->value]))
                ->slideOver(),

            Actions\CreateAction::make('create_footer')
                ->label('Crear Pie de página (Footer)')
                ->modalHeading('Crear Pie de página (Footer)')
                ->icon('heroicon-o-rectangle-stack')
                ->model(Page::class)
                ->form(fn (Schema $schema) => static::getResource()::form($schema))
                ->fillForm(['type' => PageTypeEnum::Footer->value])
                ->mutateFormDataUsing(fn (array $data): array => array_merge($data, ['type' => PageTypeEnum::Footer->value]))
                ->slideOver(),

            Actions\CreateAction::make('create_legal')
                ->label('Crear Aviso Legal')
                ->modalHeading('Crear Aviso Legal')
                ->icon('heroicon-o-shield-check')
                ->model(Page::class)
                ->form(fn (Schema $schema) => static::getResource()::form($schema))
                ->fillForm(['type' => PageTypeEnum::Legal->value])
                ->mutateFormDataUsing(fn (array $data): array => array_merge($data, ['type' => PageTypeEnum::Legal->value]))
                ->slideOver(),
        ];

        return [
            Actions\ActionGroup::make($createActions)
                ->label('Crear Contenido')
                ->icon('heroicon-m-plus')
                ->button(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'paginas' => Tab::make('Páginas')
                ->icon('heroicon-o-document-text')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('type', PageTypeEnum::Page->value)),

            'legales' => Tab::make('Legales')
                ->icon('heroicon-o-shield-check')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('type', PageTypeEnum::Legal->value)),

            'partials' => Tab::make('Secciones')
                ->icon('heroicon-o-squares-2x2')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('type', [
                    PageTypeEnum::Header->value,
                    PageTypeEnum::Footer->value,
                ])),
        ];
    }
}
