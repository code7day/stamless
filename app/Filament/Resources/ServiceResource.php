<?php

namespace App\Filament\Resources;

use App\Enums\CountryEnum;
use App\Enums\PublishStatusEnum;
use App\Filament\Resources\ServiceResource\Pages;
use App\Filament\Schemas\HeadingFieldset;
use App\Filament\Schemas\LinkSchema;
use App\Filament\Schemas\MediaUpload;
use App\Filament\Schemas\PropertiesSchema;
use App\Models\Service;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Módulo de Servicios (2026-08-31, pedido directo del Tech Lead con
 * capturas del catálogo "Servicios" y del detalle de "Seguros generales"):
 * "similar a páginas... una tabla de servicios con contenido en JSONB y
 * links, meta y properties también... un recurso bien elaborado para
 * gestionar de forma amigable y cómoda UX". Cada `Service` es a la vez (1)
 * una card del catálogo (imagen + título + subtítulo + banderas de país +
 * botón "Quiero saber" que navega a su propio slug) y (2) su página de
 * detalle completa (banner, intro, tabs "¿Qué ofrecemos?"/"Coberturas",
 * "¿Por qué elegirnos?", tip de ayuda) — ver ADR correspondiente en
 * `docs/context/DECISIONS.md` para el detalle de por qué es tabla propia
 * y no un `Builder` de bloques como `pages.blocks`.
 *
 * NO reusa `HeadingFieldset::make(hasSlug: true)`: ese modo está acoplado a
 * `Page`/`PageTypeEnum` (valida unicidad de slug contra la tabla `pages`,
 * chequea tipos Header/Footer, etc. — ver `HeadingFieldset::validSlug()`).
 * En su lugar, `HeadingFieldset::make()` sin slug + `afterTitleUpdated` para
 * autogenerar el slug, y un `TextInput::make('slug')` propio con unicidad
 * contra `Service::class`.
 */
class ServiceResource extends Resource
{
    protected static ?string $model = Service::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-briefcase';

    protected static ?string $navigationLabel = 'Servicios';

    protected static ?string $pluralLabel = 'Servicios';

    protected static ?string $modelLabel = 'Servicio';

    protected static ?string $slug = 'services';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Service Details')
                    ->tabs([
                        Tabs\Tab::make('Configuración')
                            ->schema([
                                HeadingFieldset::make(
                                    required: true,
                                    afterTitleUpdated: fn ($state, $set) => $set('slug', Str::slug($state ?? '')),
                                ),

                                Grid::make(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('slug')
                                            ->label('URL (Slug)')
                                            ->required()
                                            ->maxLength(255)
                                            ->unique(Service::class, 'slug', ignoreRecord: true),

                                        Forms\Components\Hidden::make('lang_iso')
                                            ->default('es'),

                                        Forms\Components\Select::make('status')
                                            ->label('Estado')
                                            ->required()
                                            ->options(PublishStatusEnum::class)
                                            ->default(PublishStatusEnum::Draft->value),

                                        Forms\Components\DateTimePicker::make('published_at')
                                            ->label('Fecha de publicación')
                                            ->nullable(),

                                        Forms\Components\TextInput::make('sort_order')
                                            ->label('Orden')
                                            ->helperText('Orden de la card dentro del catálogo de Servicios. Menor = primero.')
                                            ->numeric()
                                            ->default(0)
                                            ->required(),
                                    ]),

                                Grid::make(2)
                                    ->schema([
                                        MediaUpload::make('image_id', 'Imagen (catálogo y detalle)'),

                                        // 2026-08-31 (ADR-035): opcional a pedido del Tech Lead — muy
                                        // pocos servicios son internacionales, no tiene sentido forzar
                                        // la elección de un país. `CountryEnum` ahora cubre el listado
                                        // ISO 3166-1 completo (249 países) — `->searchable()` es
                                        // obligatorio acá, sin eso el listado es inmanejable.
                                        //
                                        // `afterStateHydrated()`/`dehydrateStateUsing()` sanean el
                                        // valor (mayúscula + descarta códigos que no existan hoy en
                                        // `CountryEnum`) al cargar Y al guardar. Fix real (2026-08-31):
                                        // una fila con un código viejo/inválido guardado (ej. de antes
                                        // de ADR-035) tira abajo el `Select` con un `TypeError` en
                                        // Filament (`CanDisableOptions::isOptionDisabled()` no tolera
                                        // un valor seleccionado que no está entre las `options()`
                                        // actuales) — sin esto, con solo abrir el form ya rompe.
                                        Forms\Components\Select::make('countries')
                                            ->label('Países (opcional)')
                                            ->helperText('Se muestran como banderas en la card del catálogo. "Regional / Global" se muestra con un ícono de globo. Dejar vacío si el país no aplica.')
                                            ->options(CountryEnum::class)
                                            ->multiple()
                                            ->searchable()
                                            ->afterStateHydrated(fn (Forms\Components\Select $component, $state) => $component->state(Service::sanitizeCountries($state)))
                                            ->dehydrateStateUsing(fn ($state) => Service::sanitizeCountries($state)),
                                    ]),
                            ]),

                        Tabs\Tab::make('Contenido')
                            ->schema([
                                Forms\Components\Textarea::make('content.intro')
                                    ->label('Párrafo introductorio')
                                    ->helperText('El texto que va debajo del banner, antes de las tabs "¿Qué ofrecemos?"/"Coberturas".')
                                    ->rows(4)
                                    ->required()
                                    ->columnSpanFull(),

                                Section::make('¿Qué ofrecemos?')
                                    ->description('Lista de checks con un texto en negrita al inicio (ej. "Optimización de costos en tus pólizas actuales").')
                                    ->schema([
                                        Forms\Components\Repeater::make('content.offers')
                                            ->label('Puntos')
                                            ->schema([
                                                Forms\Components\TextInput::make('highlight')
                                                    ->label('Texto destacado (negrita)')
                                                    ->required()
                                                    ->maxLength(255),

                                                Forms\Components\TextInput::make('text')
                                                    ->label('Resto del texto')
                                                    ->required()
                                                    ->maxLength(255),
                                            ])
                                            ->columns(2)
                                            ->itemLabel(fn (array $state): ?string => $state['highlight'] ?? null)
                                            ->defaultItems(1)
                                            ->collapsible()
                                            ->collapsed()
                                            ->reorderableWithButtons(),
                                    ])
                                    ->collapsible(),

                                Section::make('Coberturas')
                                    ->description('Acordeón de coberturas. "Detalle" es opcional — si se completa, se muestra como sub-lista al expandir (ej. "Automotores" → "Cubrimos daños por: Robo o hurto, Incendio, ...").')
                                    ->schema([
                                        Forms\Components\Repeater::make('content.coverages')
                                            ->label('Ítems')
                                            ->schema([
                                                Forms\Components\TextInput::make('label')
                                                    ->label('Título')
                                                    ->required()
                                                    ->maxLength(255),

                                                Forms\Components\TextInput::make('intro')
                                                    ->label('Frase antes del detalle (Opcional)')
                                                    ->helperText('Ej.: "Cubrimos daños por:"')
                                                    ->maxLength(255),

                                                Forms\Components\TagsInput::make('items')
                                                    ->label('Detalle (Opcional)')
                                                    ->helperText('Cada tag es un ítem de la sub-lista. Enter para agregar.')
                                                    ->columnSpanFull(),
                                            ])
                                            ->columns(2)
                                            ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                                            ->defaultItems(1)
                                            ->collapsible()
                                            ->collapsed()
                                            ->reorderableWithButtons(),
                                    ])
                                    ->collapsible(),

                                Section::make('¿Por qué elegirnos?')
                                    ->schema([
                                        Grid::make(1)
                                            ->schema([
                                                Forms\Components\TextInput::make('content.why_choose_us.title')
                                                    ->label('Título')
                                                    ->default('¿Por qué elegirnos?')
                                                    ->maxLength(255),

                                                Forms\Components\Textarea::make('content.why_choose_us.text')
                                                    ->label('Texto')
                                                    ->rows(3),
                                            ]),
                                    ])
                                    ->collapsible(),

                                Section::make('Tip de ayuda')
                                    ->description('El bloque destacado con ícono de foco al final (ej. "Soluciones reales, ajustadas a tu rubro, sin letra chica").')
                                    ->schema([
                                        Grid::make(1)
                                            ->schema([
                                                Forms\Components\TextInput::make('content.tip.title')
                                                    ->label('Título')
                                                    ->maxLength(255),

                                                Forms\Components\Textarea::make('content.tip.text')
                                                    ->label('Texto')
                                                    ->rows(2),
                                            ]),
                                    ])
                                    ->collapsible(),
                            ]),

                        Tabs\Tab::make('SEO / Enlaces')
                            ->schema([
                                Section::make('Metadata SEO')
                                    ->description('Título, palabras clave y descripción que Google muestra en los resultados de búsqueda.')
                                    ->collapsed()
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                Forms\Components\TextInput::make('meta.seo_title')
                                                    ->label('Título SEO')
                                                    ->maxLength(255),

                                                Forms\Components\TextInput::make('meta.seo_keywords')
                                                    ->label('Palabras Clave (Separadas por comas)')
                                                    ->maxLength(255),

                                                Forms\Components\Textarea::make('meta.seo_description')
                                                    ->label('Descripción SEO')
                                                    ->maxLength(500)
                                                    ->columnSpanFull(),
                                            ]),
                                    ]),

                                Section::make('Open Graph (Redes Sociales)')
                                    ->description('Título, descripción e imágenes con las que se ve el servicio al compartirlo en redes sociales o chats.')
                                    ->collapsed()
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                Forms\Components\TextInput::make('meta.og_title')
                                                    ->label('Título OG')
                                                    ->maxLength(255),

                                                Forms\Components\Textarea::make('meta.og_description')
                                                    ->label('Descripción OG')
                                                    ->maxLength(500),

                                                MediaUpload::make('meta.og_image_rect_id', 'Imagen OG Rectangular (1200x630)'),
                                                MediaUpload::make('meta.og_image_square_id', 'Imagen OG Cuadrada (600x600)'),
                                            ]),
                                    ]),

                                Section::make('Enlaces relacionados')
                                    ->description('Opcional — enlaces extra del servicio (ej. descargar un brochure). La navegación principal del catálogo hacia el detalle usa el slug, no necesita configurarse acá.')
                                    ->collapsed()
                                    ->schema([
                                        LinkSchema::make('links', 'Enlaces'),
                                    ]),

                                Section::make('Personalización de estilos')
                                    ->description('Color de fondo (sólido o degradado), color de texto y animación de entrada del servicio.')
                                    ->collapsed()
                                    ->schema([
                                        PropertiesSchema::make(['background_type', 'background_color', 'background_color_secondary', 'gradient_direction', 'text_color', 'animation']),
                                    ]),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image.path')
                    ->label('')
                    ->disk(fn ($record) => $record?->image?->disk?->value ?? 'public')
                    ->square(),

                // Título+subtítulo fusionados en 1 columna, 2 filas
                // (2026-08-31, UX: 2 columnas separadas quedaba angosto y
                // repetitivo) — `->description()` es el patrón nativo de
                // Filament para esto: título arriba en negrita, subtítulo
                // debajo en gris más chico, sin abrir una columna aparte.
                Tables\Columns\TextColumn::make('title')
                    ->label('Título')
                    ->searchable(['title', 'subtitle'])
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Service $record): ?string => $record->subtitle),

                // `->badge()` sobre una columna de estado array (2026-08-31,
                // fix real: `formatStateUsing` NO recibe el array completo
                // acá — con `->badge()` activo, Filament itera el array y
                // llama la closure una vez POR CADA valor individual para
                // pintar un badge por país. El primer intento tipaba
                // `?array $state` asumiendo el array entero y rompía con
                // `TypeError` (recibía cada código como `string` suelto).
                Tables\Columns\TextColumn::make('countries')
                    ->label('Países')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => CountryEnum::tryFrom($state ?? '')?->getLabel() ?? $state),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn ($state) => match ($state?->value) {
                        'published' => 'success',
                        'draft' => 'warning',
                        'scheduled' => 'info',
                        'archived' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options(PublishStatusEnum::class),

                Tables\Filters\SelectFilter::make('countries')
                    ->label('País')
                    ->options(CountryEnum::class)
                    ->searchable()
                    ->query(function (Builder $query, array $data) {
                        $value = $data['value'] ?? null;

                        if (! $value) {
                            return $query;
                        }

                        return $query->whereJsonContains('countries', $value);
                    }),
            ])
            ->actions([
                Actions\EditAction::make()
                    ->slideOver()
                    ->modalWidth('3xl'),
                Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),

                    Actions\BulkAction::make('publish')
                        ->label('Marcar como publicados')
                        ->icon('heroicon-o-check-circle')
                        ->action(fn ($records) => $records->each->update(['status' => PublishStatusEnum::Published->value]))
                        ->deselectRecordsAfterCompletion(),

                    Actions\BulkAction::make('draft')
                        ->label('Marcar como borrador')
                        ->icon('heroicon-o-pencil')
                        ->action(fn ($records) => $records->each->update(['status' => PublishStatusEnum::Draft->value]))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No hay servicios cargados')
            ->emptyStateDescription('Los servicios que crees acá arman el catálogo público de "Servicios" y su propia página de detalle.')
            ->emptyStateActions([
                Actions\CreateAction::make()
                    ->slideOver()
                    ->modalWidth('3xl'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageServices::route('/'),
        ];
    }
}
