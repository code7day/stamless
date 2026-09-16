<?php

namespace App\Filament\Resources;

use App\Enums\MediaDiskEnum;
use App\Filament\Concerns\FormatsUsageBadge;
use App\Filament\Resources\MediaResource\Pages;
use App\Models\Media;
use App\Models\Tenant;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class MediaResource extends Resource
{
    use FormatsUsageBadge;

    protected static ?string $model = Media::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationLabel = 'Multimedia';

    protected static ?string $pluralLabel = 'Archivos Multimedia';

    protected static ?string $modelLabel = 'Archivo Multimedia';

    protected static ?string $slug = 'media';

    /**
     * Límite de archivos multimedia por plan (2026-09-11, pedido del Tech
     * Lead: "pra free con 40 multimedia y para asupicio 60 multimedia").
     */
    public static function isMediaLimitReached(): bool
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return false;
        }

        $limit = $tenant->maxMedia();

        if ($limit === null) {
            return false;
        }

        return Media::where('tenant_id', $tenant->id)->count() >= $limit;
    }

    /**
     * 2026-09-14, ADR-067: el mensaje original decía "eliminar primero
     * alguno existente" — ya NO es verdad para Free/Auspicio, que a partir
     * de esta vuelta perdieron el acceso a esta misma página (`canAccess()`
     * más abajo), su único lugar para borrar archivos. Se bifurca el texto
     * según `canAccessMediaLibrary()`: quien SÍ tiene acceso (plan pago)
     * sigue viendo la instrucción real ("borrá uno"); quien NO tiene acceso
     * ve un mensaje honesto sobre el único camino que le queda (mejorar de
     * plan) en vez de una instrucción que no puede seguir. Este método
     * también lo usa `MediaUpload::make()` para el campo de subida inline.
     */
    public static function mediaLimitMessage(): string
    {
        $tenant = Filament::getTenant();
        $limit = $tenant instanceof Tenant ? $tenant->maxMedia() : null;

        if ($tenant instanceof Tenant && ! $tenant->canAccessMediaLibrary()) {
            return "El plan actual permite hasta {$limit} archivos multimedia. Para subir uno nuevo, mejorá de plan.";
        }

        return "El plan actual permite hasta {$limit} archivos multimedia. Para subir uno nuevo, eliminar primero alguno existente.";
    }

    /**
     * 2026-09-14, ADR-067: "creo que multimedia lo ocultaremos para free y
     * aspicios" (motivado por `App\Filament\Schemas\MediaUpload`, que hoy no
     * permite REUTILIZAR un archivo ya subido desde otro campo — sin eso, la
     * galería completa no aporta valor real a esos 2 planes). `canAccess()`
     * es el gate "de arriba" de Filament: lo usa tanto `HasNavigation` (saca
     * el ítem del sidebar) COMO el `abort_unless(..., 403)` que corre al
     * entrar a CUALQUIER página de este Resource — bloquea navegación Y URL
     * directa con el mismo método, sin tener que tocar policies. El límite
     * de `maxMedia()` NO desaparece para estos planes: sigue contándose y
     * mostrándose en `PlanUsageWidget` (Escritorio) y se sigue haciendo
     * cumplir en la subida inline (`MediaUpload::make()`) — lo único que
     * cambia es que ya no hay una página propia para administrar la
     * biblioteca completa.
     */
    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return parent::canAccess()
            && $tenant instanceof Tenant
            && $tenant->canAccessMediaLibrary();
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

        return self::formatUsageBadge(Media::where('tenant_id', $tenant->id)->count(), $tenant->maxMedia());
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return self::usageBadgeColor(Media::where('tenant_id', $tenant->id)->count(), $tenant->maxMedia());
    }

    /**
     * 2026-09-14, pedido del Tech Lead con 2 puntos:
     *
     * 1. "en cada preview del upload hay un ícono para editar la foto... en
     *    multimedia no lo tiene al editar": el resto de los Resources
     *    (Páginas, Posts, Sliders) arman su `FileUpload` vía
     *    `App\Filament\Schemas\MediaUpload::make()`, que SÍ encadena
     *    `->image()->imageEditor()` (ver ese archivo) — el de acá se armaba
     *    a mano y nunca sumó `imageEditor()`. Se agrega solo `imageEditor()`
     *    (sin `->image()`): a diferencia de esos campos, este `FileUpload`
     *    ES la Biblioteca de Medios completa — acepta imagen Y video/otros
     *    tipos (`mime_type` se detecta recién al subir, no hay
     *    `acceptedFileTypes()` explícito) — `->image()` restringiría los
     *    tipos aceptados solo a imágenes y rompería la carga de video.
     *    `imageEditor()` no exige `->image()` (confirmado en el código
     *    fuente del paquete: son 2 flags independientes) — el ícono de
     *    lápiz/recorte aparece cuando el archivo cargado es una imagen real,
     *    sin afectar el resto de los tipos.
     * 2. "falta ajustar el UX... se ve muy compacto cuando tiene espacio en
     *    el body del modal": la 1ra corrección metió un `Grid` DENTRO de la
     *    `Section` (`Section > Grid > [FileUpload, Group]`) — el Tech Lead
     *    mandó captura + inspector mostrando que la `Section` seguía sin
     *    usar el ancho real del modal (`slideOver`, `modalWidth('2xl')`).
     *    Mismo bug de fondo YA documentado y resuelto una vez en este mismo
     *    proyecto (`TestimonialResource::form()`, 2026-08-31): grids de
     *    Filament anidados (`Section` → `Grid`/`Group` → campos) pueden
     *    colapsar a un ancho "shrink-to-fit" en vez de estirarse al 100%
     *    del contenedor — acá se repitió el mismo error en vez de reusar la
     *    solución ya probada. Fix (idéntico patrón a `TestimonialResource`):
     *    se aplana todo — los campos son hijos DIRECTOS de la única
     *    `Section`, que usa su PROPIO `->columns(2)` (sin `Grid` ni `Group`
     *    intermedios) y cada campo controla su posición con `columnSpan()`/
     *    `columnSpanFull()`. `->extraAttributes(['class' => 'w-full'])` en
     *    la Section + `->columnSpanFull()` en la Section misma, cinturón y
     *    tirantes contra el mismo colapso de ancho.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(2)
                    ->extraAttributes(['class' => 'w-full'])
                    ->schema([
                        Forms\Components\FileUpload::make('path')
                            ->label('Archivo')
                            ->required()
                            ->disk(fn () => config('filesystems.default') === 'local' ? 'public' : config('filesystems.default', 'public'))
                            ->directory('media')
                            ->visibility('public')
                            ->acceptedFileTypes([
                                'image/jpeg',
                                'image/png',
                                'image/webp',
                                'image/gif',
                                'image/svg+xml',
                                'video/mp4',
                                'video/webm',
                                'video/quicktime',
                                'application/pdf',
                            ])
                            ->maxSize(51200)
                            ->imageEditor()
                            ->storeFileNamesIn('file_name')
                            ->getUploadedFileNameForStorageUsing(function (TemporaryUploadedFile $file) {
                                $tenantSlug = Filament::getTenant()?->slug ?? 'global';
                                $datetime = now()->format('YmdHis');
                                $extension = $file->getClientOriginalExtension();

                                return "{$tenantSlug}_media_{$datetime}.{$extension}";
                            })
                            ->afterStateUpdated(function ($state, Set $set) {
                                if ($state) {
                                    $filePath = is_array($state) ? reset($state) : $state;
                                    $set('name', pathinfo($filePath, PATHINFO_FILENAME));

                                    $diskName = config('filesystems.default') === 'local' ? 'public' : config('filesystems.default', 'public');
                                    $set('disk', $diskName);

                                    $disk = \Storage::disk($diskName);
                                    if ($disk->exists($filePath)) {
                                        $set('mime_type', $disk->mimeType($filePath));
                                        $set('size', $disk->size($filePath));
                                    }
                                }
                            })
                            ->live()
                            ->columnSpan(1),

                        Forms\Components\TextInput::make('name')
                            ->label('Nombre descriptivo')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(1),

                        Forms\Components\TextInput::make('alt_text')
                            ->label('Texto alternativo (Alt SEO)')
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Forms\Components\Hidden::make('disk')
                            ->default(fn () => config('filesystems.default') === 'local' ? 'public' : config('filesystems.default', 'public')),

                        Forms\Components\Hidden::make('file_name'),
                        Forms\Components\Hidden::make('mime_type'),
                        Forms\Components\Hidden::make('size'),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * 2026-09-13, pedido del Tech Lead: "no me convence un simple listado
     * CRUD, cuando debería ser una galería más visual, más UX". Se evaluaron
     * 4 plugins de terceros de la comunidad Filament (UniFileManager,
     * Ardavan File Explorer, mwguerra/filemanager, marcomessa/filament-
     * file-manager) — los 4 descartados: cada uno propone su PROPIO
     * inventario de archivos (tabla propia, o directamente exigen migrar a
     * Spatie Media Library), nunca leen/escriben sobre esta tabla `media` —
     * adoptar cualquiera hubiera significado mantener 2 sistemas de media en
     * paralelo, o replatear cada FK `media_id` que ya usan Páginas/Posts/
     * Servicios/Slides/SEO-OG (ADR-065). Se opta por `Table::contentGrid()`,
     * NATIVO de Filament (sin dependencias nuevas): mismo modelo `Media`,
     * mismo multi-tenancy, mismas relaciones — solo cambia cómo se renderiza
     * el índice (tarjetas con preview en vez de filas).
     *
     * 2026-09-13, 2da vuelta (fix real, reporte con captura: "se ve
     * horrible... mejor más pequeño... ocupa menos espacio"): la 1ra vuelta
     * armó `contentGrid()` pero dejó las 3 columnas (imagen/nombre/tamaño)
     * SUELTAS — sin agruparlas, Filament las sigue acomodando en fila
     * horizontal DENTRO de cada celda de la grilla (el layout por defecto de
     * cualquier `Table`), no apiladas como una tarjeta vertical — de ahí que
     * se viera como una fila angosta gigante en vez de una foto con texto
     * debajo. Fix: las 3 columnas se envuelven en `Tables\Columns\Layout\
     * Stack::make([...])`, el componente de Filament pensado exactamente
     * para esto — agrupa columnas y las apila verticalmente dentro de la
     * misma celda de `contentGrid()`, que es como se arma un layout de
     * tarjetas reales en Filament (no hay una opción "modo card" separada,
     * es `contentGrid()` + `Stack` juntos). Miniatura además baja de 160 a
     * 120px de alto y usa `->square()` (antes solo `height()`, sin forzar
     * proporción — con imágenes panorámicas tipo 1200×630 el alto fijo sin
     * `square()` las dejaba angostas y elongadas).
     */
    public static function table(Table $table): Table
    {
        return $table
            // 2026-09-13, 3ra vuelta (pedido del Tech Lead: "de 4 columnas
            // tal vez", tras ver la grilla ya funcionando pero muy apretada
            // en 5-6 columnas): se limita a 4 desde `md` en adelante — con
            // el container ahora fullwidth (`ManageMedia::getMaxContentWidth()`)
            // cada tarjeta gana bastante más aire que antes.
            //
            // 2026-09-14, 9na vuelta (pedido explícito de breakpoints): "en
            // tablet 1 columna, a partir de 1024px 3 columnas, a partir de
            // 1536px 4 columnas, a partir de 1920px 5 columnas". `1024` y
            // `1536` son, literalmente, los breakpoints `lg` y `2xl` de
            // Tailwind — se usan tal cual. `1920` NO es un breakpoint nativo
            // de Tailwind ni de `contentGrid()` (los que trae Filament de
            // fábrica llegan hasta `2xl` = 1536px, ver `vendor/filament/
            // support/resources/css/components/grid.css`): se define una
            // clave custom `uw` ("ultra-wide") — `contentGrid()` la traduce
            // igual que a cualquier otra clave (clase `uw:fi-grid-cols` +
            // variable CSS `--cols-uw`, mismo mecanismo genérico), pero como
            // `uw` no es un breakpoint real no hay ningún `@media` que la
            // dispare — se agrega a mano en `resources/css/filament/cms/
            // theme.css`.
            //
            // 2026-09-14, 10ma vuelta ("forzar a partir de 620px debería ser
            // 2 columnas y de 1024px sigue normal lo que ya se configuró"):
            // 620px TAMPOCO es un breakpoint nativo de Tailwind (el más
            // cercano, `sm`, es 640px — pedido explícito de 620, no 640, así
            // que no alcanza con reusar `sm`) — mismo tratamiento que `uw`:
            // clave custom `w620`, `@media` a mano en el theme.
            //
            // 2026-09-14, 12va vuelta ("a partir de 1080px que sea de 3", en
            // vez de 1024): 1080 tampoco es un breakpoint nativo — se
            // reemplaza la clave `lg` (1024, nativa) por otra clave custom
            // `w1080`, mismo tratamiento que `w620`/`uw`. La franja de 2
            // columnas (`w620`) ahora llega hasta justo antes de 1080 (no
            // 1024) — ver el rango exacto en el `@media` de `theme.css`.
            ->contentGrid([
                'default' => 1,
                'w620' => 2,
                'w1080' => 3,
                '2xl' => 4,
                'uw' => 5,
            ])
            // 2026-09-13, 4ta vuelta: orden por defecto pasa a "últimos
            // modificados primero" (`updated_at desc`, antes sin ningún
            // `defaultSort()` — caía al orden implícito de la PK/insert). El
            // filtro de fecha de abajo sigue siendo por `created_at` a
            // propósito: "modificado" y "creado" son 2 preguntas distintas
            // (un archivo viejo puede haberse re-nombrado/editado hoy), no
            // tiene sentido que el filtro sea sobre la misma columna que
            // ordena por default.
            ->defaultSort('updated_at', 'desc')
            ->columns([
                Stack::make([
                    Tables\Columns\ImageColumn::make('path')
                        ->label('')
                        ->disk(fn ($record) => $record->disk?->value ?? 'public')
                        ->square()
                        ->height(190)
                        // "las imágenes centradas" (2026-09-13, 4ta vuelta):
                        // `object-center` explícito (antes confiaba en el
                        // default del navegador para `object-fit: cover`,
                        // que ya centra, pero no quedaba garantizado en
                        // todos los casos) + `mx-auto` por si el ancho real
                        // de la imagen alguna vez queda por debajo del100%
                        // de la celda (100%).
                        // 2026-09-14 (perf, ver también el mensaje de
                        // consola reportado: "Could not establish
                        // connection. Receiving end does not exist." — ESE
                        // error es ruido de una extensión del navegador,
                        // no de este código, ver el reporte al Tech Lead;
                        // pero el lag real en la interfaz sí tiene una
                        // causa de fondo acá: con 50 tarjetas por página
                        // como default y sin `loading="lazy"`, el navegador
                        // pedía las 50 imágenes reales de una sola vez al
                        // cargar la página, aunque casi ninguna esté
                        // visible todavía). `loading="lazy"` es nativo del
                        // navegador (sin JS propio, sin librería nueva) —
                        // difiere la descarga de cada imagen hasta que su
                        // tarjeta esté por entrar al viewport.
                        ->extraImgAttributes(['class' => 'w-full object-cover object-center mx-auto rounded-t-xl', 'loading' => 'lazy']),

                    // 2026-09-13, 3ra vuelta ("las imágenes que se vean más
                    // estéticos como los nombres" — el mime type y el tamaño
                    // quedaban como 2 badges apilados debajo del nombre, se
                    // veía cargado): se funden mime+tamaño en una sola línea
                    // dentro del MISMO `description()` (ya no hay una
                    // columna `size` aparte en el Stack) — nombre centrado y
                    // en negrita, nombre de archivo real + los 2 datos en
                    // una fila con `flex-wrap` debajo, todo centrado y con
                    // padding parejo alrededor (antes solo la imagen tenía
                    // padding propio vía `rounded-t-*`).
                    // 2026-09-14, 6ta vuelta ("mucho gap entre el título y el
                    // nombre del archivo"): Filament ya agrega su propio
                    // espacio entre el texto principal (`name`) y el
                    // `description()` que va debajo; el `mt-1` que se sumaba
                    // acá encima duplicaba ese aire. Se cambia a `-mt-1` para
                    // compensar (no `mt-0`, que seguía dejando más espacio
                    // del necesario) — el `gap-1.5` interno entre nombre de
                    // archivo y badges queda igual, no era el problema.
                    // 2026-09-14, 8va vuelta ("aplicar truncate o no-wrap a
                    // los nombres de archivos, pero mostrar siempre la
                    // extensión"): el `file_name` real se renderizaba en una
                    // sola línea SIN ningún límite de ancho — dentro de un
                    // `flex-col items-center` (que en el eje horizontal
                    // encoge cada hijo a su contenido, no lo estira al 100%
                    // de la tarjeta), un nombre largo se salía de los bordes
                    // de la tarjeta y se metía visualmente sobre la de al
                    // lado (captura real). Un `truncate` de Tailwind puro
                    // (recorta con "…" al FINAL del texto) hubiera ocultado
                    // justo la extensión en nombres largos — lo contrario de
                    // lo pedido. Fix: `file_name` se separa en base+extensión
                    // (`self::splitFileName()`) y se renderizan en 2 `<span>`
                    // distintos dentro de una fila `flex w-full` (el `w-full`
                    // rompe el "encoger al contenido" del `items-center` del
                    // padre): la base lleva `truncate` + `min-w-0` (necesario
                    // para que un hijo flex pueda encogerse por debajo de su
                    // ancho de contenido, si no `truncate` no tiene efecto),
                    // la extensión lleva `shrink-0` — nunca se recorta,
                    // cualquiera sea el ancho real de la tarjeta.
                    Tables\Columns\TextColumn::make('name')
                        ->label('Nombre')
                        ->searchable(['name', 'file_name'])
                        ->sortable()
                        ->weight('bold')
                        ->limit(28)
                        ->alignCenter()
                        ->description(function (Media $record): HtmlString {
                            [$fileNameBase, $fileNameExtension] = self::splitFileName($record->file_name);

                            return new HtmlString(
                                '<div class="-mt-1 flex flex-col items-center gap-1.5">'.
                                '<span class="flex w-full items-center justify-center text-xs text-gray-500 dark:text-gray-400">'.
                                '<span class="min-w-0 truncate">'.e($fileNameBase).'</span>'.
                                '<span class="shrink-0">'.e($fileNameExtension).'</span>'.
                                '</span>'.
                                '<span class="flex items-center gap-1.5">'.
                                '<span class="inline-flex items-center rounded-md px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset '.
                                self::mimeBadgeClasses($record->mime_type).'">'.e($record->mime_type).'</span>'.
                                '<span class="inline-flex items-center rounded-md bg-gray-50 px-1.5 py-0.5 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/10 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/20">'.
                                number_format($record->size / 1024, 2).' KB</span>'.
                                '</span>'.
                                '</div>'
                            );
                        })
                        ->extraAttributes(['class' => 'px-3 pt-2.5 pb-3 text-center']),

                    // Columna "Disco" sacada de la tabla (2026-09-01, pedido del
                    // Tech Lead: "no es relevante") — el filtro de abajo se deja,
                    // sigue sirviendo para acotar la lista aunque el dato ya no
                    // se muestre en cada fila.
                ])
                    ->space(0)
                    // 2026-09-13, 5ta vuelta (fix real, reporte con captura:
                    // el botón "⋮" flotante quedó bien posicionado, pero su
                    // dropdown ("Editar"/"Borrar") abría en la esquina
                    // inferior-izquierda de la pantalla en vez de al lado
                    // del botón — diagnóstico correcto del Tech Lead: "te
                    // faltó por stack el relative"). Causa: el botón de
                    // acciones es `position: absolute` (ver `->actions()`
                    // más abajo), pero nada en el camino tenía `position:
                    // relative` — su "contenedor de posicionamiento" real
                    // terminaba siendo el `<body>`, y el cálculo de
                    // posición del dropdown (que sí depende del ancestro
                    // posicionado más cercano) se rompía. Se agrega
                    // `relative` acá, en el Stack — es el wrapper visual de
                    // CADA tarjeta individual dentro de `contentGrid()`, el
                    // lugar correcto para anclar tanto el botón como su
                    // dropdown a ESA tarjeta puntual (no a la grilla
                    // entera).
                    ->extraAttributes(['class' => 'relative']),
            ])
            // 2026-09-13, 4ta vuelta (pedido explícito: "paginación de 50"):
            // 50 se agrega al set de opciones Y pasa a ser el default (antes
            // 24) — mismo bug de fondo que la vuelta pasada, un valor de
            // `defaultPaginationPageOption()` que no está en
            // `paginationPageOptions()` se ignora en silencio.
            ->paginationPageOptions([12, 24, 50, 100])
            ->defaultPaginationPageOption(50)
            ->filters([
                Tables\Filters\SelectFilter::make('disk')
                    ->label('Disco')
                    ->options(MediaDiskEnum::class),

                // 2026-09-13, 4ta vuelta ("que se pueda filtrar por fecha de
                // creación también"): rango de fechas sobre `created_at`
                // (columna real de la tabla, `timestamps()` en la migración
                // — no confundir con `updated_at`, que es lo que usa el
                // orden por defecto de arriba). Patrón oficial de Filament
                // para filtros de rango de fecha: 2 `DatePicker` propios
                // (`created_from`/`created_until`) en `->schema()`, `query()`
                // aplica cada uno solo si el usuario lo cargó (`->when()`).
                Tables\Filters\Filter::make('created_at')
                    ->label('Fecha de creación')
                    ->schema([
                        Forms\Components\DatePicker::make('created_from')
                            ->label('Desde'),
                        Forms\Components\DatePicker::make('created_until')
                            ->label('Hasta'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['created_from'] ?? null) {
                            $indicators['created_from'] = 'Creado desde '.Carbon::parse($data['created_from'])->translatedFormat('d/m/Y');
                        }

                        if ($data['created_until'] ?? null) {
                            $indicators['created_until'] = 'Creado hasta '.Carbon::parse($data['created_until'])->translatedFormat('d/m/Y');
                        }

                        return $indicators;
                    }),
            ])
            ->actions([
                // 2026-09-13 (ADR-059, addendum): acciones de fila agrupadas
                // en un menú desplegable (mismo patrón que `ApiTokens::
                // table()`).
                //
                // 2026-09-13, 4ta vuelta ("el botón de acciones a la derecha
                // superior flotante"): se posiciona ABSOLUTO sobre la
                // esquina superior derecha de la tarjeta (`top-2 right-2`),
                // con fondo semi-opaco + blur para que se lea encima de
                // CUALQUIER imagen de fondo (clara u oscura) sin agregar un
                // overlay propio a la imagen. Aviso honesto: posicionar
                // acciones así dentro de `contentGrid()` es un punto flojo
                // documentado de Filament (varios reportes de la comunidad
                // con resultados inconsistentes según versión) — no se pudo
                // levantar el panel en este sandbox para confirmarlo
                // pixel-perfect, revisar visualmente y avisar si no cae
                // bien posicionado.
                Actions\ActionGroup::make([
                    // `->modalWidth('md')` (2026-09-02) quedó angosto de más
                    // una vez que el form tiene una imagen real cargada: el
                    // preview del `FileUpload` (nombre + peso + botón de
                    // sacar) se superponía/cortaba dentro de esa caja chica
                    // (2026-09-13, reporte con captura: "al editar es peor,
                    // falta UX, fullwidth al body del modal"). Sube a `2xl`
                    // — sigue siendo un `slideOver`, no un modal centrado, así
                    // que el ancho extra no deja "aire" vacío raro, solo le da
                    // lugar de verdad al preview del archivo.
                    Actions\EditAction::make()
                        ->slideOver()
                        ->modalWidth('2xl'),
                    Actions\DeleteAction::make(),
                ])
                    ->color('gray')
                    ->extraAttributes([
                        'class' => 'absolute top-2 right-2 z-10 rounded-full bg-white/90 shadow-md backdrop-blur-sm dark:bg-gray-900/80',
                    ]),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateActions([
                Actions\CreateAction::make()
                    ->slideOver()
                    ->modalWidth('2xl')
                    ->disabled(fn (): bool => self::isMediaLimitReached())
                    ->tooltip(fn (): ?string => self::isMediaLimitReached() ? self::mediaLimitMessage() : null)
                    ->before(function (Actions\CreateAction $action) {
                        if (! self::isMediaLimitReached()) {
                            return;
                        }

                        Notification::make()->danger()->title('Límite del plan alcanzado')->body(self::mediaLimitMessage())->send();
                        $action->halt();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageMedia::route('/'),
        ];
    }

    /**
     * Clases Tailwind (mismo look que un badge de Filament: `ring-1
     * ring-inset`, fondo suave + texto del mismo tono) según el prefijo del
     * mime type. Usado en la descripción HTML de la columna "Nombre" —
     * ver `table()` arriba.
     */
    private static function mimeBadgeClasses(?string $mimeType): string
    {
        $prefix = explode('/', $mimeType ?? '')[0] ?? '';

        return match ($prefix) {
            'image' => 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-400/10 dark:text-success-400 dark:ring-success-400/20',
            'video' => 'bg-primary-50 text-primary-700 ring-primary-600/20 dark:bg-primary-400/10 dark:text-primary-400 dark:ring-primary-400/20',
            'application' => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400 dark:ring-warning-400/20',
            default => 'bg-gray-50 text-gray-600 ring-gray-500/10 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/20',
        };
    }

    /**
     * Separa un `file_name` real en [base, extensión] — usado en la
     * columna "Nombre" (ver `table()` arriba) para poder aplicar
     * `truncate` SOLO a la base vía CSS y dejar la extensión en un
     * `<span>` aparte que nunca se recorta (2026-09-14, 8va vuelta).
     *
     * @return array{0: string, 1: string} [base, extensión CON el punto — cadena vacía si el archivo no tiene extensión]
     */
    private static function splitFileName(?string $fileName): array
    {
        $fileName ??= '';
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);

        if ($extension === '') {
            return [$fileName, ''];
        }

        return [substr($fileName, 0, -(strlen($extension) + 1)), '.'.$extension];
    }
}
