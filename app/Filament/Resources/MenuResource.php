<?php

namespace App\Filament\Resources;

use App\Enums\LanguageEnum;
use App\Enums\LinkTargetEnum;
use App\Enums\MenuItemTypeEnum;
use App\Models\Menu;
use Filament\Forms;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Actions;
use App\Filament\Resources\MenuResource\Pages;
use Illuminate\Support\Str;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class MenuResource extends Resource
{
    protected static ?string $model = Menu::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-bars-3';

    protected static ?string $navigationLabel = 'Menús';

    protected static ?string $pluralLabel = 'Menús';

    protected static ?string $modelLabel = 'Menú';

    protected static ?string $slug = 'menus';

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
                        Forms\Components\Repeater::make('rootItems')
                            ->relationship('rootItems')
                            ->orderColumn('sort_order')
                            ->label('Elementos')
                            ->schema(static::menuItemFields())
                            ->itemLabel(fn (array $state): ?string => $state['title'] ?? 'Nuevo elemento')
                            ->collapsible()
                            ->collapsed(true)
                            ->defaultItems(0)
                            ->addActionLabel('Añadir elemento')
                            ->columnSpanFull()
                    ])
                    ->columnSpan(2),
            ])
            ->columns(3);
    }

    /**
     * Campos de un `MenuItem`, reutilizados en los 3 niveles del menú
     * (2026-08-31, pedido del Tech Lead: "sort hasta 3 niveles, menu, sub
     * menu y sub-submenu"). Antes había un único `Repeater` PLANO ligado a
     * `Menu::items()` (todos los items, sin importar su nivel) con un
     * `Select::make('parent_id')` manual para elegir el padre — funcional,
     * pero sin jerarquía visual y con un solo orden de arrastre que
     * mezclaba todos los niveles.
     *
     * Ahora la jerarquía es ESTRUCTURAL: 3 `Repeater`s anidados, cada uno
     * ligado a una relación real (`Menu::rootItems()` → `MenuItem::children()`
     * → `MenuItem::children()`, ambas ya existían en los modelos, sin
     * cambios de esquema) con su propio `orderColumn('sort_order')` — el
     * drag-to-sort de cada nivel reordena SOLO sus hermanos directos,
     * exactamente como pide el índice compuesto `[menu_id, parent_id,
     * sort_order]` que ya tenía la migración. El `Select` de `parent_id` ya
     * no hace falta: Filament asigna el padre correcto automáticamente por
     * la posición del item dentro de la jerarquía anidada.
     *
     * $depth 1 = nivel raíz (menú), 2 = submenú, 3 = sub-submenú (tope,
     * sin `children` anidado — 3 niveles es el máximo pedido).
     *
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    protected static function menuItemFields(int $depth = 1): array
    {
        $fields = [
            Grid::make(2)
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->label('Etiqueta / Título')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\Hidden::make('lang_iso')
                        ->default('es'),

                    Forms\Components\Select::make('type')
                        ->label('Tipo de enlace')
                        ->required()
                        ->options(MenuItemTypeEnum::class)
                        ->default(MenuItemTypeEnum::Page->value)
                        ->live()
                        ->afterStateUpdated(function (Set $set) {
                            $set('reference_id', null);
                            $set('url', null);
                        }),

                    Forms\Components\Select::make('reference_id')
                        ->label(function (Get $get) {
                            $type = $get('type');
                            $typeVal = $type instanceof \BackedEnum ? $type->value : $type;
                            return match ($typeVal) {
                                'page' => 'Página de destino',
                                'post' => 'Entrada de blog de destino',
                                default => 'Referencia',
                            };
                        })
                        ->options(function (Get $get) {
                            $type = $get('type');
                            $typeVal = $type instanceof \BackedEnum ? $type->value : $type;
                            if ($typeVal === 'page') {
                                return \App\Models\Page::pluck('title', 'id');
                            }
                            if ($typeVal === 'post') {
                                return \App\Models\Post::pluck('title', 'id');
                            }
                            return [];
                        })
                        ->searchable()
                        ->required(function (Get $get) {
                            $type = $get('type');
                            $typeVal = $type instanceof \BackedEnum ? $type->value : $type;
                            return in_array($typeVal, ['page', 'post']);
                        })
                        ->visible(function (Get $get) {
                            $type = $get('type');
                            $typeVal = $type instanceof \BackedEnum ? $type->value : $type;
                            return in_array($typeVal, ['page', 'post']);
                        }),

                    Forms\Components\TextInput::make('url')
                        ->label('URL / Ruta')
                        ->required(function (Get $get) {
                            $type = $get('type');
                            $typeVal = $type instanceof \BackedEnum ? $type->value : $type;
                            return in_array($typeVal, ['external', 'custom']);
                        })
                        ->maxLength(255)
                        ->placeholder('https://ejemplo.com o /ruta-interna')
                        ->visible(function (Get $get) {
                            $type = $get('type');
                            $typeVal = $type instanceof \BackedEnum ? $type->value : $type;
                            return in_array($typeVal, ['external', 'custom']);
                        }),

                    Forms\Components\Select::make('target')
                        ->label('Destino')
                        ->required()
                        ->options(LinkTargetEnum::class)
                        ->default(LinkTargetEnum::SameWindow->value),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Activo')
                        ->default(true)
                        ->inline(false)
                        ->required(),
                ]),
        ];

        if ($depth < 3) {
            $fields[] = Forms\Components\Repeater::make('children')
                ->relationship('children')
                ->orderColumn('sort_order')
                ->label($depth === 1 ? 'Submenú (nivel 2)' : 'Sub-submenú (nivel 3)')
                ->schema(static::menuItemFields($depth + 1))
                ->itemLabel(fn (array $state): ?string => $state['title'] ?? 'Nuevo elemento')
                ->collapsible()
                ->collapsed(true)
                ->defaultItems(0)
                ->addActionLabel('Añadir sub-elemento')
                ->columnSpanFull();
        }

        return $fields;
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
                    ->slideOver(),
                Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultPaginationPageOption(50)
            ->emptyStateActions([
                Actions\CreateAction::make()
                    ->slideOver(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageMenus::route('/'),
        ];
    }
}
