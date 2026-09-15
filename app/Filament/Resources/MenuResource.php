<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\FormatsUsageBadge;
use App\Filament\Forms\Components\MenuTreeBuilder;
use App\Filament\Resources\MenuResource\Pages;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Tenant;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MenuResource extends Resource
{
    use FormatsUsageBadge;

    protected static ?string $model = Menu::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-bars-3';

    protected static ?string $navigationLabel = 'Menús';

    protected static ?string $pluralLabel = 'Menús';

    protected static ?string $modelLabel = 'Menú';

    protected static ?string $slug = 'menus';

    /**
     * Campos propios de `Menu` (no de sus items) que `syncMenuTree()`
     * NO debe tocar — únicos que `Menu::create()`/`$record->update()`
     * reciben del `$data` completo del form.
     */
    private const array MENU_FIELDS = ['name', 'slug', 'lang_iso'];

    /**
     * Límite de items de menú por plan (2026-09-11, pedido del Tech Lead:
     * "para free con 7 items de menu y para auspicio con 12 items") — a
     * diferencia de todos los demás recursos con límite (Page/Post/Service/
     * Testimonial/Slider/Media, un `->disabled()` en el botón "Crear" que
     * compara contra un `count()` YA guardado en DB), acá no hay un botón
     * "Crear item" individual: `itemsTree` es un árbol completo que se
     * edita en el navegador y se sincroniza TODO junto recién al guardar el
     * `Menu` (ver `syncMenuTree()`). Por eso el gate va en `->before()` de
     * las acciones Crear/Editar del `Menu`, contra el `itemsTree` que
     * llega en `$data` — no se puede deshabilitar un botón por adelantado
     * porque el conteo final depende de lo que el usuario arme en el
     * editor antes de guardar.
     *
     * Cuenta el array COMPLETO (todos los niveles de profundidad, no solo
     * la raíz) — "items de menú" en el pedido del Tech Lead no distingue
     * por nivel, y `itemsTree` ya es un array plano con un `depth` por
     * ítem, así que `count()` sobre él es literalmente el total de items
     * del menú sin importar si son de nivel 1, 2 o 3.
     *
     * @param  array<int, array<string, mixed>>  $itemsTree
     */
    private static function exceedsMenuItemLimit(array $itemsTree): bool
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return false;
        }

        $limit = $tenant->maxMenuItems();

        if ($limit === null) {
            return false;
        }

        return count($itemsTree) > $limit;
    }

    private static function menuItemLimitMessage(): string
    {
        $tenant = Filament::getTenant();
        $limit = $tenant instanceof Tenant ? $tenant->maxMenuItems() : null;

        return "El plan actual permite hasta {$limit} items de menú en total (todos los niveles). Quita algunos antes de guardar.";
    }

    /**
     * Límite de CANTIDAD de menús (2026-09-13, ver `Tenant::maxMenus()`) —
     * distinto de `exceedsMenuItemLimit()` de arriba, que limita items
     * DENTRO de cada menú. Mismo patrón que `isPostLimitReached()`/etc. del
     * resto de resources simples.
     */
    public static function isMenuLimitReached(): bool
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return false;
        }

        $limit = $tenant->maxMenus();

        if ($limit === null) {
            return false;
        }

        return Menu::where('tenant_id', $tenant->id)->count() >= $limit;
    }

    public static function menuLimitMessage(): string
    {
        $tenant = Filament::getTenant();
        $limit = $tenant instanceof Tenant ? $tenant->maxMenus() : null;

        return "El plan actual permite hasta {$limit} menús. Para crear uno nuevo, eliminar primero alguno existente.";
    }

    /**
     * 2026-09-13, pedido del Tech Lead: badge "usado/límite" en la opción
     * de menú del sidebar (ver `FormatsUsageBadge` y `Tenant::maxMenus()`).
     */
    public static function getNavigationBadge(): ?string
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return self::formatUsageBadge(Menu::where('tenant_id', $tenant->id)->count(), $tenant->maxMenus());
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return self::usageBadgeColor(Menu::where('tenant_id', $tenant->id)->count(), $tenant->maxMenus());
    }

    /**
     * 2026-09-13 (ADR-061 addendum): mismo helper que Page/Post/Service/
     * Slider para generar un slug único al duplicar, scopeado por
     * `lang_iso`.
     */
    private static function duplicateSlug(Menu $record): string
    {
        $base = $record->slug.'-copia';
        $candidate = $base;
        $suffix = 2;

        while (Menu::where('tenant_id', $record->tenant_id)->where('slug', $candidate)->where('lang_iso', $record->lang_iso)->exists()) {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Clona recursivamente el árbol de `MenuItem`s (hasta 3 niveles vía
     * `parent_id`, ver `MENU_FIELDS`/`syncMenuTree()`) hacia el `Menu`
     * duplicado. Se recorre nivel por nivel para poder reasignar
     * `parent_id` de cada hijo al ID YA GUARDADO de su padre recién
     * clonado — no alcanza con `replicate()` simple porque los hijos
     * necesitan apuntar a las copias nuevas, no a los items originales.
     *
     * @param  Collection<int, MenuItem>  $items
     */
    private static function duplicateMenuItemsRecursive($items, ?int $newParentId, int $newMenuId): void
    {
        foreach ($items as $item) {
            $newItem = $item->replicate(['uuid']);
            $newItem->tenant_id = $item->tenant_id;
            $newItem->menu_id = $newMenuId;
            $newItem->parent_id = $newParentId;
            $newItem->save();

            if ($item->children->isNotEmpty()) {
                self::duplicateMenuItemsRecursive($item->children, $newItem->id, $newMenuId);
            }
        }
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('')
                    ->schema([
                        Grid::make(1)
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('Nombre')
                                    ->required()
                                    ->maxLength(255)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (string $operation, $state, Set $set) => $operation === 'create' ? $set('slug', Str::slug($state)) : null),

                                Forms\Components\TextInput::make('slug')
                                    ->label('Slug')
                                    ->required()
                                    ->maxLength(255)
                                    ->scopedUnique(
                                        model: Menu::class,
                                        column: 'slug',
                                        ignoreRecord: true,
                                        modifyQueryUsing: fn ($query) => $query->where('tenant_id', Filament::getTenant()?->id ?? auth()->user()?->tenant_id),
                                    ),

                                Forms\Components\Hidden::make('lang_iso')
                                    ->default('es'),
                            ]),
                    ]),

                Group::make()
                    ->schema([
                        // 2026-09-02 — reemplaza los 3 `Repeater`s anidados
                        // (menu/submenú/sub-submenú) que tenía este form
                        // antes: el Tech Lead pidió, viendo el editor
                        // clásico de menús de WordPress como referencia,
                        // "manejar el anidar o cambiar a parent similar a
                        // WordPress" — algo que un Repeater-dentro-de-
                        // Repeater no puede dar (cada nivel es su propia
                        // lista aislada, sin forma de "arrastrar para
                        // anidar" entre niveles). Se evaluaron 4 plugins de
                        // Filament (ver `App\Filament\Forms\Components\
                        // MenuTreeBuilder` para el detalle de por qué se
                        // descartaron todos) y se optó por un campo propio.
                        //
                        // El campo trabaja sobre un array PLANO con
                        // profundidad por ítem (`itemsTree`, NO una
                        // relación Eloquent nativa de Filament) — por eso
                        // la hidratación y el guardado NO son declarativos
                        // acá abajo, están en `flattenMenuTree()`/
                        // `syncMenuTree()` más abajo en esta clase, y se
                        // conectan al guardado real vía `->using()` en las
                        // acciones Crear/Editar (ver `table()` y
                        // `MenuResource\Pages\ManageMenus`).
                        MenuTreeBuilder::make('itemsTree')
                            ->label('Elementos')
                            ->maxDepth(3)
                            ->afterStateHydrated(function (MenuTreeBuilder $component, ?Model $record) {
                                $component->state($record instanceof Menu ? static::flattenMenuTree($record) : []);
                            })
                            ->columnSpanFull(),
                    ])
                    ->columnSpan(2),
            ])
            ->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->sortable(),

            ])
            ->filters([

            ])
            ->actions([
                // 2026-09-13 (ADR-059, addendum): "los listados de cada
                // apartado o modulo, agrupar las acciones" — mismo patrón
                // aplicado en `ApiTokens::table()`: acciones de fila
                // agrupadas en un menú desplegable en vez de enlaces
                // sueltos.
                Actions\ActionGroup::make([
                    Actions\EditAction::make()
                        ->slideOver()
                        // 2026-09-02 — `itemsTree` no es una columna real de
                        // `Menu` ni una relación nativa de Filament (ver
                        // `form()` arriba), así que el guardado default de
                        // `EditAction` ($record->update($data) con TODO
                        // `$data`, incluido `itemsTree`) no sirve: `Menu`
                        // ignora esa key silenciosamente por no estar en su
                        // `#[Fillable]`, y los items nunca se sincronizan.
                        // `->using()` reemplaza el proceso default por
                        // completo: actualiza solo los campos propios de
                        // `Menu` y sincroniza el árbol aparte.
                        ->before(function (array $data, Actions\EditAction $action) {
                            if (! self::exceedsMenuItemLimit($data['itemsTree'] ?? [])) {
                                return;
                            }

                            Notification::make()->danger()->title('Límite del plan alcanzado')->body(self::menuItemLimitMessage())->send();
                            $action->halt();
                        })
                        ->using(function (array $data, Menu $record): void {
                            $record->update(Arr::only($data, self::MENU_FIELDS));
                            static::syncMenuTree($record, $data['itemsTree'] ?? []);
                        }),

                    // Duplicar (2026-09-13, ADR-061 addendum): clona el Menú
                    // (nuevo uuid + slug único) y TODO su árbol de items
                    // (recursivo hasta 3 niveles, ver
                    // `duplicateMenuItemsRecursive()`). Gateado por
                    // `isMenuLimitReached()` (agregado el mismo día,
                    // `Tenant::maxMenus()`) — duplicar cuenta como crear un
                    // menú nuevo, no debe esquivar el tope de cantidad. El
                    // límite de items DENTRO del menú (`maxMenuItems()`) no
                    // aplica acá: duplicar conserva la misma cantidad de
                    // items que ya tenía el original, que por construcción
                    // ya pasó ese límite al guardarse.
                    Actions\ReplicateAction::make()
                        ->label('Duplicar')
                        // 2026-09-13 (ADR-061, addendum UX): modal propio en
                        // vez del genérico "Replicar :label" de Filament.
                        ->modalHeading(fn (Menu $record): string => "¿Duplicar el menú \"{$record->name}\"?")
                        ->modalDescription('Se creará una copia con todos sus elementos (incluidos submenús), lista para editar de forma independiente.')
                        ->modalSubmitActionLabel('Sí, duplicar')
                        ->modalFooterActionsAlignment('center')
                        ->excludeAttributes(['uuid', 'slug'])
                        ->beforeReplicaSaved(function (Menu $record, Menu $replica): void {
                            $replica->name = "{$record->name} (copia)";
                            $replica->slug = self::duplicateSlug($record);
                        })
                        ->after(function (Menu $record, Actions\ReplicateAction $action): void {
                            $replica = $action->getReplica();

                            self::duplicateMenuItemsRecursive(
                                $record->rootItems()->with('children.children')->get(),
                                null,
                                $replica->id,
                            );
                        })
                        ->disabled(fn (): bool => self::isMenuLimitReached())
                        ->tooltip(fn (): ?string => self::isMenuLimitReached() ? self::menuLimitMessage() : null)
                        ->before(function (Actions\ReplicateAction $action) {
                            if (! self::isMenuLimitReached()) {
                                return;
                            }

                            Notification::make()->danger()->title('Límite del plan alcanzado')->body(self::menuLimitMessage())->send();
                            $action->halt();
                        })
                        ->successNotificationTitle('Menú duplicado'),

                    Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultPaginationPageOption(50)
            ->emptyStateActions([
                static::createAction(),
            ]);
    }

    /**
     * `CreateAction` con el mismo `->using()` custom que `EditAction` en
     * `table()` de arriba — extraído a un método propio porque hace
     * falta en 2 lugares con la MISMA configuración: acá (estado vacío
     * de la tabla) y en `MenuResource\Pages\ManageMenus::getHeaderActions()`
     * (botón "+ Crear" de la cabecera, el que se usa cuando ya hay al
     * menos un menú). Repetir el `->using()` en los 2 sitios sería fácil
     * de desincronizar si se toca uno y no el otro.
     */
    public static function createAction(): Actions\CreateAction
    {
        return Actions\CreateAction::make()
            ->slideOver()
            // 2026-09-13: límite de CANTIDAD de menús (`isMenuLimitReached()`)
            // — a diferencia del límite de items de abajo, este ya se sabe
            // ANTES de abrir el form (no depende de `itemsTree`), así que
            // sigue el mismo patrón `disabled()`/`tooltip()` que el resto de
            // recursos con límite (avisa antes de que el usuario pierda
            // tiempo llenando el modal).
            ->disabled(fn (): bool => self::isMenuLimitReached())
            ->tooltip(fn (): ?string => self::isMenuLimitReached() ? self::menuLimitMessage() : null)
            ->before(function (array $data, Actions\CreateAction $action) {
                if (self::isMenuLimitReached()) {
                    Notification::make()->danger()->title('Límite del plan alcanzado')->body(self::menuLimitMessage())->send();
                    $action->halt();

                    return;
                }

                if (! self::exceedsMenuItemLimit($data['itemsTree'] ?? [])) {
                    return;
                }

                Notification::make()->danger()->title('Límite del plan alcanzado')->body(self::menuItemLimitMessage())->send();
                $action->halt();
            })
            ->using(function (array $data): Menu {
                $menu = Menu::create(Arr::only($data, self::MENU_FIELDS));
                static::syncMenuTree($menu, $data['itemsTree'] ?? []);

                return $menu;
            });
    }

    /**
     * Aplana el árbol real de `MenuItem`s de un `Menu` (guardado como
     * `parent_id` + `sort_order` por nivel) a un array PLANO con
     * `depth` (0 = raíz, 1 = submenú, 2 = sub-submenú) por ítem — la
     * forma que espera `MenuTreeBuilder` en el navegador. Recorrido en
     * profundidad (DFS): cada hijo aparece INMEDIATAMENTE después de su
     * padre, en el mismo orden que se ve en el editor.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function flattenMenuTree(Menu $menu): array
    {
        $itemsByParent = [];

        foreach ($menu->items()->orderBy('sort_order')->get() as $item) {
            $itemsByParent[$item->parent_id ?? 'root'][] = $item;
        }

        $flat = [];

        $walk = function ($parentKey, int $depth) use (&$walk, &$flat, $itemsByParent): void {
            foreach ($itemsByParent[$parentKey] ?? [] as $item) {
                $flat[] = [
                    'id' => $item->id,
                    'depth' => $depth,
                    'title' => $item->title,
                    'type' => $item->type?->value ?? $item->type,
                    'reference_id' => $item->reference_id,
                    'url' => $item->url,
                    'target' => $item->target?->value ?? $item->target,
                    'is_active' => (bool) $item->is_active,
                ];

                if ($depth < 2) {
                    $walk($item->id, $depth + 1);
                }
            }
        };

        $walk('root', 0);

        return $flat;
    }

    /**
     * Reconstruye `menu_items` a partir del array plano que llega del
     * navegador (`itemsTree`, ver `MenuTreeBuilder`/`flattenMenuTree()`
     * arriba) — inverso exacto: el ORDEN del array + el `depth` de cada
     * ítem determinan `parent_id`/`sort_order` reales.
     *
     * Algoritmo (mismo principio que usa WordPress para su editor de
     * menús clásico): se recorre el array en orden manteniendo una pila
     * "último id visto por profundidad" (`$parentAtDepth`) — el padre de
     * un ítem es `$parentAtDepth[depth - 1]`. `sort_order` es un
     * contador independiente POR padre (`$sortCounters`, keyeado por
     * `parent_id` o `'root'`).
     *
     * Los ítems existentes (`id` presente) se actualizan; los nuevos
     * (`id` null, recién creados en el navegador) se insertan; cualquier
     * `MenuItem` de este `Menu` que NO aparezca en el array final se
     * borra (soporta reordenar, anidar/desanidar, y eliminar, todo en un
     * solo guardado) — mismo criterio de "sync" que ya hace Filament
     * nativamente para un `Repeater` con `->relationship()`, reimplementado
     * a mano acá porque este campo no es un Repeater nativo.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private static function syncMenuTree(Menu $menu, array $items): void
    {
        $items = array_values($items);

        DB::transaction(function () use ($menu, $items): void {
            $parentAtDepth = [];
            $sortCounters = [];
            $keptIds = [];

            foreach ($items as $item) {
                $depth = max(0, min(2, (int) ($item['depth'] ?? 0)));
                $parentId = $depth > 0 ? ($parentAtDepth[$depth - 1] ?? null) : null;
                $sortKey = $parentId ?? 'root';
                $sortOrder = $sortCounters[$sortKey] ??= 0;

                $attributes = [
                    'tenant_id' => $menu->tenant_id,
                    'menu_id' => $menu->id,
                    'parent_id' => $parentId,
                    'lang_iso' => $menu->lang_iso,
                    'title' => (string) ($item['title'] ?? ''),
                    'type' => $item['type'] ?? 'custom',
                    'reference_id' => $item['reference_id'] ?? null,
                    'url' => $item['url'] ?? null,
                    'target' => $item['target'] ?? '_self',
                    'is_active' => (bool) ($item['is_active'] ?? true),
                    'sort_order' => $sortOrder,
                ];

                $existingId = $item['id'] ?? null;
                $menuItem = $existingId ? $menu->items()->whereKey($existingId)->first() : null;

                if ($menuItem) {
                    $menuItem->fill($attributes);
                    $menuItem->save();
                } else {
                    $menuItem = MenuItem::create($attributes);
                }

                $keptIds[] = $menuItem->id;
                $parentAtDepth[$depth] = $menuItem->id;
                $sortCounters[$sortKey] = $sortOrder + 1;

                // Cualquier profundidad más honda que la del ítem que se
                // acaba de procesar queda obsoleta — el próximo ítem que
                // aparezca a esa profundidad (o menor) debe engancharse
                // acá, no a un ancestro viejo de una rama ya cerrada.
                foreach (array_keys($parentAtDepth) as $existingDepth) {
                    if ($existingDepth > $depth) {
                        unset($parentAtDepth[$existingDepth]);
                    }
                }
            }

            $menu->items()->whereNotIn('id', $keptIds)->delete();
        });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageMenus::route('/'),
        ];
    }
}
