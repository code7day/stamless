<?php

namespace App\Filament\Resources\PageResource\Pages;

use App\Enums\LanguageEnum;
use App\Enums\PageTypeEnum;
use App\Enums\PublishStatusEnum;
use App\Filament\Resources\PageResource;
use App\Models\Page;
use App\Models\Tenant;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
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
     * Legal"): las 3 acciones de acá abajo (Página/Footer/Legal — "Header"
     * se descartó 2026-09-11, ver ADR-053) solo forzaban `type` después del
     * fill (`mutateFormDataUsing`) — `lang_iso`/`status` dependían de que
     * los `Hidden::make('lang_iso')->default('es')`/`Select::make('status')
     * ->default('draft')` de `PageResource::form()` sobrevivieran el
     * `->fillForm()` de un `CreateAction`, y en la práctica `lang_iso` NO
     * sobrevivía (llegaba `null` explícito al `insert`, pisando el default
     * de la COLUMNA en Postgres — un default de columna solo aplica cuando
     * la clave está AUSENTE del insert, no cuando está presente con `null`).
     * Mismo bug latente en todas, no solo en Legal — nunca se había
     * manifestado porque nadie había completado un guardado real por este
     * camino todavía. Fix: forzar `lang_iso`/`status` explícitos tanto en
     * `fillForm()` (precarga visible en el modal) como en
     * `mutateFormDataUsing()` (red de seguridad final antes del insert,
     * mismo patrón ya usado para `type`) — `$data['lang_iso'] ?? ...` para
     * no pisar un valor que el usuario sí llegó a cambiar a mano.
     */
    protected function getHeaderActions(): array
    {
        // Límite de contenidos por plan (2026-09-11, ver
        // `Tenant::maxContentsPerType()`/`PageResource::isContentLimitReached()`):
        // cada botón se deshabilita con un tooltip explicativo apenas el
        // tenant llega al tope de SU plan para ese tipo puntual — antes de
        // que el usuario llegue a abrir el modal y perder tiempo llenando
        // un formulario que al final no se va a poder guardar. El
        // `->before()` es la red de seguridad server-side (mismo criterio
        // que el resto de este archivo: nunca confiar solo en el estado
        // visual de un botón).
        $createActions = [
            Actions\CreateAction::make('create_page')
                ->label('Crear Página')
                ->modalHeading('Crear Página')
                ->icon('heroicon-o-document-text')
                ->model(Page::class)
                ->form(fn (Schema $schema) => static::getResource()::form($schema))
                ->fillForm(['type' => PageTypeEnum::Page->value, 'lang_iso' => LanguageEnum::Spanish->value, 'status' => PublishStatusEnum::Draft->value])
                ->mutateFormDataUsing(fn (array $data): array => array_merge($data, ['type' => PageTypeEnum::Page->value, 'lang_iso' => $data['lang_iso'] ?? LanguageEnum::Spanish->value, 'status' => $data['status'] ?? PublishStatusEnum::Draft->value]))
                ->slideOver()
                ->disabled(fn (): bool => PageResource::isContentLimitReached(PageTypeEnum::Page))
                ->tooltip(fn (): ?string => PageResource::isContentLimitReached(PageTypeEnum::Page) ? PageResource::contentLimitMessage(PageTypeEnum::Page) : null)
                ->before(function (Actions\CreateAction $action) {
                    if (! PageResource::isContentLimitReached(PageTypeEnum::Page)) {
                        return;
                    }

                    Notification::make()->danger()->title('Límite del plan alcanzado')->body(PageResource::contentLimitMessage(PageTypeEnum::Page))->send();
                    $action->halt();
                }),

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

            Actions\CreateAction::make('create_footer')
                ->label('Crear Pie de página (Footer)')
                ->modalHeading('Crear Pie de página (Footer)')
                ->icon('heroicon-o-rectangle-stack')
                ->model(Page::class)
                ->form(fn (Schema $schema) => static::getResource()::form($schema))
                ->fillForm(['type' => PageTypeEnum::Footer->value, 'lang_iso' => LanguageEnum::Spanish->value, 'status' => PublishStatusEnum::Draft->value])
                ->mutateFormDataUsing(fn (array $data): array => array_merge($data, ['type' => PageTypeEnum::Footer->value, 'lang_iso' => $data['lang_iso'] ?? LanguageEnum::Spanish->value, 'status' => $data['status'] ?? PublishStatusEnum::Draft->value]))
                ->slideOver()
                ->disabled(fn (): bool => PageResource::isContentLimitReached(PageTypeEnum::Footer))
                ->tooltip(fn (): ?string => PageResource::isContentLimitReached(PageTypeEnum::Footer) ? PageResource::contentLimitMessage(PageTypeEnum::Footer) : null)
                ->before(function (Actions\CreateAction $action) {
                    if (! PageResource::isContentLimitReached(PageTypeEnum::Footer)) {
                        return;
                    }

                    Notification::make()->danger()->title('Límite del plan alcanzado')->body(PageResource::contentLimitMessage(PageTypeEnum::Footer))->send();
                    $action->halt();
                }),

            Actions\CreateAction::make('create_legal')
                ->label('Crear Aviso Legal')
                ->modalHeading('Crear Aviso Legal')
                ->icon('heroicon-o-shield-check')
                ->model(Page::class)
                ->form(fn (Schema $schema) => static::getResource()::form($schema))
                ->fillForm(['type' => PageTypeEnum::Legal->value, 'lang_iso' => LanguageEnum::Spanish->value, 'status' => PublishStatusEnum::Draft->value])
                ->mutateFormDataUsing(fn (array $data): array => array_merge($data, ['type' => PageTypeEnum::Legal->value, 'lang_iso' => $data['lang_iso'] ?? LanguageEnum::Spanish->value, 'status' => $data['status'] ?? PublishStatusEnum::Draft->value]))
                ->slideOver()
                ->disabled(fn (): bool => PageResource::isContentLimitReached(PageTypeEnum::Legal))
                ->tooltip(fn (): ?string => PageResource::isContentLimitReached(PageTypeEnum::Legal) ? PageResource::contentLimitMessage(PageTypeEnum::Legal) : null)
                ->before(function (Actions\CreateAction $action) {
                    if (! PageResource::isContentLimitReached(PageTypeEnum::Legal)) {
                        return;
                    }

                    Notification::make()->danger()->title('Límite del plan alcanzado')->body(PageResource::contentLimitMessage(PageTypeEnum::Legal))->send();
                    $action->halt();
                }),
        ];

        return [
            Actions\ActionGroup::make($createActions)
                ->label('Crear Contenido')
                ->icon('heroicon-m-plus')
                ->button(),
        ];
    }

    /**
     * 2026-09-13, pedido del Tech Lead: contador de contenidos activos por
     * tipo directo en cada tab ("Páginas (5)", "Legales (0)", "Secciones
     * (1)") — mismo conteo que ya usa `PageResource::getNavigationBadge()`
     * para el agregado del sidebar, pero acá sin fracción "/límite": cada
     * tab ya representa un tipo puntual, mostrar el mismo tope 3 veces
     * (una por tab) sería ruido repetido en vez de información nueva.
     */
    public function getTabs(): array
    {
        $tenant = Filament::getTenant();
        $tenantId = $tenant instanceof Tenant ? $tenant->id : null;

        $countByType = fn (PageTypeEnum $type): int => $tenantId
            ? Page::where('tenant_id', $tenantId)->where('type', $type->value)->count()
            : 0;

        return [
            'paginas' => Tab::make('Páginas')
                ->icon('heroicon-o-document-text')
                ->badge($countByType(PageTypeEnum::Page))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('type', PageTypeEnum::Page->value)),

            'legales' => Tab::make('Legales')
                ->icon('heroicon-o-shield-check')
                ->badge($countByType(PageTypeEnum::Legal))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('type', PageTypeEnum::Legal->value)),

            'partials' => Tab::make('Secciones')
                ->icon('heroicon-o-squares-2x2')
                ->badge($countByType(PageTypeEnum::Footer))
                // Agrupaba Header + Footer; "Header" se descartó (2026-09-11,
                // ver ADR-053) por no tener ningún mecanismo real de consumo
                // (a diferencia de Footer, referenciado por `content.
                // footer_page_id` del bloque `footer`) — queda solo Footer,
                // pero se mantiene la tab/acción separadas de "Páginas" por
                // si en el futuro se suma otro tipo de partial reutilizable.
                ->modifyQueryUsing(fn (Builder $query) => $query->where('type', PageTypeEnum::Footer->value)),
        ];
    }
}
