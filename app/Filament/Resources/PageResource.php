<?php

namespace App\Filament\Resources;

use App\Enums\LanguageEnum;
use App\Enums\PageTypeEnum;
use App\Enums\PublishStatusEnum;
use App\Enums\SocialPlatformEnum;
use App\Filament\Resources\PageResource\Pages;
use App\Filament\Schemas\HeadingFieldset;
use App\Filament\Schemas\LinkSchema;
use App\Filament\Schemas\MediaUpload;
use App\Filament\Schemas\PropertiesSchema;
use App\Models\Block;
use App\Models\Form;
use App\Models\Menu;
use App\Models\Page;
use App\Models\Slider;
use App\Support\FriendlyDate;
use Filament\Actions;
use Filament\Facades\Filament;
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
use Filament\Support\Facades\FilamentColor;
use Filament\Support\View\Components\BadgeComponent;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;

class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Contenidos';

    protected static ?string $pluralLabel = 'Contenidos';

    protected static ?string $modelLabel = 'Contenido';

    protected static ?string $slug = 'pages';

    /**
     * Únicos tipos de Content que pueden ser la página de inicio del
     * tenant (2026-09-01, pedido del Tech Lead) — un Header/Footer/Legal
     * es un partial compartido o contenido estático, nunca "la Home".
     * Consumido por la columna `is_home` de `table()` (ícono, tooltip y
     * el guard del `action()`); el `Toggle::make('is_home')` del FORM
     * (`HeadingFieldset`) tiene su propio `->visible()` equivalente, no
     * comparte esta constante porque vive en una clase separada.
     *
     * @var array<int, PageTypeEnum>
     */
    private const IS_HOME_ELIGIBLE_TYPES = [PageTypeEnum::Page, PageTypeEnum::Landing];

    /**
     * Markup de badge para el tipo de contenido, embebido DENTRO de la
     * descripción de la columna "Título" (2026-09-02, pedido del Tech Lead:
     * "los badges de tipo de contenido tiene que estar a lado del slug" —
     * corrige un primer intento que los había separado a una columna propia
     * en el extremo derecho de la tabla). Genera el MISMO HTML que produce
     * `TextColumn::badge()` nativo (`fi-badge fi-size-sm {clases del
     * color}`, ver `Filament\Tables\Columns\TextColumn::toOptimizedHtml()`)
     * en vez de un `<span>` con estilos inventados a mano, para que se vea
     * idéntico a cualquier otro badge de Filament en la misma pantalla
     * (ej. la columna `Estado`). `TextColumn::description()` acepta un
     * `Htmlable`; el helper `e()` de Laravel NO escapa un `Htmlable` — lo
     * renderiza tal cual — así que retornar HTML acá es seguro (no hay
     * doble-escape ni XSS: `$label` sí se escapa antes de insertarlo).
     */
    private static function typeBadgeHtml(Page $record): string
    {
        $color = match ($record->type) {
            PageTypeEnum::Page => 'primary',
            PageTypeEnum::Landing => 'info',
            PageTypeEnum::Legal => 'warning',
            PageTypeEnum::Header, PageTypeEnum::Footer => 'gray',
            default => 'gray',
        };

        $label = e($record->type?->getLabel() ?? '—');
        $classes = implode(' ', FilamentColor::getComponentClasses(BadgeComponent::class, $color));

        return '<span class="fi-badge fi-size-sm '.$classes.'">'.$label.'</span>';
    }

    /**
     * Soft delete (2026-09-01, ver `Page::class` y la migración
     * `2026_09_01_000001_add_soft_deletes_to_pages_table.php`): se saca el
     * global scope de `SoftDeletes` acá para que `Tables\Filters\TrashedFilter`
     * (agregado en `table()`) pueda controlar por completo qué se ve — "Sin
     * papelereados" (default), "Con papelereados" o "Solo papelereados" —,
     * en vez de que el scope global excluya siempre los papelereados antes
     * de que el filtro llegue a aplicar nada.
     */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

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
     * 2026-09-02, mismo bug de fondo que `SLIDER_PROPERTY_DEFAULTS` de
     * arriba, ahora mordiendo a `properties.background_type` (ADR-041, el
     * Select se volvió `->required()`): cualquier bloque/página guardado
     * ANTES de que ese campo existiera (o cualquiera donde el Tech Lead
     * nunca llegó a tocar la sección de estilos) llega con `background_type`
     * ausente — el `->default('solid')` de Filament solo se aplica al crear
     * un registro NUEVO, no al hidratar datos parciales vía
     * `loadStateFromRelationshipsUsing`. Resultado real reportado en vivo
     * (captura, bloque `colophon`/"pie de página"): el Select llega vacío
     * ("Seleccione una opción") y bloquea el guardado con "El campo tipo de
     * fondo es obligatorio", aun sin haber tocado esa sección.
     */
    private const string BACKGROUND_TYPE_DEFAULT = 'solid';

    /**
     * Rellena con su default real cualquier propiedad ausente en
     * `$properties` que sufra el bug "el `->default()` de Filament no se
     * aplica al hidratar datos parciales" — sliders (`SLIDER_PROPERTY_DEFAULTS`)
     * y, desde 2026-09-02, `background_type` (`BACKGROUND_TYPE_DEFAULT`).
     * Nunca pisa un valor ya presente (incluido un `0` puesto a propósito
     * por el Tech Lead, o un `background_type` real ya elegido). No toca
     * ninguna otra key (`background_color`, `text_align`, etc.) — esas no
     * sufren este bug (no son `->required()` sin un valor sembrado, o no
     * dependen de hidratación parcial).
     *
     * @param  array<array-key, mixed>|null  $properties
     * @return array<array-key, mixed>
     */
    private static function backfillSliderDefaults(?array $properties): array
    {
        $properties = array_merge(self::SLIDER_PROPERTY_DEFAULTS, $properties ?? []);
        $properties['background_type'] ??= self::BACKGROUND_TYPE_DEFAULT;

        return $properties;
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

        // Único botón opcional del bloque CTA (2026-09-01, rediseño
        // completo — antes usaba `LinkSchema::make()`, el Repeater
        // multi-enlace, sin sentido para un CTA de un solo botón: "tiene
        // solo un boton link (opcional), si desea o no tenerlo, no
        // necesita varios"). Mismo patrón que `rich_text`/`testimonials`:
        // `link_radius`/`link_size` insertados DENTRO del grid principal,
        // justo después de "Estilo del botón" — son parte del estilo, no
        // de los atributos técnicos del Fieldset "Propiedades del enlace".
        $ctaLinkFields = LinkSchema::makeSingle('links');
        $ctaLinkMainFields = $ctaLinkFields['main'];
        array_splice($ctaLinkMainFields, 1, 0, PropertiesSchema::makeComponents(['link_radius', 'link_size']));

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
                                            // Bloques disponibles condicionados por `type` (2026-09-01,
                                            // pedido del Tech Lead): un Content tipo `Footer` solo debe
                                            // poder agregar el subconjunto de bloques que tiene sentido
                                            // en un footer (imagen, CTA, features, FAQ, formulario,
                                            // testimonios, logos) — NO heading/hero/rich_text/legal_notice/
                                            // split/services_grid, pensados para el body de una `Página`.
                                            // `Página`/`Landing` siguen viendo la lista completa, sin
                                            // cambios. `->blocks()` de Filament acepta un Closure con
                                            // `Get $get` para leer el campo `type` (sibling, reactivo) y
                                            // devolver un subconjunto — se arma la lista completa en
                                            // `$allBlocks` como siempre y se filtra recién al final.
                                            // `use (...)`: un Closure de PHP NO hereda automáticamente las
                                            // variables del scope de `form()` (a diferencia del array
                                            // literal `->blocks([...])` de antes, que sí vivía en el mismo
                                            // scope) — hay que importarlas explícito. Bug real, confirmado
                                            // en Studio (2026-09-01): "Undefined variable
                                            // $richTextLinkMainFields" al abrir el bloque `rich_text` con el
                                            // Closure sin `use`.
                                    ->blocks(function (Get $get) use (
                                        $richTextLinkFields,
                                        $richTextLinkMainFields,
                                        $testimonialsLinkFields,
                                        $ctaLinkFields,
                                        $ctaLinkMainFields
                                    ) {
                                        $allBlocks = [
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
                                                        ->description('Cada tamaño de pantalla puede tener su propio recorte de imagen.')
                                                        ->schema([
                                                            Grid::make(3)
                                                                ->schema([
                                                                    MediaUpload::make('content.image_desktop_id', 'Imagen Desktop')
                                                                        ->required(fn (Get $get) => $get('properties.background_type') === 'image'),
                                                                    MediaUpload::make('content.image_tablet_id', 'Imagen Tablet'),
                                                                    MediaUpload::make('content.image_mobile_id', 'Imagen Móvil'),
                                                                ]),
                                                        ])
                                                        // 2026-09-02, pedido del Tech Lead: color/degradado e
                                                        // imagen pasan a ser EXCLUYENTES acá también — mismo
                                                        // criterio que `cta` (ver más abajo, bloque `cta`).
                                                        ->visible(fn (Get $get) => $get('properties.background_type') === 'image'),

                                                    Section::make('Propiedades Visuales')
                                                        ->description('Decoradores superior e inferior, color de fondo, overlay y ajustes de brillo/contraste.')
                                                        ->collapsed()
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

                                                                    ...PropertiesSchema::makeComponents([
                                                                        'background_type_image', 'background_color',
                                                                        'background_color_secondary', 'gradient_direction',
                                                                    ]),

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
                                                                ->description('La imagen de escritorio es obligatoria en modo Manual; tablet y móvil son opcionales.')
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
                                                                    Grid::make(3)
                                                                        ->schema(PropertiesSchema::makeComponents(['background_type', 'background_color', 'text_color'])),
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
                                                            Grid::make(3)
                                                                ->schema(PropertiesSchema::makeComponents([
                                                                    'background_type', 'background_color', 'text_color',
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

                                                    PropertiesSchema::make(['background_type', 'background_color', 'padding_y', 'animation']),
                                                ]),

                                            // 4. CTA Block
                                            //
                                            // 2026-09-01, rediseño completo a pedido del Tech Lead (con captura de
                                            // referencia: franja indigo sólida, título+subtítulo centrados, un
                                            // solo botón dorado en pill con ícono). Antes: `content.body`
                                            // (Textarea suelto, redundante con `subtitle` — el mockup no muestra
                                            // una tercera línea de texto, se elimina) + `LinkSchema::make()`
                                            // (Repeater multi-enlace, sin sentido acá — "tiene solo un boton
                                            // link (opcional), si desea o no tenerlo, no necesita varios") + solo
                                            // 5 properties sueltas, ninguna de fondo con imagen.
                                            //
                                            // Estándar nuevo confirmado por el Tech Lead, aplicado acá y pensado
                                            // para reusarse en bloques de sección completa futuros: la `<section>`
                                            // SIEMPRE es fullwidth (fondo de color/imagen edge-to-edge, sin
                                            // excepción) — lo que `content_width` condiciona es solo el
                                            // CONTENEDOR interno (texto + botón). Fondo en 2 capas apilables, no
                                            // excluyentes: `background_color` (base, tapa toda la sección) +
                                            // `content.background_image_id` opcional como capa INTERMEDIA
                                            // superpuesta encima de ese color (no lo reemplaza) — reusa las
                                            // properties `media_*` ya genéricas (blend mode + 6 filtros CSS +
                                            // opacidad, mismo set que `split`/`heading`) para que la imagen se
                                            // pueda mezclar/filtrar sobre el color de fondo. `overlay_opacity`
                                            // (ya existía en `PropertiesSchema` pero sin ningún consumidor real
                                            // en ningún bloque hasta hoy) pasa a tener uso real acá: un velo
                                            // oscuro opcional ENCIMA de la imagen (debajo del texto), para
                                            // legibilidad — independiente de los filtros/blend de la imagen en
                                            // sí. Ver `Cta.astro` (cica360) para el armado real de las capas.
                                            Builder\Block::make('cta')
                                                ->label('Llamado a la Acción (CTA)')
                                                ->icon('heroicon-o-megaphone')
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

                                                    // Pretítulo y "subtítulo" son ambos opcionales en este bloque.
                                                    // El campo `subtitle` (mismo campo genérico de siempre, para
                                                    // no romper el patrón de `block.subtitle` compartido con el
                                                    // resto de los bloques) en el CTA NO es un subtítulo real —
                                                    // es una descripción breve de una línea bajo el título, así
                                                    // que se relabela solo acá para que el editor de contenido
                                                    // no se confunda (pedido del Tech Lead, 2026-09-01).
                                                    HeadingFieldset::make(
                                                        pretitleLabel: 'Pre título (Opcional)',
                                                        subtitleLabel: 'Descripción breve (Opcional)',
                                                    ),

                                                    Section::make('Fondo')
                                                        ->description('Elegí si el fondo de la sección es un color sólido, un degradado o una imagen.')
                                                        ->schema([
                                                            Grid::make(2)
                                                                ->schema(PropertiesSchema::makeComponents([
                                                                    'background_type_image', 'background_color',
                                                                    'background_color_secondary', 'gradient_direction',
                                                                ])),

                                                            // 2026-09-02, pedido del Tech Lead: color/degradado e
                                                            // imagen pasan a ser EXCLUYENTES (antes convivían en
                                                            // capas) — la imagen y sus filtros/mezcla solo se
                                                            // muestran con `background_type: image`; el color se
                                                            // oculta solo (ver `PropertiesSchema::background_color`).
                                                            MediaUpload::make('background_image_id', 'Imagen de fondo')
                                                                ->required(fn (Get $get) => $get('properties.background_type') === 'image')
                                                                ->visible(fn (Get $get) => $get('properties.background_type') === 'image'),

                                                            Grid::make(2)
                                                                ->schema(PropertiesSchema::makeComponents([
                                                                    'media_blend_mode', 'overlay_opacity',
                                                                    'media_brightness', 'media_opacity',
                                                                    'media_filter_saturate', 'media_filter_grayscale',
                                                                    'media_filter_sepia', 'media_filter_contrast',
                                                                    'media_filter_hue_rotate', 'media_filter_blur',
                                                                ]))
                                                                ->visible(fn (Get $get) => $get('properties.background_type') === 'image'),
                                                        ])
                                                        ->collapsed(),

                                                    Section::make('Botón (opcional)')
                                                        ->description('Enlace a una página, post o URL externa, con ícono y estilo propios.')
                                                        ->schema([
                                                            Grid::make(1)
                                                                ->schema(PropertiesSchema::makeComponents(['show_link'])),

                                                            Grid::make(2)
                                                                ->schema($ctaLinkMainFields)
                                                                ->visible(fn (Get $get) => (bool) $get('properties.show_link')),

                                                            Fieldset::make('Propiedades del enlace')
                                                                ->schema($ctaLinkFields['properties'])
                                                                ->columns(2)
                                                                ->visible(fn (Get $get) => (bool) $get('properties.show_link')),
                                                        ])
                                                        ->collapsed(),

                                                    Section::make('Personalización de estilos')
                                                        ->description('Color de texto, ancho del contenido y espaciado vertical de la sección.')
                                                        ->collapsed()
                                                        ->schema([
                                                            PropertiesSchema::make(['text_color', 'content_width', 'padding_y'])
                                                                ->columns(2),
                                                        ]),
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

                                                    PropertiesSchema::make(['background_type', 'background_color', 'text_color', 'padding_y', 'animation']),
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

                                                    PropertiesSchema::make(['background_type', 'background_color', 'text_color', 'padding_y']),
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

                                                    PropertiesSchema::make(['background_type', 'background_color', 'text_color', 'padding_y', 'content_width']),
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
                                                        ->description('Posición de la imagen, colores de fondo y texto, ancho, espaciado, animación y filtros/mezcla de la imagen.')
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

                                                                    // Relabel puntual (2026-09-01, pedido del Tech Lead:
                                                                    // labels cortos pero claros "para no confundir al
                                                                    // personalizar"): acá en `split`, con la columna de
                                                                    // texto ahora con SU PROPIO fondo (`text_background_color`
                                                                    // de arriba), el `background_color` genérico queda
                                                                    // efectivamente detrás de la imagen — se relabela solo
                                                                    // en este call site (no toca el label global "Color de
                                                                    // fondo" que usan `cta`/`rich_text`/`testimonials`/`logos`,
                                                                    // donde SÍ es el fondo de toda la sección).
                                                                    PropertiesSchema::makeComponents(['background_type'])[0]
                                                                        ->label('Tipo de fondo de la imagen'),

                                                                    PropertiesSchema::makeComponents(['background_color'])[0]
                                                                        ->label('Color de fondo de la imagen'),

                                                                    ...PropertiesSchema::makeComponents([
                                                                        'text_background_color', 'text_color', 'padding_y', 'content_width', 'animation',
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
                                                        ->description('Color de fondo de la sección y de las tarjetas, color de texto, espaciado y animación de entrada.')
                                                        ->collapsed()
                                                        ->schema([
                                                            PropertiesSchema::make(['background_type', 'background_color', 'item_background_color', 'item_background_opacity', 'text_color', 'padding_y', 'animation'])
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
                                                        // 2026-09-01, actualizado: la descripción anterior decía
                                                        // "de a 7 por página, fijo" — ya no es así, ver
                                                        // `Logos.astro` (cica360): la cantidad visible a la vez
                                                        // ahora es responsive (1 en mobile → 7 desde 1700px). El
                                                        // límite real de cuántos se COMPARTEN con el sitio ahora
                                                        // se controla acá abajo, en "Límite compartido con la API".
                                                        ->description('Cargá acá todos los logos que quieras (tope de 28) — cuáles y cuántos de estos llegan al sitio público se controla en "Límite compartido con la API" más abajo.')
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
                                                                // 28 — no hay necesidad real de una franja de
                                                                // marcas más larga que eso. Es un tope de
                                                                // UX/consistencia, no una limitación técnica — se
                                                                // puede subir si hace falta. El límite REAL de cara
                                                                // a la API/sitio es el campo de abajo
                                                                // (`content.limit`), independiente de este tope.
                                                                ->maxItems(28)
                                                                ->collapsible()
                                                                ->collapsed(),
                                                        ]),

                                                    // 2026-09-01, pedido del Tech Lead: "en el admin solo se
                                                    // deberia indicar cuantos se listaran en el api para poner
                                                    // un limite maximo de logos compartidos con el frontsite y
                                                    // el orden los mas recientes o los primeros". A diferencia
                                                    // de `testimonials` (que resuelve `content.limit`/
                                                    // `content.order` en runtime contra una tabla propia, ver
                                                    // `TestimonialResource`), acá NO se creó una tabla nueva —
                                                    // los logos siguen siendo el `Repeater` de arriba
                                                    // (`content.items`, mismo patrón que `features`/
                                                    // `services_grid`, decisión explícita del Tech Lead de no
                                                    // subir de alcance). El límite/orden se aplican en
                                                    // `ResolvesPublicLinks::transformBlockContent()` sobre esos
                                                    // mismos items antes de mandarlos a la API — "los primeros"
                                                    // es el orden tal cual quedó en la lista de arriba (arriba =
                                                    // primero), "los más recientes" es esa misma lista
                                                    // invertida (los logos NO tienen fecha propia — "más
                                                    // reciente" acá es "el último que se agregó a la lista").
                                                    // Sin límite seteado (`content.limit` vacío): se comparten
                                                    // TODOS los logos cargados, sin recortar — mismo
                                                    // comportamiento que tenía el bloque antes de que existiera
                                                    // este campo, cero regresión para contenido ya sembrado.
                                                    Section::make('Límite compartido con la API')
                                                        ->description('Cuántos de los logos de arriba se comparten con el sitio público (y en qué orden) — no borra ni oculta los demás acá en Studio, solo recorta lo que sale en la API.')
                                                        ->schema([
                                                            Grid::make(2)
                                                                ->schema([
                                                                    Forms\Components\TextInput::make('content.limit')
                                                                        ->label('Cantidad máxima a compartir')
                                                                        ->helperText('Vacío = sin límite, se comparten todos los cargados arriba.')
                                                                        ->numeric()
                                                                        ->minValue(1)
                                                                        ->maxValue(28),

                                                                    // Opcional (2026-09-01, pedido del Tech Lead:
                                                                    // "en el bloque logos debería ser opcional
                                                                    // orden") — a diferencia de `testimonials`
                                                                    // (que sí lo pide `->required()`), acá no
                                                                    // hace falta bloquear el guardado: el
                                                                    // resolver ya cae a `'first'` si viene vacío
                                                                    // (`$content['order'] ?? 'first'`, ver
                                                                    // `ResolvesPublicLinks`), y `->default('first')`
                                                                    // sigue precargando el Select para bloques
                                                                    // nuevos, sin forzar una elección explícita.
                                                                    Forms\Components\Select::make('content.order')
                                                                        ->label('Orden')
                                                                        ->options([
                                                                            'first' => 'Los primeros de la lista',
                                                                            'recent' => 'Los más recientes (últimos agregados)',
                                                                        ])
                                                                        ->default('first'),
                                                                ]),
                                                        ]),

                                                    // 2026-08-31, pedido del Tech Lead: filtro por defecto
                                                    // (grayscale + opacidad reducida) que se saca por completo
                                                    // al pasar el mouse por ENCIMA DE CADA LOGO (no de toda la
                                                    // sección), mostrando el logo a color real sin ningún
                                                    // filtro — patrón clásico de "franja de marcas". Reusa
                                                    // `media_filter_grayscale`/`media_opacity`, ya genéricos
                                                    // (mismo set que `split`), no hizo falta crear properties
                                                    // nuevas — solo sumarlas acá.
                                                    //
                                                    // `content_width` (2026-09-01, pedido del Tech Lead): mismo
                                                    // property genérico que ya usan `split`/`rich_text`
                                                    // (`PropertiesSchema`), sin necesidad de crear uno nuevo —
                                                    // acá solo interesan 2 de sus 3 opciones (`full`/`boxed`,
                                                    // "narrow" no tiene sentido para una franja de logos), pero
                                                    // se deja el campo genérico completo por consistencia; el
                                                    // frontend simplemente no expone la opción rara si no la
                                                    // necesita. A diferencia de `split`/`rich_text` (que caen a
                                                    // `boxed` cuando la property no está seteada), acá el
                                                    // default real es `full` — pedido explícito del Tech Lead
                                                    // ("por default sea fullwidth") — resuelto en el fallback
                                                    // del frontend (`Logos.astro`), no acá: Filament `->default()`
                                                    // en un `Select` compartido por varios bloques solo aplicaría
                                                    // a bloques NUEVOS creados desde cero, no a los ya sembrados
                                                    // (mismo motivo por el que `split`/`rich_text` tampoco lo usan
                                                    // — ver ADR-032).
                                                    Section::make('Personalización de estilos')
                                                        ->description('Color de fondo, espaciado, ancho de contenido y filtro de escala de grises/opacidad de los logos.')
                                                        ->collapsed()
                                                        ->schema([
                                                            PropertiesSchema::make(['background_type', 'background_color', 'padding_y', 'content_width', 'media_filter_grayscale', 'media_opacity'])
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
                                                                        // ->publiclyLinkable() (2026-09-02, bug real en
                                                                        // vivo): excluye Header/Footer del selector.
                                                                        ->options(fn () => Page::query()->publiclyLinkable()->pluck('title', 'id'))
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

                                                    PropertiesSchema::make(['background_type', 'background_color', 'text_color', 'padding_y', 'animation']),
                                                ]),

                                            // FOOTER Block (2026-09-01, pedido del Tech Lead): no es
                                            // contenido propio — es una REFERENCIA a un Content tipo
                                            // `Footer` del mismo tenant (mismo patrón que `hero` con
                                            // `content.slider_id` arriba), para agrupar varias
                                            // Páginas/Landings bajo el mismo pie de página compartido.
                                            // Reemplaza el mecanismo anterior (fetch global fijo a
                                            // `footer-principal` en el layout del frontend) — ver
                                            // ADR correspondiente en DECISIONS.md. No se incluye acá
                                            // mismo en `$footerAllowedBlocks` más abajo: un Content tipo
                                            // `Footer` no puede referenciar OTRO footer (anti-recursión,
                                            // reforzado también server-side en ResolvesPublicLinks).
                                            Builder\Block::make('footer')
                                                ->label('Footer')
                                                ->icon('heroicon-o-rectangle-stack')
                                                ->schema([
                                                    Forms\Components\Select::make('content.footer_page_id')
                                                        ->label('Sección de Footer')
                                                        ->helperText('Selecciona el Content tipo "Footer" que se renderizará como pie de página en esta Página/Landing. Se resuelve junto con sus propios bloques (CTA, logos, etc.).')
                                                        ->options(fn () => Page::query()
                                                            ->where('type', PageTypeEnum::Footer->value)
                                                            ->pluck('title', 'id'))
                                                        ->searchable()
                                                        ->required(),
                                                ]),

                                            // COLOPHON (2026-09-02, pedido del Tech Lead): pie de página
                                            // multi-columna — sin `HeadingFieldset` a propósito ("no
                                            // tendrá header o heading"). Hasta 4 "Columna N" (Repeater,
                                            // `maxItems(4)`, `itemLabel` literal por posición, NO por el
                                            // título que cargue el usuario — así se ve "Columna 1/2/3/4"
                                            // aunque el título esté vacío o repetido). Cada columna tiene
                                            // título corto + descripción breve (120 caracteres, con
                                            // contador vía `->hint()`) y un `Builder` ANIDADO propio
                                            // ("botón dropdown para elegir subbloques") con 3 tipos:
                                            // lista de enlaces (`LinkSchema::make()`, misma resolución de
                                            // página/post/URL que el resto de la app), redes sociales
                                            // (`SocialPlatformEnum`, ícono predeterminado por plataforma
                                            // resuelto en el frontend) e imagen con enlace (`MediaUpload`
                                            // + un único link vía `LinkSchema::makeSingle()`). Este
                                            // Builder anidado NO tiene `saveRelationshipsUsing` propio —
                                            // vive dentro de `content.columns` (jsonb), así que la
                                            // deshidratación nativa de Filament (uuid-keyed → array
                                            // secuencial `{type,data}`) alcanza sola, igual que cualquier
                                            // otro campo `content.*`; se resuelve en `ResolvesPublicLinks`
                                            // (ver ese archivo, branch `colophon`).
                                            Builder\Block::make('colophon')
                                                ->label('Colophon (columnas del pie de página)')
                                                ->icon('heroicon-o-view-columns')
                                                ->schema([
                                                    Forms\Components\Repeater::make('content.columns')
                                                        ->label('Columnas')
                                                        ->maxItems(4)
                                                        ->defaultItems(1)
                                                        ->itemLabel(fn (?int $index): string => 'Columna '.(($index ?? 0) + 1))
                                                        ->schema([
                                                            Forms\Components\TextInput::make('title')
                                                                ->label('Título corto')
                                                                ->maxLength(255),

                                                            Forms\Components\Textarea::make('description')
                                                                ->label('Descripción breve')
                                                                ->rows(3)
                                                                ->maxLength(120)
                                                                ->live(onBlur: true)
                                                                ->hint(fn (?string $state): string => mb_strlen($state ?? '').' / 120 caracteres'),

                                                            Builder::make('blocks')
                                                                ->label('Contenido de la columna')
                                                                ->addActionLabel('Agregar contenido')
                                                                ->blocks([
                                                                    Builder\Block::make('link_list')
                                                                        ->label('Lista de enlaces')
                                                                        ->icon('heroicon-o-link')
                                                                        ->schema([
                                                                            // `withIcon: true` (2026-09-02, "faltan
                                                                            // iconos"): único consumidor de
                                                                            // `LinkSchema::make()` que pide el Select
                                                                            // de ícono opcional (`LinkIconEnum`) por
                                                                            // ítem — ver comentario del parámetro.
                                                                            LinkSchema::make('items', 'Enlaces', true),
                                                                        ]),

                                                                    Builder\Block::make('social_links')
                                                                        ->label('Redes sociales')
                                                                        ->icon('heroicon-o-share')
                                                                        ->schema([
                                                                            Forms\Components\Repeater::make('items')
                                                                                ->label('Redes')
                                                                                ->schema([
                                                                                    Grid::make(2)
                                                                                        ->schema([
                                                                                            Forms\Components\Select::make('platform')
                                                                                                ->label('Plataforma')
                                                                                                ->options(SocialPlatformEnum::class)
                                                                                                ->required(),

                                                                                            Forms\Components\TextInput::make('url')
                                                                                                ->label('URL')
                                                                                                ->url()
                                                                                                ->required(),
                                                                                        ]),
                                                                                ])
                                                                                // Fix real (2026-09-02, `TypeError` en vivo: `tryFrom():
                                                                                // Argument #1 ($value) must be of type string|int,
                                                                                // App\Enums\SocialPlatformEnum given`): cuando un
                                                                                // `Select::make()->options(EnumClass::class)` vive dentro
                                                                                // de un `Repeater`, el `$state` que llega a `itemLabel()`
                                                                                // puede traer `platform` YA como instancia del enum (no el
                                                                                // string crudo) — depende del momento del ciclo de vida en
                                                                                // que Livewire dispara el update. `tryFrom()` exige
                                                                                // string|int, revienta con un objeto. Se cubre los 2 casos
                                                                                // en vez de asumir uno solo.
                                                                                ->itemLabel(function (array $state): ?string {
                                                                                    $platform = $state['platform'] ?? null;

                                                                                    if ($platform instanceof SocialPlatformEnum) {
                                                                                        return $platform->getLabel();
                                                                                    }

                                                                                    return SocialPlatformEnum::tryFrom($platform ?? '')?->getLabel();
                                                                                })
                                                                                ->defaultItems(1)
                                                                                ->collapsible()
                                                                                ->collapsed(),
                                                                        ]),

                                                                    Builder\Block::make('image_link')
                                                                        ->label('Imagen con enlace')
                                                                        ->icon('heroicon-o-photo')
                                                                        ->schema([
                                                                            MediaUpload::make('image_id', 'Imagen'),
                                                                            ...LinkSchema::makeSingle('links')['main'],
                                                                        ]),
                                                                ])
                                                                ->collapsible()
                                                                ->collapsed()
                                                                ->blockNumbers(false),
                                                        ])
                                                        ->collapsible()
                                                        ->collapsed()
                                                        ->columnSpanFull(),

                                                    Section::make('Personalización de estilos')
                                                        ->description('Ancho de contenido, fondo sólido, degradado o imagen, color de texto y espaciado vertical.')
                                                        ->collapsed()
                                                        ->schema([
                                                            PropertiesSchema::make([
                                                                'content_width',
                                                                'background_type_image',
                                                                'background_color',
                                                                'background_color_secondary',
                                                                'gradient_direction',
                                                                'text_color',
                                                                'padding_y',
                                                            ])->columns(2),

                                                            // 2026-09-02, pedido del Tech Lead ("en el bloque de
                                                            // colophon no hay esas opciones, no existe imagen,
                                                            // debería tener también la posibilidad de tener una
                                                            // imagen con los filtros y blend que necesiten
                                                            // personalizar") — mismo patrón exacto que `cta`
                                                            // (ver Section 'Fondo' de ese bloque, más arriba):
                                                            // imagen + filtros solo visibles con
                                                            // `background_type: image`, excluyente con el color.
                                                            MediaUpload::make('background_image_id', 'Imagen de fondo')
                                                                ->required(fn (Get $get) => $get('properties.background_type') === 'image')
                                                                ->visible(fn (Get $get) => $get('properties.background_type') === 'image'),

                                                            Grid::make(2)
                                                                ->schema(PropertiesSchema::makeComponents([
                                                                    'media_blend_mode', 'overlay_opacity',
                                                                    'media_brightness', 'media_opacity',
                                                                    'media_filter_saturate', 'media_filter_grayscale',
                                                                    'media_filter_sepia', 'media_filter_contrast',
                                                                    'media_filter_hue_rotate', 'media_filter_blur',
                                                                ]))
                                                                ->visible(fn (Get $get) => $get('properties.background_type') === 'image'),
                                                        ]),
                                                ]),

                                            // FOOTER BOTTOM (2026-09-02, rediseño completo a pedido del
                                            // Tech Lead — reemplaza la versión anterior de 2 casillas de
                                            // texto libres). Estrategia nueva:
                                            //
                                            // IZQUIERDA — copyright, siempre visible, 3 estados según el
                                            //   plan del tenant (ver ADR-043 y `Tenant::canEditCopyright()`/
                                            //   `isSponsorshipTier()` — antes eran solo 2, "editable"/"no
                                            //   editable", hasta que se agregó el plan Auspicio/Convenio,
                                            //   que se asigna a mano por Stamless/Platform al tenant con el
                                            //   que se pactó el convenio/auspicio — no hay flujo de
                                            //   autoservicio ni billing real acá, mismo criterio que el
                                            //   resto de `tenants.plan`):
                                            //
                                            //   1. Free/Freemium puro (`! canEditCopyright()`): `Placeholder`
                                            //      explicando el candado — nunca el campo real, así no se
                                            //      puede escribir ahí ni por error. El FALLBACK hardcodeado
                                            //      ("© {año} Stamless CMS Headless. Todos los derechos
                                            //      reservados.") vive en el FRONTEND (`FooterBottom.astro`).
                                            //   2. Auspicio/Convenio (`isSponsorshipTier()`): SÍ puede
                                            //      escribir, pero el `TextInput` solo pide el fragmento
                                            //      "año + nombre" (ej. "2026 Nombre de tu empresa" — el
                                            //      placeholder NO debe llevar el nombre de un tenant real,
                                            //      este mismo bloque es compartido por cualquier tenant en
                                            //      este plan, no solo CICA360) — el backend
                                            //      (`ResolvesPublicLinks`) arma el copyright final envolviendo
                                            //      ese fragmento en una plantilla FIJA con "Powered by
                                            //      Stamless" (nunca removible en este plan, ver
                                            //      `content.copyright_html` más abajo).
                                            //   3. Cualquier otro plan pago (blanco total): `TextInput`
                                            //      libre de siempre, sin ninguna plantilla forzada.
                                            //
                                            //   Gateado acá por UI (`->visible()`) Y de nuevo en
                                            //   `ResolvesPublicLinks` (defensa en profundidad: si el tenant
                                            //   baja de plan, un valor legado guardado no se sigue
                                            //   sirviendo).
                                            //
                                            // DERECHA — opcional, un solo `Select` de 3 estados
                                            //   ("Seleccionar" vacío / "Mostrar menú" / "Mostrar texto
                                            //   personalizado"): sin nada elegido, el div derecho se
                                            //   oculta del todo y el copyright queda centrado (resuelto en
                                            //   el frontend, mismo criterio que la v1). "Mostrar menú"
                                            //   reusa los menús ya existentes del tenant (Select por
                                            //   nombre) — se resuelve en runtime a SOLO el nivel principal
                                            //   (`MenuItem` con `parent_id: null`, `is_active: true`),
                                            //   ignorando submenús a propósito ("listar solo el nivel
                                            //   principal en caso tenga submenus"), renderizado como
                                            //   `<nav>` semántico en el frontend por SEO. "Mostrar texto"
                                            //   da un `TextInput` de 40 caracteres máx.
                                            //
                                            // Seeders: por default ambos lados quedan sin seleccionar
                                            // ("predeterminado en los seeders sin nada o vacío") — el
                                            // Tech Lead activa "Mostrar menú"/"Mostrar texto" a mano
                                            // cuando lo necesite.
                                            Builder\Block::make('footer_bottom')
                                                ->label('Barra inferior (copyright)')
                                                ->icon('heroicon-o-minus')
                                                ->schema([
                                                    Forms\Components\TextInput::make('content.copyright_text')
                                                        ->label('Año y nombre (Auspicio/Convenio)')
                                                        ->placeholder('2026 Nombre de tu empresa o proyecto')
                                                        ->maxLength(120)
                                                        ->helperText('Se muestra como "©[esto] - Todos los derechos son reservados", con "Powered by Stamless" debajo — la marca de Stamless no se puede quitar en este plan.')
                                                        ->visible(fn () => Filament::getTenant()?->isSponsorshipTier() ?? false),

                                                    Forms\Components\TextInput::make('content.copyright_text')
                                                        ->label('Copyright personalizado')
                                                        ->placeholder('© 2026 Mi Empresa. Todos los derechos reservados.')
                                                        ->maxLength(255)
                                                        ->helperText('Reemplaza el copyright predeterminado de Stamless en el sitio público.')
                                                        ->visible(fn () => (Filament::getTenant()?->canEditCopyright() ?? false) && ! (Filament::getTenant()?->isSponsorshipTier() ?? false)),

                                                    Forms\Components\Placeholder::make('copyright_locked_hint')
                                                        ->label('Copyright')
                                                        ->content('© '.date('Y').' Stamless CMS Headless. Todos los derechos reservados. — predeterminado, disponible para personalizar en planes pagos (marca blanca) o en el plan Auspicio/Convenio.')
                                                        ->visible(fn () => ! (Filament::getTenant()?->canEditCopyright() ?? false)),

                                                    Forms\Components\Select::make('content.right_type')
                                                        ->label('Contenido adicional (lado derecho, opcional)')
                                                        ->placeholder('Seleccionar')
                                                        ->options([
                                                            'menu' => 'Mostrar menú',
                                                            'text' => 'Mostrar texto personalizado',
                                                        ])
                                                        ->live(),

                                                    Forms\Components\Select::make('content.menu_id')
                                                        ->label('Menú')
                                                        ->options(fn () => Menu::pluck('name', 'id'))
                                                        ->searchable()
                                                        ->helperText('Se muestra solo el nivel principal, aunque el menú tenga submenús.')
                                                        ->required(fn (Get $get) => $get('content.right_type') === 'menu')
                                                        ->visible(fn (Get $get) => $get('content.right_type') === 'menu'),

                                                    Forms\Components\TextInput::make('content.right_text')
                                                        ->label('Texto personalizado')
                                                        ->maxLength(40)
                                                        ->helperText('Máximo 40 caracteres.')
                                                        ->required(fn (Get $get) => $get('content.right_type') === 'text')
                                                        ->visible(fn (Get $get) => $get('content.right_type') === 'text'),

                                                    Section::make('Personalización de estilos')
                                                        ->description('Fondo sólido o degradado, color de texto y espaciado vertical.')
                                                        ->collapsed()
                                                        ->schema([
                                                            PropertiesSchema::make([
                                                                'background_type',
                                                                'background_color',
                                                                'background_color_secondary',
                                                                'gradient_direction',
                                                                'text_color',
                                                                'padding_y',
                                                            ])->columns(2),
                                                        ]),
                                                ]),
                                        ];

                                        $typeVal = $get('type');
                                        if ($typeVal instanceof \BackedEnum) {
                                            $typeVal = $typeVal->value;
                                        }

                                        // `colophon`/`footer_bottom` (2026-09-02): exclusivos de
                                        // contenidos tipo `Footer` — no tiene sentido un pie de
                                        // página multi-columna ni una barra de copyright dentro de
                                        // una Página/Landing normal. Mismo mecanismo de exclusión que
                                        // ya usa `footer` (nunca en `$footerAllowedBlocks`, ver
                                        // arriba), solo que acá es al revés: SIEMPRE disponibles para
                                        // `Footer`, NUNCA para el resto.
                                        $footerOnlyBlocks = ['colophon', 'footer_bottom'];

                                        if ($typeVal === PageTypeEnum::Footer->value) {
                                            $footerAllowedBlocks = ['image', 'cta', 'features', 'faq', 'contact_form', 'testimonials', 'logos', ...$footerOnlyBlocks];

                                            return array_values(array_filter(
                                                $allBlocks,
                                                fn (Builder\Block $block) => in_array($block->getName(), $footerAllowedBlocks, true)
                                            ));
                                        }

                                        return array_values(array_filter(
                                            $allBlocks,
                                            fn (Builder\Block $block) => ! in_array($block->getName(), $footerOnlyBlocks, true)
                                        ));
                                    })
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
                            // Header y Footer son PARTIALS compartidos, sin URL
                            // pública propia (2026-09-02, pedido en vivo del
                            // Tech Lead: "no necesita SEO/Enlaces, nada de lo
                            // que hay en ese tab") — SEO, Open Graph, enlaces
                            // relacionados y estilo de página no aplican
                            // porque no se indexan ni se comparten como una
                            // página independiente. Mismo criterio ya usado
                            // para ocultar el slug de estos 2 tipos en la
                            // tabla (`in_array($record->type, [Header,
                            // Footer])`, más abajo en este archivo).
                            ->hidden(fn (Get $get): bool => in_array(
                                $get('type'),
                                [PageTypeEnum::Header->value, PageTypeEnum::Footer->value],
                                true
                            ))
                            ->schema([
                                Section::make('Metadata SEO')
                                    ->description('Título, palabras clave y descripción que Google muestra en los resultados de búsqueda.')
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
                                    ])
                                    ->collapsed(),

                                Section::make('Open Graph (Redes Sociales)')
                                    ->description('Título, descripción e imágenes con las que se ve la página al compartirla en redes sociales o chats.')
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
                                    ])
                                    ->collapsed(),

                                Section::make('Enlaces relacionados')
                                    ->description('Botones o enlaces adicionales asociados a esta página (no forman parte del contenido de los bloques).')
                                    ->collapsed()
                                    ->schema([
                                        LinkSchema::make('links', 'Enlaces'),
                                    ]),

                                Section::make('Propiedades de la página')
                                    ->description('Color de fondo, color de texto y animación de entrada de la página.')
                                    ->collapsed()
                                    ->schema([
                                        PropertiesSchema::make(['background_type', 'background_color', 'text_color', 'animation'])
                                            ->columns(2),
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
                // negrita arriba, "Slug: xxx" + badge de tipo debajo, uno al
                // lado del otro. Indentado con "— " por nivel para que el
                // árbol de hasta 3 niveles (página → subpágina →
                // sub-subpágina) se vea de un vistazo.
                Tables\Columns\TextColumn::make('title')
                    ->label('Título')
                    ->searchable(['title', 'slug'])
                    ->weight('bold')
                    ->formatStateUsing(fn (Page $record, string $state): string => str_repeat('— ', $record->depth()).$state)
                    // `Header`/`Footer`: el slug NO se muestra en el listado
                    // para estos 2 tipos (sigue existiendo en la tabla/DB,
                    // sigue siendo único, sigue sirviendo como identificador
                    // interno para el bloque `footer` que lo referencia —
                    // solo se oculta en esta columna, el badge de tipo sigue
                    // mostrándose igual). `Página`/`Landing`/`Legal`
                    // (2026-09-01, pedido del Tech Lead: "usar prefijo
                    // 'Slug: ' para identificar o diferenciarse en algunos
                    // casos del mismo título similar") muestran el slug con
                    // el prefijo explícito.
                    //
                    // El badge de tipo (2026-09-02, primer pedido: "el tipo
                    // de contenido que está al lado del slug tiene que ser
                    // un badge... y sin guion intermedio"; segundo pedido,
                    // sobre el primer intento que lo separó a una columna
                    // aparte en el extremo derecho de la tabla: "los badges
                    // de tipo de contenido tiene que estar a lado del
                    // slug") vive DENTRO de esta misma descripción, no en
                    // una columna aparte — `TextColumn::description()`
                    // acepta un `Htmlable` (Laravel `e()` no escapa un
                    // `Htmlable`, lo renderiza tal cual — mismo mecanismo
                    // que usa Filament internamente para su propio
                    // `->badge()`), así que se arma el MISMO markup que
                    // produce un badge nativo (`typeBadgeHtml()` más abajo,
                    // usando `FilamentColor::getComponentClasses()` con las
                    // clases reales de `Filament\Support\View\Components\
                    // BadgeComponent` — no un `<span>` con estilos
                    // inventados a mano) y se concatena junto al slug.
                    ->description(fn (Page $record): HtmlString => new HtmlString(
                        (in_array($record->type, [PageTypeEnum::Header, PageTypeEnum::Footer], true)
                            ? ''
                            : 'Slug: '.e($record->slug).' ')
                        .self::typeBadgeHtml($record)
                    )),

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
                //
                // Restricción por tipo (2026-09-01, pedido del Tech Lead:
                // "is_home solo puede definirse o seleccionarse uno del tipo
                // página y landing, no puede ser un footer, header o legal
                // is_home=true") — el form (`HeadingFieldset`, `Toggle::make
                // ('is_home')`) ya tenía esta misma restricción vía
                // `->visible()`; a esta columna de la TABLA le faltaba el
                // mismo criterio, así que un Footer/Header/Legal mostraba el
                // check clickeable igual. `IS_HOME_ELIGIBLE_TYPES` centraliza
                // la lista para no repetirla 3 veces (ícono/acción/guard).
                // Sin ícono en absoluto para los tipos no elegibles (en vez
                // de un check gris "apagado pero clickeable", que insinúa
                // una opción válida que no lo es); el guard server-side
                // dentro de `action()` es defensa en profundidad, no solo
                // UI — protege aunque `->disabled()` no alcance a bloquear
                // el request (ej. estado de UI desactualizado).
                Tables\Columns\IconColumn::make('is_home')
                    ->label('Inicio')
                    ->icon(fn (Page $record): ?string => in_array($record->type, self::IS_HOME_ELIGIBLE_TYPES, true)
                        ? 'heroicon-o-check-circle'
                        : null)
                    ->color(fn (Page $record): string => $record->is_home ? 'success' : 'gray')
                    ->tooltip(fn (Page $record): ?string => in_array($record->type, self::IS_HOME_ELIGIBLE_TYPES, true)
                        ? null
                        : 'Solo Página/Landing pueden ser Inicio')
                    ->action(
                        Actions\Action::make('toggleIsHome')
                            ->disabled(fn (Page $record): bool => ! in_array($record->type, self::IS_HOME_ELIGIBLE_TYPES, true))
                            ->action(function (Page $record): void {
                                if (! in_array($record->type, self::IS_HOME_ELIGIBLE_TYPES, true)) {
                                    return;
                                }

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

                // Papelera (2026-09-01, pedido del Tech Lead: "que permita
                // restaurar o recuperar un contenido de la papelera por si
                // borró accidentalmente") — 3 estados: sin papelereados
                // (default), con papelereados, solo papelereados. Requiere
                // `getEloquentQuery()` sin el scope global de `SoftDeletes`
                // (ver arriba) para poder mostrar los 3 estados de verdad.
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Actions\EditAction::make()
                    ->slideOver()
                    ->modalHeading(fn (Page $record) => 'Editar '.($record->type?->getLabel() ?? 'Contenido')),
                Actions\DeleteAction::make(),
                // Restaurar / borrado permanente por fila — Filament ya trae
                // la visibilidad correcta por default (`RestoreAction` solo
                // aparece si el registro está papelereado, `ForceDeleteAction`
                // idem), no hace falta condicionarla a mano.
                Actions\RestoreAction::make(),
                Actions\ForceDeleteAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                    Actions\RestoreBulkAction::make(),
                    Actions\ForceDeleteBulkAction::make(),
                ]),
            ])
            ->headerActions([
                // "Vaciar papelera" (2026-09-01, pedido explícito del Tech
                // Lead: "permita forzar borrado permanente o vaciar
                // basurero, donde borrará todos los soft-delete en total
                // para mantener limpia la tabla manualmente") — a
                // diferencia de `ForceDeleteBulkAction` (borra solo lo
                // seleccionado en pantalla), esta acción borra TODOS los
                // registros papelereados del tenant de una vez, sin
                // necesitar seleccionarlos uno por uno primero. Solo
                // visible si hay al menos 1 papelereado, para no invitar a
                // un clic sin efecto.
                Actions\Action::make('emptyTrash')
                    ->label('Vaciar papelera')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Vaciar papelera')
                    ->modalDescription('Esto borra PERMANENTEMENTE todo el contenido papelereado (páginas, legales, headers y footers). No se puede deshacer.')
                    ->modalSubmitActionLabel('Vaciar papelera')
                    ->visible(fn (): bool => Page::onlyTrashed()->exists())
                    ->action(function (): void {
                        Page::onlyTrashed()->forceDelete();
                    }),
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
            // Botones del estado vacío (2026-09-01, fix real: "tanto legales
            // como secciones no abre o no funciona el botón" — reportado
            // por el Tech Lead). Causa: la versión anterior era UNA sola
            // `Action` genérica que hacía `$livewire->mountAction($actionName)`
            // para "montar" una acción de OTRA clase (`ManagePages::
            // getHeaderActions()`) por nombre — un patrón indirecto y
            // frágil (depende de timing/cacheo de acciones entre Livewire
            // components) que fallaba en silencio: `mountAction()` no
            // encuentra la acción → no hace nada, sin error visible, "no
            // abre y no tira nada". Fix: 3 `CreateAction` propios y
            // autocontenidos (mismo patrón, MISMA config, que ya usan los
            // botones del dropdown "Crear Contenido" de `ManagePages.php`
            // — model/form/fillForm/mutateFormDataUsing/slideOver), cada
            // uno con su propio `->visible()` según la tab activa, así el
            // botón que se ve YA ES la acción que crea el registro — sin
            // ninguna acción "puente" en el medio.
            ->emptyStateActions([
                Actions\CreateAction::make('create_from_empty_state_page')
                    ->label('Crear Página')
                    ->icon('heroicon-o-document-text')
                    ->model(Page::class)
                    ->form(fn (Schema $schema) => static::form($schema))
                    // `lang_iso`/`status` explícitos (2026-09-01, fix real:
                    // `SQLSTATE[23502] ... column "lang_iso"` al guardar —
                    // los `Hidden`/`Select` con `->default(...)` de
                    // `PageResource::form()` no sobreviven de forma
                    // confiable el `->fillForm()` de un `CreateAction`,
                    // mismo motivo por el que `type` ya se forzaba acá.
                    // Detalle completo en `ManagePages::getHeaderActions()`.
                    ->fillForm(['type' => PageTypeEnum::Page->value, 'lang_iso' => LanguageEnum::Spanish->value, 'status' => PublishStatusEnum::Draft->value])
                    ->mutateFormDataUsing(fn (array $data): array => array_merge($data, ['type' => PageTypeEnum::Page->value, 'lang_iso' => $data['lang_iso'] ?? LanguageEnum::Spanish->value, 'status' => $data['status'] ?? PublishStatusEnum::Draft->value]))
                    ->slideOver()
                    ->visible(fn ($livewire): bool => ($livewire->activeTab ?? 'paginas') === 'paginas'),

                Actions\CreateAction::make('create_from_empty_state_legal')
                    ->label('Crear Aviso Legal')
                    ->icon('heroicon-o-shield-check')
                    ->model(Page::class)
                    ->form(fn (Schema $schema) => static::form($schema))
                    ->fillForm(['type' => PageTypeEnum::Legal->value, 'lang_iso' => LanguageEnum::Spanish->value, 'status' => PublishStatusEnum::Draft->value])
                    ->mutateFormDataUsing(fn (array $data): array => array_merge($data, ['type' => PageTypeEnum::Legal->value, 'lang_iso' => $data['lang_iso'] ?? LanguageEnum::Spanish->value, 'status' => $data['status'] ?? PublishStatusEnum::Draft->value]))
                    ->slideOver()
                    ->visible(fn ($livewire): bool => ($livewire->activeTab ?? 'paginas') === 'legales'),

                // "Secciones" agrupa Header + Footer (ver `getTabs()` en
                // `ManagePages.php`) — el botón de estado vacío crea un
                // Header por default, mismo criterio simplificado que ya
                // tenía la versión anterior (Footer sigue disponible desde
                // el dropdown "Crear Contenido" del header de la página).
                Actions\CreateAction::make('create_from_empty_state_partial')
                    ->label('Crear Sección')
                    ->icon('heroicon-o-squares-2x2')
                    ->model(Page::class)
                    ->form(fn (Schema $schema) => static::form($schema))
                    ->fillForm(['type' => PageTypeEnum::Header->value, 'lang_iso' => LanguageEnum::Spanish->value, 'status' => PublishStatusEnum::Draft->value])
                    ->mutateFormDataUsing(fn (array $data): array => array_merge($data, ['type' => PageTypeEnum::Header->value, 'lang_iso' => $data['lang_iso'] ?? LanguageEnum::Spanish->value, 'status' => $data['status'] ?? PublishStatusEnum::Draft->value]))
                    ->slideOver()
                    ->visible(fn ($livewire): bool => ($livewire->activeTab ?? 'paginas') === 'partials'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManagePages::route('/'),
        ];
    }
}
