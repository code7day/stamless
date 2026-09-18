<?php

namespace App\Filament\Resources;

use App\Enums\CountryEnum;
use App\Enums\PublishStatusEnum;
use App\Filament\Concerns\FormatsUsageBadge;
use App\Filament\Concerns\LocksPublishingForAuthor;
use App\Filament\Resources\ServiceResource\Pages;
use App\Filament\Schemas\HeadingFieldset;
use App\Filament\Schemas\LinkSchema;
use App\Filament\Schemas\MediaUpload;
use App\Filament\Schemas\PropertiesSchema;
use App\Models\Service;
use App\Models\Tenant;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Schmeits\FilamentCharacterCounter\Forms\Components\Textarea as CharacterTextarea;
use Schmeits\FilamentCharacterCounter\Forms\Components\TextInput as CharacterTextInput;

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
    use FormatsUsageBadge;
    use LocksPublishingForAuthor;

    protected static ?string $model = Service::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-briefcase';

    protected static ?string $navigationLabel = 'Servicios';

    protected static ?string $pluralLabel = 'Servicios';

    protected static ?string $modelLabel = 'Servicio';

    protected static ?string $slug = 'services';

    /**
     * Límite de servicios por plan (2026-09-11, pedido del Tech Lead: "para
     * free con 10 servicios y auspicios con 20 servicios") — mismo patrón
     * que `PostResource::isPostLimitReached()`.
     */
    public static function isServiceLimitReached(): bool
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return false;
        }

        $limit = $tenant->maxServices();

        if ($limit === null) {
            return false;
        }

        return Service::where('tenant_id', $tenant->id)->count() >= $limit;
    }

    public static function serviceLimitMessage(): string
    {
        $tenant = Filament::getTenant();
        $limit = $tenant instanceof Tenant ? $tenant->maxServices() : null;

        return "El plan actual permite hasta {$limit} servicios. Para crear uno nuevo, eliminar primero alguno existente.";
    }

    /**
     * 2026-09-13, pedido del Tech Lead: badge "usado/límite" en la opción
     * de menú del sidebar (ver `FormatsUsageBadge`).
     */
    public static function getNavigationBadge(): ?string
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return self::formatUsageBadge(Service::where('tenant_id', $tenant->id)->count(), $tenant->maxServices());
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return self::usageBadgeColor(Service::where('tenant_id', $tenant->id)->count(), $tenant->maxServices());
    }

    /**
     * 2026-09-13, pedido del Tech Lead: "duplicar... facilitar a los
     * usuarios o clientes que puedan replicar paginas o publicaciones o
     * servicios para editarlos con sus properties definidos y asi heredar
     * lo configurado anteriormente" — slug único para el duplicado
     * ("-copia", "-copia-2"... si ya existe). `Service::where(...)` ya
     * queda scopeado al tenant actual por el global scope de `HasTenant`,
     * así que solo hace falta filtrar por `lang_iso` acá.
     */
    private static function duplicateSlug(Service $record): string
    {
        $base = $record->slug.'-copia';
        $candidate = $base;
        $suffix = 2;

        while (Service::where('tenant_id', $record->tenant_id)->where('slug', $candidate)->where('lang_iso', $record->lang_iso)->exists()) {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }

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
                                            ->scopedUnique(
                                                model: Service::class,
                                                column: 'slug',
                                                ignoreRecord: true,
                                                modifyQueryUsing: fn ($query) => $query->where('tenant_id', Filament::getTenant()?->id ?? auth()->user()?->tenant_id),
                                            ),

                                        Forms\Components\Hidden::make('lang_iso')
                                            ->default('es'),

                                        Forms\Components\Select::make('status')
                                            ->label('Estado')
                                            ->required()
                                            ->options(PublishStatusEnum::class)
                                            ->default(PublishStatusEnum::Draft->value)
                                            ->disabled(static::currentUserIsAuthor())
                                            ->dehydrated(),

                                        Forms\Components\DateTimePicker::make('published_at')
                                            ->label('Fecha de publicación')
                                            ->nullable()
                                            ->disabled(static::currentUserIsAuthor())
                                            ->dehydrated(),

                                        Forms\Components\TextInput::make('sort_order')
                                            ->label('Orden')
                                            ->helperText('Orden de la card dentro del catálogo de Servicios. Menor = primero.')
                                            ->numeric()
                                            ->default(0)
                                            ->required(),
                                    ]),

                                // 2026-09-14, pedido del Tech Lead: "no solo tenga una imagen si
                                // no dos, una normal como la que ya tiene y otra nueva mas
                                // panoramica como para el detalle... aplicar UX para explicar el
                                // uso que le puedan dar de forma general los usuarios".
                                //
                                // Copy en español neutro, sin voseo ni jerga regional — ADR-051
                                // ("Tono de copy: Stamless en español neutro; voseo EXCLUSIVO del
                                // contenido de marca de CICA360"). Esto es Console/Studio: lo ve
                                // cualquier administrador de cualquier tenant, no solo CICA360 —
                                // nunca "subí"/"cargás"/"tenés" acá, siempre infinitivo o 3ra
                                // persona. Explicación en 1 solo nivel (no repetida entre la
                                // `description()` y cada `helperText`): la `description()` cubre
                                // la relación entre las 2 imágenes Y el fallback; cada
                                // `helperText` solo agrega el dato puntual de ESE campo (dónde se
                                // usa / formato recomendado), sin reafirmar el fallback de nuevo.
                                Section::make('Imágenes del servicio')
                                    ->description('La imagen principal es la miniatura del catálogo. La secundaria es opcional, pensada para el header del detalle — si no se carga, el header usa la principal.')
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                MediaUpload::make(
                                                    'image_id',
                                                    'Imagen principal',
                                                    helperText: 'Miniatura del catálogo de Servicios.',
                                                ),

                                                MediaUpload::make(
                                                    'image_detail_id',
                                                    'Imagen secundaria (opcional)',
                                                    helperText: 'Formato panorámico recomendado para el header del detalle.',
                                                ),
                                            ]),
                                    ]),

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
                                    ->columnSpanFull()
                                    ->afterStateHydrated(fn (Forms\Components\Select $component, $state) => $component->state(Service::sanitizeCountries($state)))
                                    ->dehydrateStateUsing(fn ($state) => Service::sanitizeCountries($state)),

                                Section::make('Pie de página (Footer)')
                                    ->description('Selecciona el Content tipo "Footer" que se renderizará en el detalle de este servicio.')
                                    ->schema([
                                        PropertiesSchema::make(['footer_page_id']),
                                    ]),
                            ]),

                        Tabs\Tab::make('Contenido')
                            ->schema([
                                CharacterTextarea::make('content.intro')
                                    ->label('Párrafo introductorio')
                                    ->helperText('El texto que va debajo del banner, antes de las tabs "¿Qué ofrecemos?"/"Coberturas".')
                                    ->rows(4)
                                    ->required()
                                    ->maxLength(500)
                                    ->characterLimit(300)
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

                                                // 2026-09-14, pedido del Tech Lead (captura: "Porque
                                                // **integramos en un solo equipo**..." con negrita a
                                                // mitad de frase): `Textarea` → `RichEditor`. NO se
                                                // agregan `properties` de color de fondo/redondez a
                                                // este nivel (pedido explícito: "tal vez no sea
                                                // necesario las properties a ese nivel"), solo el
                                                // soporte de texto enriquecido.
                                                //
                                                // `Service::$casts()['content'] = 'array'` (jsonb) →
                                                // Filament guarda este campo como documento TipTap/
                                                // ProseMirror (JSON), mismo comportamiento ya
                                                // documentado para `content.body` en `PageResource.php`
                                                // (ver `ResolvesPublicLinks::renderRichContent()`) — el
                                                // API lo convierte a HTML sanitizado antes de responder
                                                // (`ServiceController::show()`), así que el front
                                                // (`[slug].astro` en cica360) necesita `set:html` para
                                                // este campo en vez de interpolación de texto plano.
                                                Forms\Components\RichEditor::make('content.why_choose_us.text')
                                                    ->label('Texto')
                                                    ->columnSpanFull(),
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
                                                CharacterTextInput::make('meta.seo_title')
                                                    ->label('Título SEO')
                                                    ->maxLength(255)
                                                    ->characterLimit(60),

                                                Forms\Components\TextInput::make('meta.seo_keywords')
                                                    ->label('Palabras Clave (Separadas por comas)')
                                                    ->maxLength(255),

                                                CharacterTextarea::make('meta.seo_description')
                                                    ->label('Descripción SEO')
                                                    ->maxLength(500)
                                                    ->characterLimit(160)
                                                    ->columnSpanFull(),
                                            ]),
                                    ]),

                                Section::make('Open Graph (Redes Sociales)')
                                    ->description('Título, descripción e imágenes con las que se ve el servicio al compartirlo en redes sociales o chats.')
                                    ->collapsed()
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                CharacterTextInput::make('meta.og_title')
                                                    ->label('Título OG')
                                                    ->maxLength(255)
                                                    ->characterLimit(60),

                                                CharacterTextarea::make('meta.og_description')
                                                    ->label('Descripción OG')
                                                    ->maxLength(500)
                                                    ->characterLimit(160),

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
                                        // 2026-09-13, pedido del Tech Lead: "recuerda todas las
                                        // properties a dos columnas o 3" — mismo `->columns(2)`
                                        // que ya tiene cada `PropertiesSchema::make()` de
                                        // `PageResource.php`, esta se había quedado sin.
                                        PropertiesSchema::make(['background_type', 'background_color', 'background_color_secondary', 'gradient_direction', 'text_color'])
                                            ->columns(2),
                                    ]),

                                Section::make('Header del detalle')
                                    ->description('Tamaño del banner superior (imagen + degradado + wave + banderas) que se ve al entrar al detalle de este servicio.')
                                    ->collapsed()
                                    ->schema([
                                        PropertiesSchema::make(['header_type', 'show_decorative_detail'])
                                            ->columns(2),
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
                // 2026-09-13, pedido del Tech Lead: circular (antes
                // cuadrada) — mismo criterio visual que `TestimonialResource`.
                //
                // 2026-09-18, fix producción: en el listado real el thumbnail
                // salía SIEMPRE roto (src="" vacío, ni siquiera intentaba una
                // URL) pese a que el mismo `image_id` resuelve bien en el
                // formulario de edición (`MediaUpload::getUploadedFileUsing()`,
                // que busca el `Media` directo por id). El `disk()` de abajo
                // SÍ podía leer `$record->image` (recibe `$record` completo),
                // pero el ESTADO de la columna (`'image.path'`, resuelto por
                // Filament vía notación de puntos / `data_get()`) llegaba
                // null — una discrepancia entre cómo Filament arma el
                // "state" de la columna y cómo el closure de `disk()` accede
                // a la misma relación. `->getStateUsing()` explícito saca
                // esa ambigüedad: la ruta del archivo se lee de la MISMA
                // forma directa (`$record->image?->path`) que ya usaba
                // `disk()` para el disco, sin depender de la resolución
                // automática de relaciones por dot-notation de `ImageColumn`.
                // 2026-09-18, 2do fix producción (el mismo día, mismo hilo):
                // el `getStateUsing()` de arriba resolvió el estado (antes
                // llegaba null) pero el thumbnail SEGUÍA sin mostrarse en
                // producción (R2) incluso con el estado ya poblado. Causa
                // real, en el propio código fuente de Filament
                // (`ImageColumn::getImageUrl()`): antes de construir la URL,
                // llama `$storage->exists($state)` — un `HeadObject` extra
                // contra R2 por cada fila del listado — y si esa llamada
                // devuelve `false` O tira `UnableToCheckFileExistence`
                // (credenciales/permiso limitado del token R2, latencia,
                // etc.), el método corta y devuelve `null`, mismo resultado
                // visual (`src=""`) que el bug anterior. `MediaUpload::
                // previewUrl()` (el mecanismo del formulario de edición, que
                // SIEMPRE funcionó) nunca hace este chequeo — arma la URL
                // directo desde `Storage::disk()->url()`, confiando en que
                // el registro `Media` en base de datos representa un
                // archivo real. `->checkFileExistence(false)` alinea el
                // listado con ese mismo criterio ya usado en el resto de la
                // app (evita además un HEAD extra a R2 por cada fila visible
                // del listado, más rápido de por sí).
                // 2026-09-18, 3er fix real, mismo hilo: con el estado y el
                // `exists()` ya resueltos, el `src` seguía roto — mostraba
                // una URL FIRMADA de R2 (`*.r2.cloudflarestorage.com/...
                // ?X-Amz-Signature=...`), no la URL pública simple que ya
                // funciona (`media.stamless.com/...`, confirmada por
                // tinker). Causa: `ImageColumn::getVisibility()` infiere
                // `'private'` cuando el disco NO se llama literalmente
                // `'public'` (acá era `'r2'`) — dispara `$storage->
                // temporaryUrl()` en vez de `$storage->url()`.
                //
                // 2026-09-18, 4to fix (el de fondo): en vez de parchear cada
                // columna con `->visibility('public')`, se corrigió en la
                // raíz — `config/filesystems.php` ahora resuelve el disco
                // `'public'` a local o R2 según `FILESYSTEM_DISK` (mismo
                // nombre de disco en cualquier ambiente), y una migración de
                // datos (`2026_09_18_080000_normalize_media_disk_to_public`)
                // reescribió los registros `Media` viejos de `disk='r2'` a
                // `disk='public'`. Con el disco SIEMPRE llamado `'public'`,
                // Filament infiere la visibilidad correcta solo, sin
                // overrides por columna — por eso ya no hace falta
                // `->visibility('public')` acá.
                Tables\Columns\ImageColumn::make('image.path')
                    ->label('')
                    ->getStateUsing(fn ($record) => $record?->image?->path)
                    ->disk(fn ($record) => $record?->image?->disk?->value ?? 'public')
                    ->checkFileExistence(false)
                    ->circular(),

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
                // 2026-09-13 (ADR-059, addendum): acciones de fila agrupadas
                // en un menú desplegable (mismo patrón que `ApiTokens::
                // table()`).
                Actions\ActionGroup::make([
                    // 2026-09-14, pedido del Tech Lead (UX): "al editar
                    // debería mostrarse el título del servicio, ej.
                    // 'Editar Servicio: Seguro Financiero'" — antes quedaba
                    // en el genérico "Editar Servicio" sin importar cuál se
                    // estuviera editando. Mismo criterio que ya usa
                    // `ReplicateAction` de acá abajo (`->modalHeading()`
                    // con el título del record).
                    Actions\EditAction::make()
                        ->slideOver()
                        ->modalWidth('3xl')
                        ->modalHeading(fn (Service $record): string => "Editar Servicio: {$record->title}"),
                    // 2026-09-13, pedido del Tech Lead: "duplicar... para
                    // editarlos con sus properties definidos y asi heredar
                    // lo configurado anteriormente" — mismo patrón que
                    // `PostResource`/`PageResource`.
                    Actions\ReplicateAction::make()
                        ->label('Duplicar')
                        // 2026-09-13 (ADR-061, addendum UX): mismo criterio
                        // que PageResource/PostResource — modal propio en vez
                        // del genérico "Replicar :label" de Filament.
                        ->modalHeading(fn (Service $record): string => "¿Duplicar \"{$record->title}\"?")
                        ->modalDescription('Se creará una copia con la misma configuración, guardada como borrador para que puedas editarla antes de publicarla.')
                        ->modalSubmitActionLabel('Sí, duplicar')
                        ->modalFooterActionsAlignment('center')
                        ->excludeAttributes(['uuid', 'slug', 'status', 'published_at'])
                        ->beforeReplicaSaved(function (Service $record, Service $replica): void {
                            $replica->title = "{$record->title} (copia)";
                            $replica->slug = self::duplicateSlug($record);
                            $replica->status = PublishStatusEnum::Draft;
                            $replica->published_at = null;
                        })
                        ->disabled(fn (): bool => self::isServiceLimitReached())
                        ->tooltip(fn (): ?string => self::isServiceLimitReached() ? self::serviceLimitMessage() : null)
                        ->before(function (Actions\ReplicateAction $action) {
                            if (! self::isServiceLimitReached()) {
                                return;
                            }

                            Notification::make()->danger()->title('Límite del plan alcanzado')->body(self::serviceLimitMessage())->send();
                            $action->halt();
                        })
                        ->successNotificationTitle('Servicio duplicado'),
                    Actions\DeleteAction::make(),
                ]),
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
                    ->modalWidth('3xl')
                    ->disabled(fn (): bool => self::isServiceLimitReached())
                    ->tooltip(fn (): ?string => self::isServiceLimitReached() ? self::serviceLimitMessage() : null)
                    ->before(function (Actions\CreateAction $action) {
                        if (! self::isServiceLimitReached()) {
                            return;
                        }

                        Notification::make()->danger()->title('Límite del plan alcanzado')->body(self::serviceLimitMessage())->send();
                        $action->halt();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageServices::route('/'),
        ];
    }
}
