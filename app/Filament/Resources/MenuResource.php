<?php

namespace App\Filament\Resources;

use App\Filament\Forms\Components\MenuTreeBuilder;
use App\Filament\Resources\MenuResource\Pages;
use App\Models\Menu;
use App\Models\MenuItem;
use Filament\Actions;
use Filament\Forms;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MenuResource extends Resource
{
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
                                    ->unique(ignoreRecord: true),

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
                    ->using(function (array $data, Menu $record): void {
                        $record->update(Arr::only($data, self::MENU_FIELDS));
                        static::syncMenuTree($record, $data['itemsTree'] ?? []);
                    }),
                Actions\DeleteAction::make(),
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
