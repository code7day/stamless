<?php

namespace App\Filament\Resources\PageResource\Pages;

use App\Enums\LanguageEnum;
use App\Enums\PageTypeEnum;
use App\Enums\PublishStatusEnum;
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

    /**
     * Fix real (2026-09-01, reportado por el Tech Lead: `SQLSTATE[23502]:
     * Not null violation ... column "lang_iso"` al guardar "Crear Aviso
     * Legal"): las 4 acciones de acá abajo solo forzaban `type` después del
     * fill (`mutateFormDataUsing`) — `lang_iso`/`status` dependían de que
     * los `Hidden::make('lang_iso')->default('es')`/`Select::make('status')
     * ->default('draft')` de `PageResource::form()` sobrevivieran el
     * `->fillForm()` de un `CreateAction`, y en la práctica `lang_iso` NO
     * sobrevivía (llegaba `null` explícito al `insert`, pisando el default
     * de la COLUMNA en Postgres — un default de columna solo aplica cuando
     * la clave está AUSENTE del insert, no cuando está presente con `null`).
     * Mismo bug latente en las 4, no solo en Legal — nunca se había
     * manifestado porque nadie había completado un guardado real por este
     * camino todavía. Fix: forzar `lang_iso`/`status` explícitos tanto en
     * `fillForm()` (precarga visible en el modal) como en
     * `mutateFormDataUsing()` (red de seguridad final antes del insert,
     * mismo patrón ya usado para `type`) — `$data['lang_iso'] ?? ...` para
     * no pisar un valor que el usuario sí llegó a cambiar a mano.
     */
    protected function getHeaderActions(): array
    {
        $createActions = [
            Actions\CreateAction::make('create_page')
                ->label('Crear Página')
                ->modalHeading('Crear Página')
                ->icon('heroicon-o-document-text')
                ->model(Page::class)
                ->form(fn (Schema $schema) => static::getResource()::form($schema))
                ->fillForm(['type' => PageTypeEnum::Page->value, 'lang_iso' => LanguageEnum::Spanish->value, 'status' => PublishStatusEnum::Draft->value])
                ->mutateFormDataUsing(fn (array $data): array => array_merge($data, ['type' => PageTypeEnum::Page->value, 'lang_iso' => $data['lang_iso'] ?? LanguageEnum::Spanish->value, 'status' => $data['status'] ?? PublishStatusEnum::Draft->value]))
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
                ->fillForm(['type' => PageTypeEnum::Header->value, 'lang_iso' => LanguageEnum::Spanish->value, 'status' => PublishStatusEnum::Draft->value])
                ->mutateFormDataUsing(fn (array $data): array => array_merge($data, ['type' => PageTypeEnum::Header->value, 'lang_iso' => $data['lang_iso'] ?? LanguageEnum::Spanish->value, 'status' => $data['status'] ?? PublishStatusEnum::Draft->value]))
                ->slideOver(),

            Actions\CreateAction::make('create_footer')
                ->label('Crear Pie de página (Footer)')
                ->modalHeading('Crear Pie de página (Footer)')
                ->icon('heroicon-o-rectangle-stack')
                ->model(Page::class)
                ->form(fn (Schema $schema) => static::getResource()::form($schema))
                ->fillForm(['type' => PageTypeEnum::Footer->value, 'lang_iso' => LanguageEnum::Spanish->value, 'status' => PublishStatusEnum::Draft->value])
                ->mutateFormDataUsing(fn (array $data): array => array_merge($data, ['type' => PageTypeEnum::Footer->value, 'lang_iso' => $data['lang_iso'] ?? LanguageEnum::Spanish->value, 'status' => $data['status'] ?? PublishStatusEnum::Draft->value]))
                ->slideOver(),

            Actions\CreateAction::make('create_legal')
                ->label('Crear Aviso Legal')
                ->modalHeading('Crear Aviso Legal')
                ->icon('heroicon-o-shield-check')
                ->model(Page::class)
                ->form(fn (Schema $schema) => static::getResource()::form($schema))
                ->fillForm(['type' => PageTypeEnum::Legal->value, 'lang_iso' => LanguageEnum::Spanish->value, 'status' => PublishStatusEnum::Draft->value])
                ->mutateFormDataUsing(fn (array $data): array => array_merge($data, ['type' => PageTypeEnum::Legal->value, 'lang_iso' => $data['lang_iso'] ?? LanguageEnum::Spanish->value, 'status' => $data['status'] ?? PublishStatusEnum::Draft->value]))
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
