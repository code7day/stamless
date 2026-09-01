<?php

namespace App\Filament\Resources;

use App\Enums\PageTypeEnum;
use App\Enums\PublishStatusEnum;
use App\Filament\Resources\PageResource\Pages;
use App\Filament\Schemas\HeadingFieldset;
use App\Filament\Schemas\LinkSchema;
use App\Filament\Schemas\MediaUpload;
use App\Filament\Schemas\PropertiesSchema;
use App\Models\Block;
use App\Models\Form;
use App\Models\Page;
use App\Models\Slider;
use App\Support\FriendlyDate;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Components\Builder;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Log;

class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Contenidos';

    protected static ?string $pluralLabel = 'Contenidos';

    protected static ?string $modelLabel = 'Contenido';

    protected static ?string $slug = 'pages';

    /**
     * Primer bug de esta familia, confirmado el 2026-09-01 (reporte del Tech
     * Lead: "guardo sin cambiar nada y se borra/daña el jsonb"): el `$state`
     * que `saveRelationshipsUsing()` recibe para cada bloque del Builder es
     * el estado INTERNO CRUDO de sus campos, no el valor ya "limpio" que
     * Filament normalmente entrega. Para un `MediaUpload` (FileUpload en
     * modo single) eso significa que en vez de guardar el id escalar real
     * (ej. `"4"`), se guarda su forma cruda: un array de un solo elemento
     * keyeado por el UUID interno que usa el widget para identificar el
     * archivo (ej. `["537a6e80-...-...": "4"]`) — confirmado leyendo el
     * log real de un guardado (`saveRelationshipsUsing — content.media_id
     * recibido para bloque`, media_id_type: "array"). Ese array corrupto
     * se guarda tal cual en el jsonb; el resolver público
     * (`ResolvesPublicLinks`) no puede resolverlo como id de media (no es
     * escalar) y la imagen desaparece del sitio — aunque en Studio el
     * widget la siga mostrando bien, porque para el propio FileUpload esa
     * forma sigue siendo un archivo "válido".
     *
     * Se detecta por firma exacta (array de 1 elemento, key con forma de
     * UUID) para no tocar por error objetos legítimos de una sola
     * propiedad (ej. `properties: {"background_color": "#fff"}`), y se
     * aplica recursivamente para cubrir también los MediaUpload anidados
     * dentro de repeaters (logos, features, services_grid, etc.).
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>|mixed
     */
    private static function unwrapFileUploadState(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (count($value) === 1) {
            $key = array_key_first($value);

            if (
                is_string($key)
                && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key)
                && is_scalar($value[$key])
            ) {
                return $value[$key];
            }
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::unwrapFileUploadState($item);
            }
        }

        return $value;
    }

    /**
     * Segundo bug de la misma familia (2026-09-01, confirmado con inspector
     * del navegador por el Tech Lead): los `Forms\Components\Slider` de
     * Filament (opacidad/brillo/saturación/contraste de `PropertiesSchema` y
     * del bloque `heading`) también tienen su propio state cast
     * (`SliderStateCast::get()`, `floatval($state)`). Cuando una propiedad
     * nunca se guardó explícitamente (el seeder no la escribe — solo pisa
     * `pretitle`/`title`/`media_id`/`body`, no cada slider de estilo), el
     * valor crudo que trae `$block->properties` es simplemente inexistente
     * (`null` al leerlo), y `floatval(null)` da `0.0` — sin importar que el
     * slider esté configurado con `->default(100)` en `PropertiesSchema`.
     * Ese `->default()` de Filament solo se aplica al crear un registro
     * nuevo desde cero, no al hidratar datos parciales vía
     * `loadStateFromRelationshipsUsing`. Resultado confirmado con el
     * inspector: `filter: brightness(0%) saturate(0%) ...; opacity: 0` en
     * vez de `brightness(100%) saturate(100%) ...; opacity: 1` — la imagen
     * se volvía invisible, no faltaba el archivo (a diferencia del bug de
     * `MediaUpload` de más arriba, esto no tiene nada que ver con el id de
     * media). Mapa exhaustivo de cada `Slider::make('properties.X')` de la
     * app con su default real configurado (grep completo, ver
     * `PropertiesSchema.php` + los 4 sliders propios del bloque `heading`
     * en este archivo) — se usa para rellenar cualquier key ausente ANTES
     * de que el widget la vea, tanto al cargar como red de seguridad al
     * guardar.
     *
     * @var array<string, int>
     */
    private const SLIDER_PROPERTY_DEFAULTS = [
        'item_background_opacity' => 30,
        'overlay_opacity' => 0,
        'media_brightness' => 100,
        'media_opacity' => 100,
        'media_filter_saturate' => 100,
        'media_filter_grayscale' => 0,
        'media_filter_sepia' => 0,
        'media_filter_contrast' => 100,
        'media_filter_hue_rotate' => 0,
        'media_filter_blur' => 0,
        'decorator_top_opacity' => 100,
        'decorator_bottom_opacity' => 100,
        'slide_background_brightness' => 100,
        'slide_background_opacity' => 100,
        'slide_background_filter_saturate' => 100,
        'slide_background_filter_grayscale' => 0,
        'slide_background_filter_sepia' => 0,
        'slide_background_filter_contrast' => 100,
        'slide_background_filter_hue_rotate' => 0,
        'slide_background_filter_blur' => 0,
        // Propios del bloque `heading` (nombres cortos, sin prefijo `media_`
        // ni `slide_background_`, ver el `Section` de personalización más
        // arriba en este mismo archivo).
        'contrast' => 100,
        'brightness' => 100,
        'transparency' => 0,
    ];

    /**
     * Rellena con su default real (`SLIDER_PROPERTY_DEFAULTS`) cualquier
     * propiedad de tipo Slider que esté ausente en `$properties` — sin pisar
     * nunca un valor ya presente (incluido un `0` puesto a propósito por el
     * Tech Lead). No toca ninguna otra key (`background_color`,
     * `text_align`, etc.) — esas no usan `Slider`, no sufren este bug.
     *
     * @param  array<array-key, mixed>|null  $properties
     * @return array<array-key, mixed>
     */
    private static function backfillSliderDefaults(?array $properties): array
    {
        return array_merge(self::SLIDER_PROPERTY_DEFAULTS, $properties ?? []);
    }

    public static function form(Schema $schema): Schema
    {
        // Único enlace "Ver más" del bloque Texto Enriquecido (2026-08-31) —
        // mismo patrón que el CTA único de SliderResource: LinkSchema::makeSingle()
        // en vez de LinkSchema::make() (Repeater), a pedido explícito del Tech
        // Lead ("no se necesita tener multiples enlaces"). Se computa una vez y
        // se reutiliza en las dos columnas del bloque (campos principales /
        // propiedades del enlace).
        $richTextLinkFields = LinkSchema::makeSingle('links');

        // `link_radius`/`link_size` (2026-08-31, reubicados a pedido del Tech
        // Lead): antes vivían sueltos arriba de la Section "Enlace 'Ver
        // más'", visibles aunque "Mostrar enlace" estuviera apagado — se
        // veían huérfanos, sin nada que estilizar todavía. Se insertan
        // DENTRO del grid principal del link, justo después de "Estilo del
        // botón": son parte del ESTILO del botón, no de sus atributos
        // técnicos (destino de apertura/SEO/clase/id), por eso no van al
        // Fieldset "Propiedades del enlace". Splice local, solo para este
        // bloque — `LinkSchema::makeSingle()` sigue genérico y sin tocar
        // (Slides usa el mismo helper sin estos 2 campos).
        $richTextLinkMainFields = $richTextLinkFields['main'];
        array_splice($richTextLinkMainFields, 1, 0, PropertiesSchema::makeComponents(['link_radius', 'link_size']));

        // Único enlace opcional del bloque Testimonios (2026-08-31, mismo
        // patrón que `rich_text`: "ver más casos de éxito" es un botón,
        // nunca una lista). Instancia propia — `LinkSchema::makeSingle()`
        // no comparte estado entre llamadas, cada bloque arma su propio
        // set de componentes sobre el mismo campo `links` del Block.
        $testimonialsLinkFields = LinkSchema::makeSingle('links');

        return $schema
            ->components([
                Tabs::make('Page Details')
                    ->tabs([
                        Tabs\Tab::make('Configuración')
                            ->schema([
                                HeadingFieldset::make(
                                    required: true,
                                    hasSlug: true,
                                    hasIsHome: true
                                ),

                                // Árbol de páginas hasta 3 niveles (2026-08-31,
                                // pedido del Tech Lead) — solo ORGANIZACIÓN
                                // interna en Studio, confirmado con el Tech
                                // Lead: no cambia la URL pública de la página
                                // (sigue siendo su `slug` plano). `options()`
                                // excluye: la página misma (al editar), sus
                                // propios descendientes (evita ciclos), y
                                // cualquier página que ya esté en el nivel 2
                                // (elegirla como padre crearía un 4to nivel,
                                // por encima del máximo pedido).
                                Grid::make(3)
                                    ->schema([
                                        Forms\Components\Hidden::make('type')
                                            ->default(PageTypeEnum::Page->value),

                                        Forms\Components\Hidden::make('lang_iso')
                                            ->default('es'),

                                        // Árbol de páginas hasta 3 niveles (2026-08-31,
                                        // pedido del Tech Lead) — solo ORGANIZACIÓN
                                        // interna en Studio, confirmado con el Tech
                                        // Lead: no cambia la URL pública de la página
                                        // (sigue siendo su `slug` plano). `options()`
                                        // excluye: la página misma (al editar), sus
                                        // propios descendientes (evita ciclos), y
                                        // cualquier página que ya esté en el nivel 2
                                        // (elegirla como padre crearía un 4to nivel,
                                        // por encima del máximo pedido).
                                        Forms\Components\Select::make('parent_id')
                                            ->label('Página superior (opcional)')
                                            ->helperText('Hasta 3 niveles. No cambia la URL pública.')
                                            ->options(function (?Page $record) {
                                                $descendantIds = $record ? static::descendantPageIds($record) : [];

                                                return Page::query()
                                                    ->when($record, fn ($query) => $query->whereKeyNot($record->id))
                                                    ->get()
                                                    ->reject(fn (Page $page) => in_array($page->id, $descendantIds, true))
                                                    ->reject(fn (Page $page) => $page->depth() >= 2)
                                                    ->pluck('title', 'id');
                                            })
                                            ->searchable()
                                            ->nullable(),

                                        Forms\Components\Select::make('status')
                                            ->label('Estado')
                                            ->required()
                                            ->options(PublishStatusEnum::class)
                                            ->default(PublishStatusEnum::Draft->value),

                                        Forms\Components\DateTimePicker::make('published_at')
                                            ->label('Fecha de publicación')
                                            ->nullable(),
                                    ]),
                            ]),

                        Tabs\Tab::make('Contenidos')
                            ->schema([
                                
                                Builder::make('blocks')
                                            ->label('Secciones')
                                            ->addActionLabel('Añadir bloque')
                                            ->blocks([
                                                // HEADING Block
                                                Builder\Block::make('heading')
                                                    ->label('Heading (Sección de Títulos)')
                                                    ->icon('heroicon-o-bars-3-bottom-left')
                                                    ->schema([
                                                        Grid::make(2)
                                                            ->schema([
                                                                Forms\Components\Hidden::make('lang_iso')
                                                                    ->default('es'),

                                                                Forms\Components\Toggle::make('is_visible')
                                                                    ->label('Visible')
                                                                    ->helperText('Oculta el bloque en el sitio público sin borrarlo del editor.')
                                                                    ->default(true)
                                                                    ->required(),
                                                            ]),

                                                        HeadingFieldset::make(),

                                                        Section::make('Imágenes del Encabezado')
                                                            ->description('Selecciona las imágenes responsivas')
                                                            ->schema([
                                                                Grid::make(3)
                                                                    ->schema([
                                                                        MediaUpload::make('content.image_desktop_id', 'Imagen Desktop'),
                                                                        MediaUpload::make('content.image_tablet_id', 'Imagen Tablet'),
                                                                        MediaUpload::make('content.image_mobile_id', 'Imagen Móvil'),
                                                                    ]),
                                                            ]),

                                                        Section::make('Propiedades Visuales')
                                                            ->schema([
                                                                Grid::make(3)
                                                                    ->schema([
                                                                        Forms\Components\Select::make('properties.decorator_top')
                                                                            ->label('Decorador Superior')
                                                                            ->options([
                                                                                'none' => 'Ninguno',
                                                                                'curve' => 'Curva',
                                                                                'waves' => 'Ondas',
                                                                                'triangle' => 'Triangular',
                                                                                'diagonal' => 'Diagonal',
                                                                            ])
                                                                            ->default('none')
                                                                            ->live(),

                                                                        Forms\Components\ColorPicker::make('properties.decorator_top_color')
                                                                            ->label('Color de decorador superior')
                                                                            ->visible(fn (Get $get) => filled($get('properties.decorator_top')) && $get('properties.decorator_top') !== 'none'),

                                                                        Forms\Components\Select::make('properties.decorator_bottom')
                                                                            ->label('Decorador Inferior')
                                                                            ->options([
                                                                                'none' => 'Ninguno',
                                                                                'curve' => 'Curva',
                                                                                'waves' => 'Ondas',
                                                                                'triangle' => 'Triangular',
                                                                                'diagonal' => 'Diagonal',
                                                                            ])
                                                                            ->default('none')
                                                                            ->live(),

                                                                        Forms\Components\ColorPicker::make('properties.decorator_bottom_color')
                                                                            ->label('Color de decorador inferior')
                                                                            ->visible(fn (Get $get) => filled($get('properties.decorator_bottom')) && $get('properties.decorator_bottom') !== 'none'),

                                                                        Forms\Components\ColorPicker::make('properties.background_color')
                                                                            ->label('Color de fondo'),

                                                                        Forms\Components\ColorPicker::make('properties.overlay_color')
                                                                            ->label('Color del overlay / filtro'),

                                                                        Forms\Components\Slider::make('properties.overlay_opacity')
                                                                            ->label('Opacidad del overlay (0-100)')
                                                                            ->minValue(0)
                                                                            ->maxValue(100)
                                                                            ->step(5)
                                                                            ->default(0)
                                                                            ->decimalPlaces(0)
                                                                            ->fillTrack()
                                                                            ->tooltips(),

                                                                        Forms\Components\Slider::make('properties.contrast')
                                                                            ->label('Contraste')
                                                                            ->minValue(0)
                                                                            ->maxValue(200)
                                                                            ->step(10)
                                                                            ->default(100)
                                                                            ->decimalPlaces(0)
                                                                            ->fillTrack()
                                                                            ->tooltips(),

                                                                        Forms\Components\Slider::make('properties.brightness')
                                                                            ->label('Brillo')
                                                                            ->minValue(0)
                                                                            ->maxValue(200)
                                                                            ->step(10)
                                                                            ->default(100)
                                                                            ->decimalPlaces(0)
                                                                            ->fillTrack()
                                                                            ->tooltips(),

                                                                        Forms\Components\Slider::make('properties.transparency')
                                                                            ->label('Transparencia (0-100)')
                                                                            ->minValue(0)
                                                                            ->maxValue(100)
                                                                            ->step(5)
                                                                            ->default(0)
                                                                            ->decimalPlaces(0)
                                                                            ->fillTrack()
                                                                            ->tooltips(),

                                                                        Forms\Components\Select::make('properties.title_alignment')
                                                                            ->label('Alineación de títulos')
                                                                            ->options([
                                                                                'left' => 'Izquierda',
                                                                                'center' => 'Centro',
                                                                                'right' => 'Derecha',
                                                                            ])
                                                                            ->default('left'),
                                                                    ]),
                                                            ]),
                                                    ]),

                                                // 1. HERO Block
                                                Builder\Block::make('hero')
                                                    ->label('Hero (Cabecera)')
                                                    ->icon('heroicon-o-presentation-chart-bar')
                                                    ->schema([
                                                        Grid::make(2)
                                                            ->schema([
                                                            Forms\Components\Select::make('content.mode')
                                                                ->label('Modo del Hero')
                                                                ->required()
                                                                ->options([
                                                                    'slider' => 'Slider (Carrusel existente)',
                                                                    'manual' => 'Manual (Título + Imagen de fondo)',
                                                                ])
                                                                ->default('slider')
                                                                ->live(),

                                                            // If Slider
                                                            Group::make()
                                                                ->schema([
                                                                    Forms\Components\Select::make('content.slider_id')
                                                                        ->label('Seleccionar Slider')
                                                                        ->options(fn () => Slider::pluck('title', 'id'))
                                                                        ->searchable()
                                                                        ->required(fn (Get $get) => $get('content.mode') === 'slider'),
                                                                ])
                                                                ->visible(fn (Get $get) => $get('content.mode') === 'slider'),
                                                        ]),

                                                        // If Manual — Encabezado + CTA + PropertiesSchema (fondo/
                                                        // color/alineación/padding/overlay/animación/flecha de
                                                        // scroll) agrupados bajo esta única Section, un solo
                                                        // `->visible()` para todo el grupo. `HeadingFieldset`
                                                        // (pretitle/título/subtítulo del Block) también se movió
                                                        // acá adentro: en modo Slider es un campo muerto — cada
                                                        // Slide tiene su PROPIO pretitle/título/subtítulo, el
                                                        // front nunca lee `block.pretitle/title/subtitle` salvo
                                                        // en el fallback manual (ver `Hero.astro`, `manualSlide`).
                                                        // Detalle y motivo en ADR-031 (DECISIONS.md).
                                                        Section::make('Configuración Manual')
                                                            ->description('Solo aplica cuando el Hero usa una imagen de fondo directa. En modo Slider, el contenido y la personalización visual (encabezado, fondo, decoradores, alineación, flecha de scroll) viven en el Slider elegido arriba — ver Studio → Sliders.')
                                                            ->schema([
                                                                HeadingFieldset::make(),

                                                                Section::make('Imágenes de fondo responsivas')
                                                                    ->description('Sube o selecciona imágenes de fondo para diferentes dispositivos')
                                                                    ->schema([
                                                                        Grid::make(3)
                                                                            ->schema([
                                                                                MediaUpload::make('content.background_image_id', 'Imagen Desktop')
                                                                                    ->required(fn (Get $get) => $get('content.mode') === 'manual'),
                                                                                MediaUpload::make('content.background_image_tablet_id', 'Imagen Tablet'),
                                                                                MediaUpload::make('content.background_image_mobile_id', 'Imagen Móvil'),
                                                                            ]),
                                                                    ]),

                                                                LinkSchema::make('links', 'Botón de acción (CTA)'),

                                                                Section::make('Diseño de la sección')
                                                                    ->description('Fondo, alineación, espaciado, overlay y flecha de scroll — mismo sistema visual que el resto de los bloques.')
                                                                    ->schema([
                                                                        Grid::make(2)
                                                                            ->schema(PropertiesSchema::makeComponents(['background_color', 'text_color'])),
                                                                        Grid::make(2)
                                                                            ->schema(PropertiesSchema::makeComponents(['text_align', 'padding_y'])),
                                                                        Grid::make(2)
                                                                            ->schema(PropertiesSchema::makeComponents(['overlay_opacity', 'animation'])),
                                                                        Grid::make(1)
                                                                            ->schema(PropertiesSchema::makeComponents(['show_scroll_indicator'])),
                                                                    ])
                                                                    ->collapsible(),
                                                            ])
                                                            ->visible(fn (Get $get) => $get('content.mode') === 'manual'),
                                                    ]),

                                                // 2. RICH TEXT Block (2026-08-31, reesquematizado a pedido del
                                                // Tech Lead: fondo/padding/alineación/decoradores/ancho ya
                                                // existían como campos reusables de `PropertiesSchema` — se
                                                // suman acá los que faltaban (`show_scroll_indicator`,
                                                // `decorator_top`/`_color`, `decorator_top_opacity` para
                                                // simetría con el inferior que ya tenía opacidad) y se agrupa
                                                // todo en 2 secciones colapsables para que no sea una lista
                                                // plana larga. El enlace "Ver más" es NUEVO: un solo botón
                                                // opcional (no un Repeater — "no se necesita tener multiples
                                                // enlaces"), con un toggle propio (`properties.show_link`)
                                                // para poder ocultar el botón sin perder lo ya cargado.
                                                Builder\Block::make('rich_text')
                                                    ->label('Texto Enriquecido')
                                                    ->icon('heroicon-o-document-text')
                                                    ->schema([
                                                        HeadingFieldset::make(),

                                                        Forms\Components\RichEditor::make('content.body')
                                                            ->label('Cuerpo del texto')
                                                            ->required(),

                                                        Section::make('Enlace "Ver más" (opcional)')
                                                            ->description('Un solo botón opcional debajo del texto — hacia una página interna, una entrada del blog o una URL externa. Para más de un enlace, usar el bloque CTA.')
                                                            ->schema([
                                                                Grid::make(1)
                                                                    ->schema(PropertiesSchema::makeComponents(['show_link'])),

                                                                Grid::make(3)
                                                                    ->schema($richTextLinkMainFields)
                                                                    ->visible(fn (Get $get) => (bool) $get('properties.show_link')),

                                                                Fieldset::make('Propiedades del enlace')
                                                                    ->schema($richTextLinkFields['properties'])
                                                                    ->columns(2)
                                                                    ->visible(fn (Get $get) => (bool) $get('properties.show_link')),
                                                            ])
                                                            ->collapsible()
                                                            ->collapsed(),

                                                        Section::make('Diseño de la sección')
                                                            ->description('Fondo, espaciado, ancho de contenido y decoradores — mismo sistema visual del resto de los bloques.')
                                                            ->schema([
                                                                Grid::make(2)
                                                                    ->schema(PropertiesSchema::makeComponents([
                                                                        'background_color', 'text_color',
                                                                        'text_align', 'content_width', 'padding_y',
                                                                        'show_scroll_indicator',
                                                                    ])),

                                                                Grid::make(3)
                                                                    ->schema(PropertiesSchema::makeComponents([
                                                                        'decorator_top', 'decorator_top_color', 'decorator_top_opacity',
                                                                    ])),

                                                                Grid::make(3)
                                                                    ->schema(PropertiesSchema::makeComponents([
                                                                        'decorator_bottom', 'decorator_bottom_color', 'decorator_bottom_opacity',
                                                                    ])),
                                                            ])
                                                            ->collapsible()
                                                            ->collapsed(),
                                                    ]),

                                                // 3. IMAGE Block
                                                Builder\Block::make('image')
                                                    ->label('Imagen única')
                                                    ->icon('heroicon-o-photo')
                                                    ->schema([
                                                        HeadingFieldset::make(),

                                                        MediaUpload::make('content.media_id', 'Seleccionar Imagen')
                                                            ->required(),

                                                        Forms\Components\TextInput::make('content.caption')
                                                            ->label('Descripción (Caption)'),

                                                        Forms\Components\Select::make('content.aspect')
                                                            ->label('Relación de aspecto')
                                                            ->options([
                                                                'auto' => 'Automático',
                                                                '16:9' => '16:9 Horizontal',
                                                                '4:3' => '4:3 Estándar',
                                                                '1:1' => '1:1 Cuadrado',
                                                            ])
                                                            ->default('auto'),

                                                        Forms\Components\Select::make('content.align')
                                                            ->label('Alineación')
                                                            ->options([
                                                                'left' => 'Izquierda',
                                                                'center' => 'Centro',
                                                                'right' => 'Derecha',
                                                            ])
                                                            ->default('center'),

                                                        PropertiesSchema::make(['background_color', 'padding_y', 'animation']),
                                                    ]),

                                                // 4. CTA Block
                                                Builder\Block::make('cta')
                                                    ->label('Llamado a la Acción (CTA)')
                                                    ->icon('heroicon-o-megaphone')
                                                    ->schema([
                                                        HeadingFieldset::make(),

                                                        Forms\Components\Textarea::make('content.body')
                                                            ->label('Cuerpo del mensaje')
                                                            ->required(),

                                                        LinkSchema::make('links', 'Botones de acción'),
                                                        PropertiesSchema::make(['background_color', 'text_color', 'overlay_opacity', 'text_align', 'padding_y']),
                                                    ]),

                                                // 5. FEATURES Block
                                                Builder\Block::make('features')
                                                    ->label('Características / Grid')
                                                    ->icon('heroicon-o-squares-2x2')
                                                    ->schema([
                                                        HeadingFieldset::make(),

                                                        Section::make('Elementos (Items)')
                                                            ->schema([
                                                                Forms\Components\Repeater::make('content.items')
                                                                    ->label('Características')
                                                                    ->schema([
                                                                        Forms\Components\TextInput::make('icon')
                                                                            ->label('Icono (ej: heroicon-o-check)'),
                                                                        Forms\Components\TextInput::make('title')
                                                                            ->label('Título/Nombre')
                                                                            ->required(),
                                                                        Forms\Components\Textarea::make('description')
                                                                            ->label('Descripción'),
                                                                        MediaUpload::make('image_id', 'Imagen (Opcional)'),
                                                                    ])
                                                                    ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                                                                    ->defaultItems(1)
                                                                    ->collapsible()
                                                                    ->collapsed(),
                                                            ]),

                                                        PropertiesSchema::make(['background_color', 'text_color', 'padding_y', 'animation']),
                                                    ]),

                                                // 6. FAQ Block
                                                Builder\Block::make('faq')
                                                    ->label('Preguntas Frecuentes (FAQ)')
                                                    ->icon('heroicon-o-question-mark-circle')
                                                    ->schema([
                                                        HeadingFieldset::make(),

                                                        Section::make('Lista de Preguntas y Respuestas')
                                                            ->schema([
                                                                Forms\Components\Repeater::make('content.items')
                                                                    ->label('FAQ Items')
                                                                    ->schema([
                                                                        Forms\Components\TextInput::make('question')
                                                                            ->label('Pregunta')
                                                                            ->required(),
                                                                        Forms\Components\RichEditor::make('answer')
                                                                            ->label('Respuesta')
                                                                            ->required(),
                                                                    ])
                                                                    ->itemLabel(fn (array $state): ?string => $state['question'] ?? null)
                                                                    ->defaultItems(1)
                                                                    ->collapsible()
                                                                    ->collapsed(),
                                                            ]),

                                                        PropertiesSchema::make(['background_color', 'text_color', 'padding_y']),
                                                    ]),

                                                // 7. CONTACT FORM Block
                                                Builder\Block::make('contact_form')
                                                    ->label('Formulario de Contacto')
                                                    ->icon('heroicon-o-envelope')
                                                    ->schema([
                                                        HeadingFieldset::make(),

                                                        Forms\Components\Select::make('content.form_id')
                                                            ->label('Seleccionar Formulario')
                                                            ->options(fn () => Form::pluck('name', 'id'))
                                                            ->searchable()
                                                            ->required(),

                                                        Forms\Components\Textarea::make('content.intro')
                                                            ->label('Texto de introducción (Opcional)'),

                                                        PropertiesSchema::make(['background_color', 'text_color', 'padding_y', 'content_width']),
                                                    ]),

                                                // 8. LEGAL NOTICE Block
                                                Builder\Block::make('legal_notice')
                                                    ->label('Aviso Legal / Contenido largo')
                                                    ->icon('heroicon-o-shield-check')
                                                    ->schema([
                                                        HeadingFieldset::make(),

                                                        Forms\Components\RichEditor::make('content.body')
                                                            ->label('Contenido legal')
                                                            ->required(),

                                                        PropertiesSchema::make(['padding_y', 'content_width']),
                                                    ]),

                                                // 9. SPLIT Block
                                                Builder\Block::make('split')
                                                    ->label('Split Imagen y Texto')
                                                    ->icon('heroicon-o-adjustments-horizontal')
                                                    ->schema([
                                                        Forms\Components\Hidden::make('lang_iso')
                                                            ->default('es'),

                                                        // `is_visible` ya no comparte grid con "Posición de la
                                                        // imagen" (2026-08-31, mismo día): ese campo se movió
                                                        // adentro de "Personalización de estilos" > "Sección" —
                                                        // ver comentario más abajo. Solo, a ancho completo, no
                                                        // deja celda vacía.
                                                        Forms\Components\Toggle::make('is_visible')
                                                            ->label('Visible')
                                                            ->helperText('Oculta el bloque en el sitio público sin borrarlo del editor.')
                                                            ->default(true)
                                                            ->required(),

                                                        Grid::make(2)
                                                            ->schema([
                                                                MediaUpload::make('content.media_id', 'Imagen / Elemento Multimedia')
                                                                    ->required(),

                                                                Group::make()
                                                                ->schema([
                                                                    HeadingFieldset::make(),
                                                                ]),
                                                            ]),

                                                        Forms\Components\RichEditor::make('content.body')
                                                            ->label('Cuerpo del texto')
                                                            ->required(),

                                                        LinkSchema::make('links', 'Enlaces'),

                                                        // Antes era una lista plana de 4 campos apilados debajo
                                                        // de "Enlaces" (2026-08-31, feedback visual del Tech
                                                        // Lead con captura: "podrían estar en una sección a 2
                                                        // columnas, algo más profesional"). Reorganizado en una
                                                        // Section con 2 Fieldsets a 2 columnas cada uno: estilos
                                                        // generales de la sección, y filtros/efectos de la
                                                        // imagen (blend mode, brillo, opacidad, bordes + los 6
                                                        // filtros CSS clásicos — mismo set que ya existía para
                                                        // el fondo del Slide, generalizado bajo `media_*` en
                                                        // `PropertiesSchema` para no atarlo al nombre "slide").
                                                        // `content_width` se sumó acá mismo día (el Tech Lead
                                                        // notó que el bloque se veía integrado a nivel "boxed"
                                                        // en el sitio real y pidió una property para elegir
                                                        // fullwidth — ya existía genérica en `PropertiesSchema`,
                                                        // sin uso en `split` hasta ahora).
                                                        //
                                                        // `content.media_position` SÍ vive acá (movido desde
                                                        // arriba, mismo pedido: "la posición de la imagen
                                                        // también debería pasar a Personalización de estilos >
                                                        // Sección") — es una reubicación de UI únicamente, el
                                                        // campo sigue siendo `content.media_position` (no
                                                        // `properties.media_position`, que fue el duplicado
                                                        // muerto eliminado en ADR-031/ADR-032): sigue siendo
                                                        // required, con default `left` y resuelto por
                                                        // `Split.astro`, solo cambia dónde se renderiza en el
                                                        // formulario.
                                                        Section::make('Personalización de estilos')
                                                            ->collapsed()
                                                            ->schema([
                                                                Fieldset::make('Sección')
                                                                    ->columns(2)
                                                                    ->schema([
                                                                        Forms\Components\Select::make('content.media_position')
                                                                            ->label('Posición de la imagen')
                                                                            ->options([
                                                                                'left' => 'Izquierda',
                                                                                'right' => 'Derecha',
                                                                            ])
                                                                            ->default('left')
                                                                            ->required(),

                                                                        ...PropertiesSchema::makeComponents([
                                                                            'background_color', 'text_color', 'padding_y', 'content_width', 'animation',
                                                                        ]),
                                                                    ]),

                                                                Fieldset::make('Imagen: filtros y efectos')
                                                                    ->columns(2)
                                                                    ->schema(
                                                                        PropertiesSchema::makeComponents([
                                                                            'media_blend_mode', 'media_brightness', 'media_opacity', 'media_radius',
                                                                            'media_filter_saturate', 'media_filter_grayscale', 'media_filter_sepia',
                                                                            'media_filter_contrast', 'media_filter_hue_rotate', 'media_filter_blur',
                                                                        ])
                                                                    ),
                                                            ]),
                                                    ]),

                                                // 10. TESTIMONIALS Block
                                                // 2026-08-31, rediseño completo a pedido del Tech Lead: los
                                                // testimonios dejaron de vivir como `Repeater` inline acá
                                                // (`content.items`) — ahora son su propio módulo gestionable
                                                // (`TestimonialResource`, tabla `testimonials`). Este bloque
                                                // queda reducido a: encabezado (`HeadingFieldset`, sin los
                                                // `title`/`subtitle` duplicados que tenía antes — mismo
                                                // anti-patrón ya corregido en ADR-031/032), un filtro
                                                // (`content.limit`/`content.order`) que se resuelve en runtime
                                                // contra la tabla real (`ResolvesPublicLinks`, respetando
                                                // `is_visible`), un enlace único opcional (mismo patrón que
                                                // `rich_text`: `LinkSchema::makeSingle()` vía
                                                // `$testimonialsLinkFields`) y personalización de estilos.
                                                Builder\Block::make('testimonials')
                                                    ->label('Testimonios')
                                                    ->icon('heroicon-o-chat-bubble-left-right')
                                                    ->schema([
                                                        Forms\Components\Hidden::make('lang_iso')
                                                            ->default('es'),

                                                        Forms\Components\Toggle::make('is_visible')
                                                            ->label('Visible')
                                                            ->helperText('Oculta el bloque en el sitio público sin borrarlo del editor.')
                                                            ->default(true)
                                                            ->required(),

                                                        HeadingFieldset::make(),

                                                        Section::make('Filtro de testimonios')
                                                            ->description('Los testimonios se gestionan en su propio módulo ("Testimonios" en el menú lateral) — acá solo se elige cuáles trae este bloque. Solo se consideran los marcados como visibles en esa tabla.')
                                                            ->schema([
                                                                Grid::make(2)
                                                                    ->schema([
                                                                        Forms\Components\TextInput::make('content.limit')
                                                                            ->label('Cantidad a mostrar')
                                                                            ->helperText('Ej.: los últimos 3, o los primeros 5 según el orden elegido.')
                                                                            ->numeric()
                                                                            ->minValue(1)
                                                                            ->maxValue(50)
                                                                            ->default(3)
                                                                            ->required(),

                                                                        Forms\Components\Select::make('content.order')
                                                                            ->label('Orden')
                                                                            ->options([
                                                                                'desc' => 'Más recientes primero',
                                                                                'asc' => 'Más antiguos primero',
                                                                            ])
                                                                            ->default('desc')
                                                                            ->required(),
                                                                    ]),
                                                            ]),

                                                        Section::make('Enlace "Ver más" (opcional)')
                                                            ->description('Un solo botón opcional debajo de los testimonios — hacia una página interna, una entrada del blog o una URL externa.')
                                                            ->schema([
                                                                Grid::make(1)
                                                                    ->schema(PropertiesSchema::makeComponents(['show_link'])),

                                                                Grid::make(2)
                                                                    ->schema($testimonialsLinkFields['main'])
                                                                    ->visible(fn (Get $get) => (bool) $get('properties.show_link')),

                                                                Fieldset::make('Propiedades del enlace')
                                                                    ->schema($testimonialsLinkFields['properties'])
                                                                    ->columns(2)
                                                                    ->visible(fn (Get $get) => (bool) $get('properties.show_link')),
                                                            ])
                                                            ->collapsible()
                                                            ->collapsed(),

                                                        Section::make('Personalización de estilos')
                                                            ->collapsed()
                                                            ->schema([
                                                                PropertiesSchema::make(['background_color', 'item_background_color', 'item_background_opacity', 'text_color', 'padding_y', 'animation'])
                                                                    ->columns(2),
                                                            ]),
                                                    ]),

                                                // 11. LOGOS Block
                                                Builder\Block::make('logos')
                                                    ->label('Logos / Socios')
                                                    ->icon('heroicon-o-squares-plus')
                                                    ->schema([
                                                        Grid::make(2)
                                                            ->schema([
                                                                Forms\Components\Hidden::make('lang_iso')
                                                                    ->default('es'),

                                                                Forms\Components\Toggle::make('is_visible')
                                                                    ->label('Visible')
                                                                    ->helperText('Oculta el bloque en el sitio público sin borrarlo del editor.')
                                                                    ->default(true)
                                                                    ->required(),
                                                            ]),

                                                        HeadingFieldset::make(),

                                                        Section::make('Galería de Logos')
                                                            ->description('En el sitio se muestran de a 7 por página (7 columnas en desktop). Con más de 7, aparece como carousel — flechas, puntos de página, swipe táctil y drag con mouse.')
                                                            ->schema([
                                                                Forms\Components\Repeater::make('content.items')
                                                                    ->label('Logos')
                                                                    ->schema([
                                                                        MediaUpload::make('media_id', 'Logo')
                                                                            ->required(),
                                                                        Forms\Components\TextInput::make('alt')
                                                                            ->label('Texto alternativo (Alt)'),
                                                                        Forms\Components\TextInput::make('url')
                                                                            ->label('URL de destino (Opcional)')
                                                                            ->url(),
                                                                    ])
                                                                    ->itemLabel(fn (array $state): ?string => $state['alt'] ?? null)
                                                                    ->defaultItems(1)
                                                                    // 2026-08-31, pedido del Tech Lead ("cuanto brands
                                                                    // como maximo listará el api"): tope explícito de
                                                                    // 28 (4 páginas completas de 7) — no hay necesidad
                                                                    // real de una franja de marcas más larga que eso,
                                                                    // y evita que el carousel crezca sin límite. Es un
                                                                    // tope de UX/consistencia, no una limitación
                                                                    // técnica — se puede subir si hace falta.
                                                                    ->maxItems(28)
                                                                    ->collapsible()
                                                                    ->collapsed(),
                                                            ]),

                                                        // 2026-08-31, pedido del Tech Lead: filtro por defecto
                                                        // (grayscale + opacidad reducida) que se saca por completo
                                                        // al pasar el mouse por ENCIMA DE CADA LOGO (no de toda la
                                                        // sección), mostrando el logo a color real sin ningún
                                                        // filtro — patrón clásico de "franja de marcas". Reusa
                                                        // `media_filter_grayscale`/`media_opacity`, ya genéricos
                                                        // (mismo set que `split`), no hizo falta crear properties
                                                        // nuevas — solo sumarlas acá.
                                                        Section::make('Personalización de estilos')
                                                            ->collapsed()
                                                            ->schema([
                                                                PropertiesSchema::make(['background_color', 'padding_y', 'media_filter_grayscale', 'media_opacity'])
                                                                    ->columns(2),
                                                            ]),
                                                    ]),

                                                // 12. SERVICES GRID Block
                                                Builder\Block::make('services_grid')
                                                    ->label('Grid de Servicios')
                                                    ->icon('heroicon-o-squares-2x2')
                                                    ->schema([
                                                        Grid::make(2)
                                                            ->schema([
                                                                Forms\Components\Hidden::make('lang_iso')
                                                                    ->default('es'),

                                                                Forms\Components\Toggle::make('is_visible')
                                                                    ->label('Visible')
                                                                    ->helperText('Oculta el bloque en el sitio público sin borrarlo del editor.')
                                                                    ->default(true)
                                                                    ->required(),

                                                                HeadingFieldset::make(),

                                                                Forms\Components\TextInput::make('title')
                                                                    ->label('Título')
                                                                    ->maxLength(255),

                                                                Forms\Components\TextInput::make('subtitle')
                                                                    ->label('Subtítulo')
                                                                    ->maxLength(255),
                                                            ]),

                                                        Section::make('Elementos del Grid')
                                                            ->schema([
                                                                Forms\Components\Repeater::make('content.items')
                                                                    ->label('Servicios')
                                                                    ->schema([
                                                                        Forms\Components\TextInput::make('title')
                                                                            ->label('Título')
                                                                            ->required(),
                                                                        Forms\Components\TextInput::make('subtitle')
                                                                            ->label('Subtítulo'),
                                                                        MediaUpload::make('image_id', 'Imagen'),
                                                                        Forms\Components\Select::make('page_id')
                                                                            ->label('Destino: Página')
                                                                            ->options(fn () => Page::pluck('title', 'id'))
                                                                            ->searchable(),
                                                                        Forms\Components\TextInput::make('url')
                                                                            ->label('Destino: URL externa'),
                                                                        Forms\Components\TextInput::make('badge')
                                                                            ->label('Etiqueta / Badge'),
                                                                    ])
                                                                    ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                                                                    ->defaultItems(1)
                                                                    ->collapsible()
                                                                    ->collapsed(),
                                                            ]),

                                                        PropertiesSchema::make(['background_color', 'text_color', 'padding_y', 'animation']),
                                                    ]),
                                            ])
                                            ->loadStateFromRelationshipsUsing(static function (Builder $component) {
                                                $record = $component->getRecord();
                                                if (! $record) {
                                                    return;
                                                }

                                                $state = $record->blocks()
                                                    ->orderBy('sort_order')
                                                    ->get()
                                                    ->map(function ($block) {
                                                        return [
                                                            'type' => $block->type->value ?? $block->type,
                                                            'data' => [
                                                                'id' => $block->id,
                                                                'uuid' => $block->uuid,
                                                                'lang_iso' => $block->lang_iso->value ?? $block->lang_iso,
                                                                'pretitle' => $block->pretitle,
                                                                'title' => $block->title,
                                                                'subtitle' => $block->subtitle,
                                                                'is_visible' => $block->is_visible,
                                                                'links' => $block->links,
                                                                'properties' => self::backfillSliderDefaults($block->properties),
                                                                'content' => $block->content,
                                                            ],
                                                        ];
                                                    })
                                                    ->toArray();

                                                $component->state($state);
                                            })
                                            ->saveRelationshipsUsing(static function (Builder $component, $state) {
                                                $record = $component->getRecord();
                                                if (! $record) {
                                                    return;
                                                }

                                                // Red de seguridad crítica (2026-09-01, reporte real del
                                                // Tech Lead: "si guardo sin cambiar nada, se borra/daña el
                                                // contenido"): si `$state` llega vacío pero la página YA
                                                // tiene bloques guardados, algo salió mal en la hidratación
                                                // del Builder (tab no visitada, glitch de Livewire, lo que
                                                // sea) — sin este guard, el `whereNotIn('id', [])->delete()`
                                                // de más abajo borraría TODOS los bloques de la página en
                                                // cualquier guardado donde el estado no haya llegado bien,
                                                // incluyendo un guardado donde el usuario no tocó
                                                // "Contenidos" para nada. Nunca es correcto que un guardado
                                                // silencioso destruya contenido real — se aborta el save de
                                                // bloques (dejando los existentes intactos) y se deja rastro
                                                // en el log para investigar la causa real.
                                                if (empty($state) && $record->blocks()->exists()) {
                                                    Log::warning('PageResource: saveRelationshipsUsing de "blocks" recibió estado vacío en una página que ya tiene bloques — se aborta el guardado de bloques para no borrarlos por error.', [
                                                        'page_id' => $record->id,
                                                        'page_slug' => $record->slug ?? null,
                                                    ]);

                                                    return;
                                                }

                                                $existingBlockIds = [];

                                                // `$state` viene keyeado por el ID interno de Livewire de cada
                                                // item del Builder (string tipo UUID, no una posición) — usar
                                                // esa key como `sort_order` rompe la columna integer apenas el
                                                // key deja de "parecer" un número. `array_values()` lo reindexa
                                                // 0,1,2... preservando el orden real (que sí importa: es el
                                                // orden en pantalla tras arrastrar/soltar bloques).
                                                foreach (array_values($state) as $index => $blockData) {
                                                    $data = $blockData['data'] ?? [];
                                                    $type = $blockData['type'];

                                                    $attributes = [
                                                        'type' => $type,
                                                        'lang_iso' => $data['lang_iso'] ?? 'es',
                                                        'pretitle' => $data['pretitle'] ?? null,
                                                        'title' => $data['title'] ?? null,
                                                        'subtitle' => $data['subtitle'] ?? null,
                                                        'is_visible' => $data['is_visible'] ?? true,
                                                        'links' => self::unwrapFileUploadState($data['links'] ?? []),
                                                        'properties' => self::backfillSliderDefaults(self::unwrapFileUploadState($data['properties'] ?? [])),
                                                        'content' => self::unwrapFileUploadState($data['content'] ?? []),
                                                        'sort_order' => $index,
                                                        'tenant_id' => $record->tenant_id,
                                                    ];

                                                    if (! empty($data['id'])) {
                                                        $block = $record->blocks()->find($data['id']);
                                                        if ($block) {
                                                            $block->update($attributes);
                                                            $existingBlockIds[] = $block->id;
                                                        }
                                                    } else {
                                                        $block = $record->blocks()->create($attributes);
                                                        $existingBlockIds[] = $block->id;
                                                    }
                                                }

                                                $record->blocks()->whereNotIn('id', $existingBlockIds)->delete();
                                            })
                                            ->collapsible()
                                            ->collapsed()
                                            // Duplicar bloque (2026-08-31, pedido del Tech Lead): Filament 5
                                            // trae esto nativo en `Builder` (`Concerns\CanBeCloned`, mismo
                                            // trait que `Repeater`) — agrega una acción "Duplicar" al menú de
                                            // cada bloque en Studio, clona todo el contenido/properties/links
                                            // tal cual están (mismo `sort_order + 1`, Filament lo reordena
                                            // solo). Útil para el caso real que motivó esto: crear un segundo
                                            // "Split Imagen y Texto" con la imagen del otro lado, partiendo
                                            // del primero ya armado en vez de rehacerlo desde cero.
                                            ->cloneable()
                                            ->columnSpanFull(),
                            ]),

                        Tabs\Tab::make('SEO / Enlaces')
                            ->schema([
                                Section::make('Metadata SEO')
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
                                    ->collapsed()
                                    ->schema([
                                        LinkSchema::make('links', 'Enlaces'),
                                    ]),

                                Section::make('Propiedades de la página')
                                    ->collapsed()
                                    ->schema([
                                        PropertiesSchema::make(['background_color', 'text_color', 'animation']),
                                    ]),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * IDs de todos los descendientes de `$page` (hijos + nietos), para
     * excluirlos de las opciones de `parent_id` al editarla — elegir un
     * descendiente propio como padre formaría un ciclo. Con el máximo de 3
     * niveles del árbol, la recursión nunca baja más de 2 veces.
     *
     * @return array<int, int>
     */
    private static function descendantPageIds(Page $page): array
    {
        $ids = [];

        foreach ($page->children as $child) {
            $ids[] = $child->id;
            $ids = array_merge($ids, static::descendantPageIds($child));
        }

        return $ids;
    }

    public static function table(Table $table): Table
    {
        return $table
            // Eager-load de 2 niveles hacia arriba (`parent.parent`) para
            // que `Page::depth()` no dispare una query por fila al indentar
            // el título en la tabla (el árbol tiene máximo 3 niveles, así
            // que 2 niveles de `with()` alcanzan siempre). Orden: páginas de
            // primer nivel antes que sus hijas, por título — agrupa por
            // nivel de forma simple y segura (sin JOINs recursivos contra
            // la misma tabla, más frágil de mantener); no interlinea cada
            // hija exactamente debajo de SU padre entre sí, pero junto con
            // el indentado "— " de abajo alcanza para que la jerarquía se
            // entienda de un vistazo — suficiente para el volumen de
            // páginas de un tenant típico (todas caben en una sola página
            // de la tabla).
            ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query
                ->with('parent.parent')
                ->orderByRaw('parent_id IS NOT NULL')
                ->orderBy('title'))
            ->columns([
                // Título+slug+tipo fusionados en 1 columna, 2 filas
                // (2026-08-31, pedido del Tech Lead, mismo patrón ya usado
                // en Servicios/Testimonios/Publicaciones) — título en
                // negrita arriba, "slug - Tipo" en gris debajo. Indentado
                // con "— " por nivel para que el árbol de hasta 3 niveles
                // (página → subpágina → sub-subpágina) se vea de un vistazo.
                Tables\Columns\TextColumn::make('title')
                    ->label('Título')
                    ->searchable(['title', 'slug'])
                    ->weight('bold')
                    ->formatStateUsing(fn (Page $record, string $state): string => str_repeat('— ', $record->depth()).$state)
                    ->description(fn (Page $record): string => $record->slug.' - '.($record->type?->getLabel() ?? '—')),

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

                // 2026-08-31, pedido del Tech Lead: en vez de tilde verde /
                // X roja, el MISMO ícono de check en los dos estados (gris
                // "apagado" / verde "prendido") y clickeable tipo toggle —
                // clic activa esta página como Home directo desde la tabla,
                // sin abrir el form. `Column::action()` (no `ToggleColumn`,
                // que se ve como un switch, no como este ícono) permite
                // colgar una `Action` de cualquier columna, incluida
                // `IconColumn`. Solo puede haber 1 página Home por tenant a
                // la vez — al activar una, se desactivan las demás.
                Tables\Columns\IconColumn::make('is_home')
                    ->label('Inicio')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->action(
                        Actions\Action::make('toggleIsHome')
                            ->action(function (Page $record): void {
                                if ($record->is_home) {
                                    $record->update(['is_home' => false]);

                                    return;
                                }

                                Page::where('tenant_id', $record->tenant_id)
                                    ->where('id', '!=', $record->id)
                                    ->where('is_home', true)
                                    ->update(['is_home' => false]);

                                $record->update(['is_home' => true]);
                            })
                    )
                    ->sortable(),

                // Fecha "amigable" en todos los listados (2026-08-31,
                // pedido del Tech Lead) — relativo ("hace 2 días") si es
                // reciente, `d:m:Y h:i a` si no. Ver `FriendlyDate`/ADR-021.
                Tables\Columns\TextColumn::make('published_at')
                    ->label('Fecha pub.')
                    ->formatStateUsing(fn (mixed $state): ?string => FriendlyDate::format($state))
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(PageTypeEnum::class),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options(PublishStatusEnum::class),

            ])
            ->actions([
                Actions\EditAction::make()
                    ->slideOver()
                    ->modalHeading(fn (Page $record) => 'Editar '.($record->type?->getLabel() ?? 'Contenido')),
                Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading(function ($livewire) {
                $tab = $livewire->activeTab ?? 'paginas';

                return match ($tab) {
                    'legales' => 'No hay contenido legal',
                    'partials' => 'No hay secciones ni parciales',
                    default => 'No hay páginas creadas',
                };
            })
            ->emptyStateActions([
                Actions\Action::make('create_from_empty_state')
                    ->label(function ($livewire) {
                        $tab = $livewire->activeTab ?? 'paginas';

                        return match ($tab) {
                            'legales' => 'Crear Aviso Legal',
                            'partials' => 'Crear Sección',
                            default => 'Crear Página',
                        };
                    })
                    ->icon('heroicon-m-plus')
                    ->button()
                    ->action(function ($livewire) {
                        $tab = $livewire->activeTab ?? 'paginas';
                        $actionName = match ($tab) {
                            'legales' => 'create_legal',
                            'partials' => 'create_header',
                            default => 'create_page',
                        };

                        $livewire->mountAction($actionName);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManagePages::route('/'),
        ];
    }
}
