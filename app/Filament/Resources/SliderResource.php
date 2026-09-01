<?php

namespace App\Filament\Resources;

use App\Enums\SlideBackgroundTypeEnum;
use App\Filament\Resources\SliderResource\Pages;
use App\Filament\Schemas\HeadingFieldset;
use App\Filament\Schemas\LinkSchema;
use App\Filament\Schemas\MediaUpload;
use App\Filament\Schemas\PropertiesSchema;
use App\Models\Slider;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class SliderResource extends Resource
{
    protected static ?string $model = Slider::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-presentation-chart-bar';

    protected static ?string $navigationLabel = 'Sliders (Carruseles)';

    protected static ?string $pluralLabel = 'Sliders';

    protected static ?string $modelLabel = 'Slider';

    protected static ?string $slug = 'sliders';

    public static function form(Schema $schema): Schema
    {
        // Único CTA por slide (2026-08-30) — ver LinkSchema::makeSingle().
        // Se computa una vez y se reutiliza en las dos columnas del tab
        // "Enlaces" (campos principales / propiedades del enlace).
        $linkFields = LinkSchema::makeSingle('links');

        return $schema
            ->components([
                Section::make('General')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('title')
                                    ->label('Título')
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

                                Forms\Components\Toggle::make('is_active')
                                    ->label('Activo')
                                    ->default(true)
                                    ->required(),
                            ]),

                        // `show_scroll_indicator` vive a nivel Slider, NO por
                        // slide (2026-08-31, corrección del Tech Lead sobre la
                        // primera pasada de este mismo día: "las propiedades
                        // del slider en general, no dentro de cada slide, solo
                        // una vez desde el slider para sobreponerse sobre el
                        // decorador... para todos los slides detrás"). Reusa
                        // el mismo campo de `PropertiesSchema` que ya usa
                        // Texto Enriquecido, pero bindeado a `properties.*`
                        // del propio Slider (columna jsonb nueva, ver
                        // migración `..._add_properties_to_sliders_table`),
                        // no del Slide. El front (`Hero.astro`) la dibuja UNA
                        // sola vez, fuera del `.map()` de slides.
                        Fieldset::make('Flecha de scroll')
                            ->schema(PropertiesSchema::makeComponents(['show_scroll_indicator']))
                            ->columns(1),
                    ])
                    ->columnSpanFull(),

                Forms\Components\Repeater::make('slides')
                    ->relationship('slides')
                    ->orderColumn('sort_order')
                    ->label('Slides')
                    ->itemLabel(fn (array $state): ?string => $state['title'] ?? 'Nuevo slide')
                    ->collapsible()
                    ->collapsed()
                    ->defaultItems(0)
                    ->columnSpanFull()
                    ->schema([
                                Tabs::make('SlideTabs')
                                    ->tabs([
                                        Tabs\Tab::make('Contenido')
                                            ->icon('heroicon-m-document-text')
                                            ->schema([
                                                HeadingFieldset::make(),

                                                Grid::make(2)
                                                    ->schema([
                                                        Forms\Components\Hidden::make('lang_iso')
                                                            ->default('es'),

                                                        Forms\Components\Toggle::make('is_active')
                                                            ->label('Activo')
                                                            ->default(true)
                                                            ->required(),
                                                    ]),
                                            ]),

                                        Tabs\Tab::make('Fondo')
                                            ->icon('heroicon-m-photo')
                                            ->schema([
                                                Forms\Components\Select::make('background_type')
                                                    ->label('Tipo de fondo')
                                                    ->required()
                                                    ->options(SlideBackgroundTypeEnum::class)
                                                    ->default(SlideBackgroundTypeEnum::Image->value)
                                                    ->live(),

                                                Grid::make(3)
                                                    ->visible(function (Get $get) {
                                                        $val = $get('background_type');

                                                        return ($val instanceof \BackedEnum ? $val->value : $val) === 'image';
                                                    })
                                                    ->schema([
                                                        MediaUpload::make('image_desktop_id', 'Imagen Desktop')
                                                            ->required(function (Get $get) {
                                                                $val = $get('background_type');

                                                                return ($val instanceof \BackedEnum ? $val->value : $val) === 'image';
                                                            }),

                                                        MediaUpload::make('image_tablet_id', 'Imagen Tablet'),

                                                        MediaUpload::make('image_mobile_id', 'Imagen Móvil'),
                                                    ]),

                                                Grid::make(2)
                                                    ->visible(function (Get $get) {
                                                        $val = $get('background_type');

                                                        return ($val instanceof \BackedEnum ? $val->value : $val) === 'video';
                                                    })
                                                    ->schema([
                                                        MediaUpload::make('video_desktop_id', 'Video Desktop', 'video')
                                                            ->required(function (Get $get) {
                                                                $val = $get('background_type');

                                                                return ($val instanceof \BackedEnum ? $val->value : $val) === 'video';
                                                            }),

                                                        MediaUpload::make('video_mobile_id', 'Video Móvil', 'video'),
                                                    ]),
                                            ]),

                                        Tabs\Tab::make('Enlaces')
                                            ->icon('heroicon-m-link')
                                            ->schema([
                                                Grid::make(4)
                                                    ->schema([
                                                        // Columna Izquierda (span 2): video de presentación + CTA único.
                                                        // Un solo CTA por slide (2026-08-30, a pedido del Tech Lead:
                                                        // "solo tendrá un boton CTA no se necesita multiples enlaces")
                                                        // — LinkSchema::makeSingle() en vez de LinkSchema::make()
                                                        // (Repeater). `links` sigue siendo array en DB/API (un solo
                                                        // elemento), no cambia el contrato público.
                                                        Group::make([
                                                            Grid::make(2)
                                                                ->schema([
                                                                    Forms\Components\Toggle::make('has_presentation_video')
                                                                        ->label('Tiene video de presentación')
                                                                        ->default(false)
                                                                        ->inline(false)
                                                                        ->live(),

                                                                    Forms\Components\TextInput::make('presentation_youtube_id')
                                                                        ->label('YouTube Video ID')
                                                                        ->maxLength(50)
                                                                        ->visible(fn (Get $get) => $get('has_presentation_video') === true),
                                                                ]),

                                                            Grid::make(2)
                                                                ->schema($linkFields['main']),
                                                        ])
                                                            ->columnSpan(2),

                                                        // Columna Derecha (span 2): propiedades del enlace (target de
                                                        // apertura, alt SEO, clase CSS, id HTML) — antes anidadas
                                                        // dentro de cada item del Repeater, ahora que es un único CTA
                                                        // se sacan a su propio fieldset "Propiedades" en la columna
                                                        // donde antes vivía "Posición y Contenido" (que se movió al
                                                        // tab "Estilos", ver más abajo).
                                                        Fieldset::make('Propiedades')
                                                            ->schema($linkFields['properties'])
                                                            ->columns(2)
                                                            ->columnSpan(2),
                                                    ]),
                                            ]),

                                        // "Posición y Contenido" (movida acá desde el tab "Enlaces",
                                        // 2026-08-30) + "Decorador y Efectos" (renombrado) fusionados
                                        // en un único tab "Estilos" — a pedido del Tech Lead.
                                        Tabs\Tab::make('Estilos')
                                            ->icon('heroicon-m-sparkles')
                                            ->schema([
                                                Fieldset::make('Posición y Contenido')
                                                    ->schema(
                                                        PropertiesSchema::makeComponents(['position_container', 'align_content'])
                                                    )
                                                    ->columns(2),

                                                // `show_scroll_indicator` NO vive acá (corrección del mismo
                                                // día, 2026-08-31): el Tech Lead aclaró que es una property
                                                // del Slider en general, una sola vez para todas las slides
                                                // ("sobrepuesta... para todos los slides detrás"), no algo
                                                // que se prenda/apague por slide individual — ver el nuevo
                                                // fieldset "Flecha de scroll" en la sección "General" arriba.
                                                Fieldset::make('Decorador inferior')
                                                    ->schema(
                                                        PropertiesSchema::makeComponents([
                                                            'decorator_bottom', 'decorator_bottom_color', 'decorator_bottom_opacity',
                                                        ])
                                                    )
                                                    ->columns(3),

                                                Fieldset::make('Fondo del slide')
                                                    ->schema(
                                                        PropertiesSchema::makeComponents([
                                                            'slide_background_color',
                                                            'slide_background_blend_mode',
                                                            'slide_background_brightness',
                                                            'slide_background_opacity',
                                                            'slide_background_filter_saturate',
                                                            'slide_background_filter_grayscale',
                                                            'slide_background_filter_sepia',
                                                            'slide_background_filter_contrast',
                                                            'slide_background_filter_hue_rotate',
                                                            'slide_background_filter_blur',
                                                        ])
                                                    )
                                                    ->columns(2),
                                            ]),
                                    ])
                                    ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Título')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->sortable(),

                // 2026-08-31, pedido del Tech Lead: mismo criterio que
                // `is_home` en PageResource — un solo ícono de check en los
                // dos estados (gris apagado / verde prendido), clickeable
                // tipo toggle en vez de tilde verde / X roja. Acá no hay
                // regla de "uno solo a la vez" (a diferencia de `is_home`):
                // cualquier cantidad de sliders puede estar activa.
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->action(
                        Actions\Action::make('toggleIsActive')
                            ->action(fn (Slider $record) => $record->update(['is_active' => ! $record->is_active]))
                    )
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Activo'),
            ])
            ->actions([
                Actions\EditAction::make()
                    ->slideOver()
                    ->modalWidth('5xl'),
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
                    ->slideOver()
                    ->modalWidth('5xl'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageSliders::route('/'),
        ];
    }
}
