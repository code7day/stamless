<?php

namespace App\Filament\Resources;

use App\Enums\LanguageEnum;
use App\Enums\PageTypeEnum;
use App\Enums\PublishStatusEnum;
use App\Enums\SocialPlatformEnum;
use App\Filament\Concerns\FormatsUsageBadge;
use App\Filament\Concerns\LocksPublishingForAuthor;
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
use App\Models\Tenant;
use App\Support\FriendlyDate;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Components\Builder;
use Filament\Notifications\Notification;
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
use Schmeits\FilamentCharacterCounter\Forms\Components\Textarea as CharacterTextarea;
use Schmeits\FilamentCharacterCounter\Forms\Components\TextInput as CharacterTextInput;

class PageResource extends Resource
{
    use FormatsUsageBadge;
    use LocksPublishingForAuthor;

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
            PageTypeEnum::Footer => 'gray',
            default => 'gray',
        };

        $label = e($record->type?->getLabel() ?? '—');
        $classes = implode(' ', FilamentColor::getComponentClasses(BadgeComponent::class, $color));

        return '<span class="fi-badge fi-size-sm '.$classes.'">'.$label.'</span>';
    }

    /**
     * ¿Este tenant ya llegó al tope de contenidos ACTIVOS (no
     * soft-deleted) de `$type` que permite su plan? (2026-09-11, ver
     * `Tenant::maxContentsPerType()`.) `false` si no hay tenant resuelto o
     * si el plan no tiene límite — nunca bloquea por accidente fuera de un
     * contexto de tenant real.
     */
    public static function isContentLimitReached(PageTypeEnum $type): bool
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return false;
        }

        $limit = $tenant->maxContentsPerType($type);

        if ($limit === null) {
            return false;
        }

        return Page::where('tenant_id', $tenant->id)
            ->where('type', $type->value)
            ->count() >= $limit;
    }

    /**
     * Copy neutro (ADR-051) para el tooltip del botón deshabilitado y la
     * notificación de la red de seguridad server-side (`->before()`) — se
     * usa en ambos lugares para no duplicar el texto.
     */
    public static function contentLimitMessage(PageTypeEnum $type): string
    {
        $tenant = Filament::getTenant();
        $limit = $tenant instanceof Tenant ? $tenant->maxContentsPerType($type) : null;

        return "El plan actual permite hasta {$limit} contenidos de tipo \"{$type->getLabel()}\". Para crear uno nuevo, eliminar primero alguno existente de este mismo tipo.";
    }

    /**
     * Tipos que participan del badge de uso del sidebar y de las tabs de
     * `ManagePages::getTabs()` (Páginas/Legales/Secciones) — 2026-09-13,
     * pedido del Tech Lead. `Landing` queda afuera a propósito: no tiene
     * acción de "Crear" habilitada todavía (comentada para MVP en
     * `ManagePages::getHeaderActions()`), así que siempre está en 0 y
     * sumarla solo infla el denominador sin aportar información real.
     *
     * @var array<int, PageTypeEnum>
     */
    private const NAV_BADGE_TYPES = [PageTypeEnum::Page, PageTypeEnum::Legal, PageTypeEnum::Footer];

    /**
     * Suma de conteo y límite de los 3 tipos de `NAV_BADGE_TYPES`, para el
     * badge agregado del sidebar. Desde 2026-09-13, `maxContentsPerType()`
     * devuelve un número DISTINTO por tipo (ver su docblock) — ya no se
     * puede multiplicar "un límite" × 3, hay que sumar el límite propio de
     * cada tipo. Si CUALQUIER tipo queda sin límite (`null`), el agregado
     * completo se trata como sin límite (no tiene sentido mostrar una
     * fracción "usado/límite" a medias).
     *
     * 2026-09-13: pública (no privada) desde que `PlanUsageWidget` del
     * Dashboard también la necesita para su barra "Contenidos" — mismo
     * cálculo exacto que el badge del sidebar, sin duplicar la suma.
     *
     * @return array{count: int, limit: ?int}
     */
    public static function navBadgeUsage(Tenant $tenant): array
    {
        $count = 0;
        $limit = 0;

        foreach (self::NAV_BADGE_TYPES as $type) {
            $count += Page::where('tenant_id', $tenant->id)->where('type', $type->value)->count();

            $typeLimit = $tenant->maxContentsPerType($type);

            if ($typeLimit === null) {
                $limit = null;

                continue;
            }

            if ($limit !== null) {
                $limit += $typeLimit;
            }
        }

        return ['count' => $count, 'limit' => $limit];
    }

    /**
     * Badge "usado/límite" del sidebar (ver `FormatsUsageBadge` y
     * `navBadgeUsage()`).
     */
    public static function getNavigationBadge(): ?string
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        $usage = self::navBadgeUsage($tenant);

        return self::formatUsageBadge($usage['count'], $usage['limit']);
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        $usage = self::navBadgeUsage($tenant);

        return self::usageBadgeColor($usage['count'], $usage['limit']);
    }

    /**
     * 2026-09-13, pedido del Tech Lead: "duplicar... facilitar a los
     * usuarios o clientes que puedan replicar paginas o publicaciones o
     * servicios para editarlos con sus properties definidos y asi heredar
     * lo configurado anteriormente" — slug único para el duplicado
     * ("-copia", "-copia-2"... si ya existe). `Page::where(...)` ya queda
     * scopeado al tenant actual (global scope de `HasTenant`) y a los NO
     * papelereados (global scope de `SoftDeletes`), así que solo hace
     * falta filtrar por `lang_iso` acá — igual que la regla real de
     * unicidad del slug.
     */
    private static function duplicateSlug(Page $record): string
    {
        $base = $record->slug.'-copia';
        $candidate = $base;
        $suffix = 2;

        while (Page::where('slug', $candidate)->where('lang_iso', $record->lang_iso)->exists()) {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        }

        return $candidate;
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

        /**
         * Fix real (2026-09-17, reporte del Tech Lead): las columnas de
         * `colophon` (y cualquier Repeater/Builder anidado dentro de
         * `content` — sub-bloques de columna, items de `social_links`,
         * etc.) se reordenaban solas al guardar, y un reordenamiento manual
         * (drag-and-drop) tampoco se mantenía tras recargar.
         *
         * Causa raíz: `$state` en `saveRelationshipsUsing()` es el estado
         * CRUDO del Builder de nivel página (`getState()`/`getRawState()`,
         * sin pasar por `dehydrateState()` — ver
         * `vendor/filament/schemas/src/Components/Concerns/HasState.php`).
         * Ese estado crudo keyea cada ítem de Repeater/Builder por su ID
         * interno de Livewire (string tipo UUID), no por posición 0..n-1.
         * El nivel superior (`blocks`) ya se reindexaba correctamente vía
         * `array_values($state)` en `saveRelationshipsUsing` (ver más abajo)
         * — pero `content` (guardado tal cual, solo pasando por acá) nunca
         * recibía el mismo tratamiento en sus arrays ANIDADOS.
         *
         * Un array PHP con keys no-secuenciales se serializa a JSON como
         * OBJETO, no como ARRAY. Postgres `jsonb` NO garantiza preservar el
         * orden de las keys de un objeto al guardarlo (a diferencia del tipo
         * `json`) — reordena las keys según su propio criterio interno de
         * almacenamiento. Resultado: el orden visual/de arrastre que el
         * usuario dejó en pantalla se perdía silenciosamente en cada
         * guardado, reemplazado por el orden "canónico" que Postgres le dio
         * al objeto — percibido como "se reordena solo" y "no se mantiene el
         * reordenamiento manual".
         *
         * Fix: cualquier array cuyas keys sean TODAS UUID-like (la firma de
         * un array de ítems de Repeater/Builder de Livewire, nunca de un
         * objeto de datos real como `properties`/`content.columns[n]`, que
         * usan nombres de campo) se reindexa a 0..n-1 con `array_values()`
         * — preservando el orden real (ya correcto en el array de Livewire),
         * pero forzando la serialización como ARRAY JSON en vez de objeto.
         * Ver `ResolvesPublicLinks::transformBlockContent()` (mismo
         * síntoma, ya parchado ahí del lado de LECTURA de la API pública
         * con el mismo `array_values()` defensivo) — este fix cierra el
         * mismo agujero del lado de ESCRITURA en Studio.
         */
        if (self::isUuidKeyedArray($value)) {
            return array_values($value);
        }

        return $value;
    }

    /**
     * Detecta un array cuyas keys son TODAS strings con formato UUID — la
     * firma exacta de un array de ítems crudo de un Repeater/Builder de
     * Livewire (cada ítem se identifica por un UUID interno, no por
     * posición). Un array vacío no cuenta (nada que reindexar) para no
     * gastar el chequeo de más, y para no confundir "sin ítems" con "sí es
     * un array de ítems". Ver `unwrapFileUploadState()` para el porqué.
     *
     * @param  array<array-key, mixed>  $value
     */
    private static function isUuidKeyedArray(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        foreach (array_keys($value) as $key) {
            if (! is_string($key) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key)) {
                return false;
            }
        }

        return true;
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

    /**
     * Reglas de compatibilidad bloque↔tipo de página, únicas para todo el
     * recurso (2026-09-13, extraídas del closure de `->blocks()` al agregar
     * la acción "Copiar a otra página": ambos lugares necesitaban la MISMA
     * regla — el selector de bloques disponibles al editar, y el filtro de
     * páginas destino elegibles al copiar un bloque ya existente — tenerla
     * duplicada en dos sitios hubiera sido una fuente segura de divergencia
     * el día que se agregue/quite un tipo de bloque). Ver los comentarios
     * originales, más detallados, en el closure de `->blocks()` más abajo.
     *
     * @return array{footerOnly: list<string>, legalOnly: list<string>, legalExcluded: list<string>, footerAllowed: list<string>}
     */
    private static function blockCompatibilityRules(): array
    {
        $footerOnlyBlocks = ['colophon', 'footer_bottom'];
        $legalOnlyBlocks = ['legal_notice'];
        $legalExcludedBlocks = ['hero', 'image', 'features', 'faq', 'split', 'testimonials', 'services_grid', 'testimonials_grid', 'rich_text'];

        return [
            'footerOnly' => $footerOnlyBlocks,
            'legalOnly' => $legalOnlyBlocks,
            'legalExcluded' => $legalExcludedBlocks,
            'footerAllowed' => ['image', 'cta', 'features', 'faq', 'contact_form', 'testimonials', 'logos', ...$footerOnlyBlocks],
        ];
    }

    /**
     * ¿Puede un bloque de tipo `$blockName` (valor de `BlockTypeEnum`, ej.
     * `'cta'`, `'colophon'`) vivir en una página de tipo `$pageType`?
     * Usada por el selector de bloques disponibles (`->blocks()`) y por el
     * filtro de páginas destino de "Copiar a otra página" (`extraItemActions`
     * más abajo) — un bloque solo puede copiarse a una página que también
     * lo aceptaría si se agregara a mano ahí.
     */
    public static function isBlockAllowedForPageType(?string $blockName, ?PageTypeEnum $pageType): bool
    {
        if (! $blockName || ! $pageType) {
            return false;
        }

        $rules = self::blockCompatibilityRules();

        return match ($pageType) {
            PageTypeEnum::Footer => in_array($blockName, $rules['footerAllowed'], true),
            PageTypeEnum::Legal => ! in_array($blockName, [...$rules['footerOnly'], ...$rules['legalExcluded']], true),
            default => ! in_array($blockName, [...$rules['footerOnly'], ...$rules['legalOnly']], true),
        };
    }

    /**
     * Opciones agrupadas por tipo de contenido para el selector del modal "Copiar a otro contenido"
     * (2026-09-15, pedido del Tech Lead: "agrupar las opciones por tipo de contenido" / "Contenido destino").
     *
     * Devuelve una estructura [NombreGrupo => [id => title]] compatible con
     * los <optgroup> de Filament Select, excluyendo el contenido actual ($currentRecord)
     * y filtrando solo aquellos contenidos cuyo tipo admita el bloque ($blockName).
     *
     * @return array<string, array<int, string>>
     */
    public static function getTargetPageOptionsForBlock(?Page $currentRecord, ?string $blockName): array
    {
        $tenantId = $currentRecord?->tenant_id ?? Filament::getTenant()?->id;

        $typeOrder = [
            PageTypeEnum::Page->value => 1,
            PageTypeEnum::Footer->value => 2,
            PageTypeEnum::Legal->value => 3,
            PageTypeEnum::Landing->value => 4,
        ];

        $pages = Page::query()
            ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when($currentRecord, fn ($query) => $query->whereKeyNot($currentRecord->getKey()))
            ->orderBy('title')
            ->get()
            ->filter(fn (Page $page) => self::isBlockAllowedForPageType($blockName, $page->type))
            ->sortBy(fn (Page $page) => $typeOrder[$page->type?->value ?? ''] ?? 99);

        $grouped = [];

        foreach ($pages as $page) {
            $groupName = match ($page->type) {
                PageTypeEnum::Page => 'Páginas',
                PageTypeEnum::Footer => 'Pie de página (Footer)',
                PageTypeEnum::Legal => 'Avisos Legales',
                PageTypeEnum::Landing => 'Landing Pages',
                default => $page->type instanceof PageTypeEnum ? $page->type->getLabel() : 'Otros',
            };

            $grouped[$groupName][$page->id] = $page->title;
        }

        return $grouped;
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

        // Único enlace opcional del bloque Grid de Servicios (2026-09-10,
        // ADR pendiente desde ADR-034 resuelto — mismo patrón exacto que
        // `testimonials`): normalmente no hace falta (el catálogo completo
        // de `/servicios` pagina solo con "Ver más servicios", 100% client
        // side sobre datos ya horneados), pero un teaser de servicios en
        // otra página (ej. Home) sí puede querer un botón "Ver todos los
        // servicios" hacia la página `/servicios`.
        $servicesGridLinkFields = LinkSchema::makeSingle('links');

        // Único enlace opcional del bloque Grid de Casos de Éxito (2026-09-11,
        // pedido del Tech Lead: "Casos de éxito"... "tiene que ser un bloque
        // nuevo especial como el de servicios... la configuración todo igual
        // al de servicios en el admin"). Mismo patrón exacto que
        // `$servicesGridLinkFields`, ver ese comentario — el catálogo
        // completo de `/casos-de-exito` pagina solo con su propio botón
        // "Ver más casos" (100% client-side, mismo mecanismo que
        // `ServicesGrid.astro`), un teaser en otra página sí puede querer un
        // botón "Ver todos los casos de éxito".
        $testimonialsGridLinkFields = LinkSchema::makeSingle('links');

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
                                                $tenantId = $record?->tenant_id ?? Filament::getTenant()?->id ?? auth()->user()?->tenant_id;

                                                return Page::query()
                                                    ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
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
                                            ->default(PublishStatusEnum::Draft->value)
                                            ->disabled(static::currentUserIsAuthor())
                                            ->dehydrated(),

                                        Forms\Components\DateTimePicker::make('published_at')
                                            ->label('Fecha de publicación')
                                            ->nullable()
                                            ->disabled(static::currentUserIsAuthor())
                                            ->dehydrated(),
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
                                            // `legal_notice` (2026-09-11) suma su propia exclusión al
                                            // revés de `colophon`/`footer_bottom`: solo disponible para
                                            // `Legal`, oculto en `Página`/`Landing`/`Footer` — no tiene
                                            // sentido ofrecer "Aviso Legal / Contenido..." como bloque de
                                            // una página normal. `->blocks()` de Filament acepta un Closure con
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
                                        $servicesGridLinkFields,
                                        $testimonialsGridLinkFields,
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

                                                    Section::make('Propiedades Visuales')
                                                        ->description('Decoradores superior e inferior, color de fondo, overlay y ajustes de brillo/contraste.')
                                                        ->collapsed()
                                                        ->schema([
                                                            Grid::make(3)
                                                                ->schema([
                                                                    // 2026-09-05, fix real (detectado al sembrar el
                                                                    // heading de "Sobre CICA"): este bloque tenía sus
                                                                    // PROPIOS `Select` de decorador con opciones
                                                                    // hardcodeadas a mano (`'waves'`, `'curve'`, etc.)
                                                                    // en vez de reusar `PropertiesSchema` como
                                                                    // `rich_text`. El valor `'waves'` (plural) NUNCA
                                                                    // coincidía con `DecoratorShapeEnum::Wave->value`
                                                                    // (`'wave'`, singular) que consume el frontend
                                                                    // (`DecoratorShape` en `cica360/src/lib/types.ts`)
                                                                    // — elegir "Ondas" acá guardaba un valor que el
                                                                    // sitio público nunca iba a reconocer. Se
                                                                    // reemplaza por los componentes compartidos
                                                                    // (mismo enum, mismas opciones que `rich_text`).
                                                                    ...PropertiesSchema::makeComponents([
                                                                        'decorator_top', 'decorator_top_color',
                                                                        'decorator_bottom', 'decorator_bottom_color',
                                                                    ]),

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

                                                            // 2026-09-11 (pedido del Tech Lead, con captura): "esta
                                                            // mal que cuando se cambien dentro de propiedades
                                                            // visuales, en el campo Tipo de fondo: imagen, ahi
                                                            // recien se muestre la seccion de Imagenes del
                                                            // Encabezado... se ve raro eso". Antes esta sub-sección
                                                            // vivía en un `Section` propio, ANTES de "Propiedades
                                                            // Visuales" en el formulario: el selector "Tipo de
                                                            // fondo" estaba escondido dentro de esta sección
                                                            // colapsada, así que elegir "Imagen" revelaba contenido
                                                            // nuevo MÁS ARRIBA en la pantalla, fuera de foco. Se
                                                            // mueve acá adentro, mismo criterio ya usado en
                                                            // `cta`/`features`/`colophon` (ver esos bloques): el
                                                            // campo dependiente vive PEGADO al selector que lo
                                                            // activa, en la misma sección — se abre "Propiedades
                                                            // Visuales" una sola vez y todo lo relacionado a fondo
                                                            // aparece junto, sin saltos.
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
                                                                        ->options(function () {
                                                                            $tenantId = Filament::getTenant()?->id ?? auth()->user()?->tenant_id;

                                                                            return Slider::query()
                                                                                ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
                                                                                ->pluck('title', 'id');
                                                                        })
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
                                                                        ->schema(PropertiesSchema::makeComponents(['overlay_opacity'])),
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
                                                                    'background_type_image', 'background_color',
                                                                    'background_color_secondary', 'gradient_direction',
                                                                    'text_color', 'text_align', 'content_width', 'padding_y',
                                                                    'show_scroll_indicator',
                                                                ])),

                                                            // 2026-09-11 (pedido del Tech Lead: "todos deberian
                                                            // permitir personalizar el fondo en 3 tipos, no se
                                                            // por que solo 4 bloques tiene eso") — mismo patrón
                                                            // que `cta`/`features`/`colophon`: imagen + filtros
                                                            // solo visibles con `background_type: image`,
                                                            // excluyente con el color. De paso se suman
                                                            // `background_color_secondary`/`gradient_direction`
                                                            // — este bloque ya ofrecía "Degradado" como opción
                                                            // del selector, pero sin estos 2 campos era una
                                                            // opción fantasma (no había dónde elegir el 2do
                                                            // color ni la dirección), un gap preexistente
                                                            // detectado al auditar este mismo pedido.
                                                            MediaUpload::make('content.background_image_id', 'Imagen de fondo')
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
                                            // 2026-09-11, rediseño UX/copy a pedido del Tech Lead ("el
                                            // bloque imagen unica falta alinear UX y con buenos copys y
                                            // no deberia tener heading este bloque"): se quita
                                            // `HeadingFieldset::make()` (el frontend nunca lo renderizaba
                                            // como encabezado — `ImageBlock.astro` usaba `block.title` a
                                            // modo de caption improvisado, algo que ya tiene su propio
                                            // campo `content.caption` acá abajo). Los campos pasan a 2
                                            // `Section`: "Imagen" (archivo + caption + aspecto/alineación,
                                            // con `helperText` explicando el efecto real de cada uno) y
                                            // "Personalización de estilos" (colapsada, mismo patrón que
                                            // el resto de bloques). De paso se corrige el mismo gap de
                                            // "Degradado fantasma" ya encontrado en otros bloques (ver
                                            // ADR-052): el Select de Tipo de fondo ya ofrecía "Degradado"
                                            // sin los campos `background_color_secondary`/
                                            // `gradient_direction` que lo hacen funcionar.
                                            Builder\Block::make('image')
                                                ->label('Imagen única')
                                                ->icon('heroicon-o-photo')
                                                ->schema([
                                                    Section::make('Imagen')
                                                        ->description('El archivo que se muestra en esta sección, con una descripción opcional debajo.')
                                                        ->schema([
                                                            MediaUpload::make('content.media_id', 'Seleccionar Imagen')
                                                                ->required(),

                                                            Forms\Components\TextInput::make('content.caption')
                                                                ->label('Descripción (Opcional)')
                                                                ->helperText('Texto breve que aparece debajo de la imagen.'),

                                                            Grid::make(2)
                                                                ->schema([
                                                                    Forms\Components\Select::make('content.aspect')
                                                                        ->label('Relación de aspecto')
                                                                        ->helperText('Cómo se recorta la imagen. "Automático" respeta las proporciones originales del archivo.')
                                                                        ->options([
                                                                            'auto' => 'Automático',
                                                                            '16:9' => '16:9 Horizontal',
                                                                            '4:3' => '4:3 Estándar',
                                                                            '1:1' => '1:1 Cuadrado',
                                                                        ])
                                                                        ->default('auto'),

                                                                    Forms\Components\Select::make('content.align')
                                                                        ->label('Alineación')
                                                                        ->helperText('Posición de la imagen dentro de la sección.')
                                                                        ->options([
                                                                            'left' => 'Izquierda',
                                                                            'center' => 'Centro',
                                                                            'right' => 'Derecha',
                                                                        ])
                                                                        ->default('center'),
                                                                ]),
                                                        ]),

                                                    Section::make('Personalización de estilos')
                                                        ->description('Color de fondo (sólido o degradado) y espaciado vertical de la sección.')
                                                        ->collapsed()
                                                        ->schema([
                                                            PropertiesSchema::make([
                                                                'background_type', 'background_color',
                                                                'background_color_secondary', 'gradient_direction',
                                                                'padding_y',
                                                            ])->columns(2),
                                                        ]),
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
                                                        ->description('Elegir si el fondo de la sección es un color sólido, un degradado o una imagen.')
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
                                            //
                                            // Rediseño completo 2026-09-07, pedido explícito del Tech Lead (con
                                            // captura de referencia: 3 tarjetas "Misión/Visión/Valores", foto
                                            // real arriba, título, y descripción en párrafo O en lista de
                                            // viñetas según el item): antes el item solo tenía
                                            // icono+título+descripción+imagen sueltos, sin ningún orden de
                                            // layout ni forma de mostrar una lista (necesaria para "Valores").
                                            // Tampoco tenía NINGUNA property de fondo/estilo propia — a
                                            // diferencia de `cta`/`heading`/`rich_text`, `features` se había
                                            // quedado con el set mínimo original.
                                            //
                                            // UX del item (pedido explícito): imagen a la IZQUIERDA (columna 1
                                            // del `Grid(2)`), ícono+título+descripción a la DERECHA (columna 2,
                                            // agrupados en un `Fieldset` para que se lean como "el contenido del
                                            // item" separado de "la imagen del item"). Ícono e imagen son
                                            // AMBOS opcionales (ninguno lleva `->required()`) — el frontend
                                            // decide cuál priorizar si un item trae los dos (ver `Features.astro`
                                            // en cica360).
                                            //
                                            // `properties.list_style` (`FeatureListStyleEnum`: ninguno/lista/
                                            // grid) — CORRECCIÓN 2026-09-07: originalmente este selector vivía
                                            // POR ITEM bajo el nombre confuso "Contenido adicional"
                                            // (`FeatureContentFormatEnum`, ya eliminado). El Tech Lead pidió 2
                                            // cambios en el mismo pedido: "el nombre no es el adecuado...
                                            // debería ser Estilo, Formato, presentación, algo así" y "también es
                                            // mejor pasar a properties como parte del estilo si desea las
                                            // características tipo grid, list o ninguna". Ahora es UNA sola
                                            // property a nivel de BLOQUE (`PropertiesSchema.php`, Section
                                            // "Personalización de estilos" abajo) — cada característica sigue
                                            // decidiendo implícitamente si tiene algo que mostrar en ese formato
                                            // según si trae `items[]` cargado (vía `TagsInput`, sin estructura
                                            // rígida de sub-campos; ver "Valores"). `TagsInput` en vez de un 2do
                                            // `Repeater` anidado: cada ítem es solo una palabra/frase corta, no
                                            // un objeto con más campos — un `Repeater` de un solo `TextInput`
                                            // por fila sería más pesado de editar sin ganar nada.
                                            //
                                            // `Section` "Fondo" (`background_type_image` + imagen + filtros,
                                            // igual patrón que `cta`) y `Section` "Personalización de estilos"
                                            // (con las 3 properties NUEVAS específicas del bloque:
                                            // `feature_style` — simple/formas/tarjeta sin sombra/tarjeta con
                                            // sombra, esta última es la del diseño de referencia —,
                                            // `card_rounded` y `list_style`, ver `PropertiesSchema.php`) — mismas
                                            // 2 secciones colapsables que ya usa `cta`, para que la UX de
                                            // "properties" quede unificada entre bloques en vez de un tercer
                                            // patrón sin relación.
                                            //
                                            // Auditoría de enums (pedido explícito del Tech Lead: "me imagino que
                                            // están en un ENUM por que crearlas hardcode generarán problemas
                                            // después, cuidar eso"): `feature_style` y `list_style` SÍ usan enums
                                            // backed (`FeatureCardStyleEnum`, `FeatureListStyleEnum`, ambos
                                            // implementan `HasLabel`) — no hay `->options([...])` hardcodeado en
                                            // ninguno de los 2 selectores nuevos de este bloque.
                                            // TODAS las properties de este bloque son opcionales (ninguna lleva
                                            // `->required()`, todas con `->default()` razonable): un dev
                                            // integrando su propio frontend sobre esta misma API puede ignorar
                                            // por completo `feature_style`/`card_rounded`/fondo y quedarse solo
                                            // con `content.items[]` sin que nada rompa.
                                            //
                                            // `content.background_image_id` (con prefijo `content.` explícito,
                                            // NO `background_image_id` a secas): el campo de imagen de fondo de
                                            // `cta`/`colophon` (ver esos bloques, código legado) se declaró SIN
                                            // el prefijo `content.` — al no vivir dentro de ningún `Repeater`
                                            // (que sí escopea automáticamente sus campos), ese nombre "pelado"
                                            // queda como un atributo de nivel raíz del item del `Builder`, que
                                            // NUNCA es mass-assignable a `Block` (`$fillable` no lo incluye) — el
                                            // valor se pierde en silencio al guardar. Acá se usa el prefijo
                                            // correcto desde el arranque para no repetir ese bug latente; queda
                                            // pendiente auditar/corregir `cta`/`colophon` por separado (no se
                                            // tocan en este cambio para no mezclar un fix no pedido con el
                                            // rediseño de `features`).
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
                                                                    Grid::make(2)
                                                                        ->schema([
                                                                            MediaUpload::make('image_id', 'Imagen (Opcional)'),

                                                                            Fieldset::make('Contenido')
                                                                                ->schema([
                                                                                    Forms\Components\TextInput::make('icon')
                                                                                        ->label('Ícono (Opcional)')
                                                                                        ->helperText('Ej: heroicon-o-flag. Se usa como respaldo si el item no tiene imagen.')
                                                                                        ->columnSpanFull(),
                                                                                    Forms\Components\TextInput::make('title')
                                                                                        ->label('Título/Nombre')
                                                                                        ->required()
                                                                                        ->columnSpanFull(),
                                                                                    Forms\Components\Textarea::make('description')
                                                                                        ->label('Descripción')
                                                                                        ->rows(3)
                                                                                        ->columnSpanFull(),
                                                                                    // 2026-09-07: el selector "Contenido adicional" (por item) se
                                                                                    // eliminó de acá. El Tech Lead pidió: "el nombre no es el
                                                                                    // adecuado... y también es mejor pasar a properties como parte
                                                                                    // del estilo si desea las características tipo grid, list o
                                                                                    // ninguna" — ahora es `properties.list_style` (única, a nivel de
                                                                                    // BLOQUE, ver Section "Personalización de estilos" más abajo). El
                                                                                    // TagsInput queda siempre visible: el propio contenido decide
                                                                                    // implícitamente si hay algo que mostrar (item sin `items[]`
                                                                                    // cargado no muestra nada sin importar `list_style`).
                                                                                    Forms\Components\TagsInput::make('items')
                                                                                        ->label('Ítems de la lista (Opcional)')
                                                                                        ->helperText('Puntos breves para esta característica (uno por Enter o coma). Ej: Profesionalismo, Empatía, Transparencia. Si lo dejás vacío, la tarjeta muestra solo el campo Descripción.')
                                                                                        ->columnSpanFull(),
                                                                                ])
                                                                                ->columns(1),
                                                                        ]),
                                                                ])
                                                                ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                                                                ->defaultItems(1)
                                                                ->collapsible()
                                                                ->collapsed(),
                                                        ]),

                                                    Section::make('Fondo')
                                                        ->description('Elegir si el fondo de la sección es un color sólido, un degradado o una imagen. Es opcional: si no se modifica, queda sólido y transparente.')
                                                        ->collapsed()
                                                        ->schema([
                                                            Grid::make(2)
                                                                ->schema(PropertiesSchema::makeComponents([
                                                                    'background_type_image', 'background_color',
                                                                    'background_color_secondary', 'gradient_direction',
                                                                ])),

                                                            MediaUpload::make('content.background_image_id', 'Imagen de fondo')
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

                                                    Section::make('Personalización de estilos')
                                                        ->description('Estilo de tarjeta, bordes redondeados, color de texto, ancho y espaciado vertical. Todo opcional.')
                                                        ->collapsed()
                                                        ->schema([
                                                            PropertiesSchema::make([
                                                                'feature_style', 'card_rounded', 'list_style',
                                                                'text_color', 'content_width', 'padding_y',
                                                            ])->columns(2),
                                                            // 2026-09-10 (pedido del Tech Lead, front cica360): "no veo
                                                            // hasta ahora la flecha de invitacion a scrollear que ya
                                                            // usa otros bloques que ya hemos hecho" — campo reusable
                                                            // ya existente en PropertiesSchema (usado en `slider`/
                                                            // `hero` y `rich_text`), agregado acá por primera vez para
                                                            // este bloque. Ver Features.astro (cica360) para el
                                                            // consumo (`properties.show_scroll_indicator`).
                                                            PropertiesSchema::make(['show_scroll_indicator']),
                                                        ]),
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
                                                                    // Texto simple, NO `RichEditor` (2026-09-11, bug
                                                                    // real reportado por el Tech Lead: Livewire
                                                                    // tiraba 500 — `MaxNestingDepthExceededException`,
                                                                    // "exceeds the maximum nesting depth of 10
                                                                    // levels" — al escribir una respuesta). El
                                                                    // documento JSON en vivo que arma `RichEditor`
                                                                    // (Tiptap) sumado a la propia profundidad de
                                                                    // Builder→Repeater→campo superaba el límite de
                                                                    // Livewire con solo un párrafo simple. Además,
                                                                    // el sitio público (`Faq.astro`) ya renderiza
                                                                    // esta respuesta como texto plano (sin
                                                                    // `set:html`), así que el HTML de un rich editor
                                                                    // nunca se hubiera visto formateado igual —
                                                                    // `Textarea` es el campo correcto para lo que el
                                                                    // frontend realmente consume.
                                                                    Forms\Components\Textarea::make('answer')
                                                                        ->label('Respuesta')
                                                                        ->rows(3)
                                                                        ->required(),
                                                                ])
                                                                ->itemLabel(fn (array $state): ?string => $state['question'] ?? null)
                                                                ->defaultItems(1)
                                                                ->collapsible()
                                                                ->collapsed(),
                                                        ]),

                                                    // 2026-09-11 (pedido del Tech Lead: "todos deberian
                                                    // permitir personalizar el fondo en 3 tipos") — mismo
                                                    // patrón que `cta`/`features`/`colophon`: imagen +
                                                    // filtros solo visibles con `background_type: image`,
                                                    // excluyente con el color. De paso se agrupa en un
                                                    // `Section` (antes quedaban sueltos al final del
                                                    // formulario, sin ningún agrupamiento visual — mismo
                                                    // criterio ya aplicado a `contact_form` en esta sesión).
                                                    Section::make('Personalización de estilos')
                                                        ->description('Color de fondo (sólido, degradado o imagen), color de texto y espaciado vertical.')
                                                        ->collapsed()
                                                        ->schema([
                                                            PropertiesSchema::make(['background_type_image', 'background_color', 'background_color_secondary', 'gradient_direction', 'text_color', 'padding_y'])
                                                                ->columns(2),

                                                            MediaUpload::make('content.background_image_id', 'Imagen de fondo')
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

                                            // 7. CONTACT FORM Block
                                            //
                                            // 2026-09-11 (pedido del Tech Lead sobre el acabado real en el
                                            // admin): "se ve super mal el acabado, tiene ser mas UX y con buen
                                            // copy". 2 problemas encontrados al revisar este bloque puntual:
                                            //
                                            // 1. Traía `HeadingFieldset::make()` (Pre título/Título/Subtítulo)
                                            //    igual que todos los demás bloques, pero desde la corrección de
                                            //    esta misma sesión ("el formulario no debe tener nada en el
                                            //    heading", ver docblock de `ContactFormBlock.astro` en cica360)
                                            //    el frontend NUNCA lee `block.pretitle`/`title`/`subtitle` para
                                            //    este bloque en particular — quien completara esos 3 campos acá
                                            //    no vería ningún efecto en el sitio, campos "fantasma" que
                                            //    confunden más de lo que ayudan. Se elimina el fieldset: el
                                            //    banner `heading` de la página ya cumple ese rol.
                                            // 2. A diferencia de todos los demás bloques con properties de
                                            //    fondo/estilo, este no envolvía esos campos en un `Section` con
                                            //    título — quedaban sueltos al final del formulario sin ningún
                                            //    agrupamiento visual (ver captura), y ni el selector de
                                            //    Formulario ni el texto de introducción tenían copy que explique
                                            //    qué hacen. Se agrega la misma estructura (`Section` +
                                            //    `->description()`/`->helperText()`) que ya usa el resto de
                                            //    bloques, en español neutro sin jerga (ADR-051).
                                            Builder\Block::make('contact_form')
                                                ->label('Formulario de Contacto')
                                                ->icon('heroicon-o-envelope')
                                                ->schema([
                                                    Section::make('Formulario')
                                                        ->description('Elegir qué formulario de este sitio se muestra en esta sección. Los formularios se administran en el módulo Formularios, disponible en el menú lateral.')
                                                        ->schema([
                                                            // 2026-09-18, Fase 2 del plan de formularios (ADR-073):
                                                            // este Select guardaba el `id` INTERNO del `Form`
                                                            // (`pluck('name', 'id')`) — un detalle de
                                                            // implementación de Eloquent, no un dato que el API
                                                            // público pueda resolver. El endpoint público de
                                                            // formularios (igual que Páginas/Posts/Servicios)
                                                            // SIEMPRE resuelve por `slug` (`GET /forms/{slug}`,
                                                            // nunca por id), así que cica360 no tenía forma de
                                                            // pedirle al API el formulario elegido acá — el gap
                                                            // real detrás de "el front sigue mostrando el
                                                            // formulario hardcodeado, no lee `content.form_id`"
                                                            // (ver `ContactFormBlock.astro`, consecuencia #4 de
                                                            // ADR-073). Se cambia a guardar el `slug` (string,
                                                            // igual que `properties.footer_page_id` guarda un id
                                                            // porque ESE sí se resuelve server-side dentro de
                                                            // Genesis — acá en cambio el consumidor es el frontend
                                                            // headless, vía API pública) y se renombra la clave a
                                                            // `content.form_slug` para que el nombre no mienta
                                                            // sobre lo que realmente contiene. Bloques YA
                                                            // guardados con la clave vieja `content.form_id`
                                                            // (numérica) se migran en
                                                            // `2026_09_18_050000_migrate_contact_form_block_id_to_slug`.
                                                            Forms\Components\Select::make('content.form_slug')
                                                                ->label('Formulario a mostrar')
                                                                ->helperText('Si el formulario buscado no aparece en la lista, se puede crear uno nuevo en el módulo Formularios.')
                                                                ->options(function () {
                                                                    $tenantId = Filament::getTenant()?->id ?? auth()->user()?->tenant_id;

                                                                    return Form::query()
                                                                        ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
                                                                        ->pluck('name', 'slug');
                                                                })
                                                                ->searchable()
                                                                ->required(),

                                                            Forms\Components\Textarea::make('content.intro')
                                                                ->label('Texto de introducción (Opcional)')
                                                                ->helperText('Aparece arriba de los campos del formulario, antes de que la persona empiece a completarlo.'),
                                                        ]),

                                                    // 2026-09-11 (pedido del Tech Lead, mismo día: "todos
                                                    // deberian permitir personalizar el fondo en 3 tipos") —
                                                    // sube a `background_type_image` + imagen/filtros, mismo
                                                    // patrón que el resto de los bloques.
                                                    Section::make('Personalización de estilos')
                                                        ->description('Color de fondo (sólido, degradado o imagen), color de texto, espaciado vertical y ancho del contenido de la sección.')
                                                        ->collapsed()
                                                        ->schema([
                                                            PropertiesSchema::make(['background_type_image', 'background_color', 'background_color_secondary', 'gradient_direction', 'text_color', 'padding_y', 'content_width'])
                                                                ->columns(2),

                                                            MediaUpload::make('content.background_image_id', 'Imagen de fondo')
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

                                            // 8. LEGAL NOTICE Block
                                            Builder\Block::make('legal_notice')
                                                ->label('Aviso Legal / Contenido largo')
                                                ->icon('heroicon-o-shield-check')
                                                ->schema([
                                                    HeadingFieldset::make(),

                                                    Forms\Components\RichEditor::make('content.body')
                                                        ->label('Contenido legal')
                                                        ->required(),

                                                    // 2026-09-11 (pedido del Tech Lead: "todos deberian
                                                    // permitir personalizar el fondo en 3 tipos, no se por
                                                    // que solo 4 bloques tiene eso") — este bloque no tenía
                                                    // NINGÚN control de fondo (solo padding/ancho). Se agrega
                                                    // el mismo set completo (sólido/degradado/imagen + color
                                                    // de texto) que ya usa el resto, mismo patrón que
                                                    // `cta`/`features`/`colophon` para la imagen/filtros.
                                                    Section::make('Personalización de estilos')
                                                        ->description('Color de fondo (sólido, degradado o imagen), color de texto, espaciado vertical y ancho del contenido.')
                                                        ->collapsed()
                                                        ->schema([
                                                            PropertiesSchema::make(['background_type_image', 'background_color', 'background_color_secondary', 'gradient_direction', 'text_color', 'padding_y', 'content_width'])
                                                                ->columns(2),

                                                            MediaUpload::make('content.background_image_id', 'Imagen de fondo')
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
                                                                        'text_background_color', 'text_color', 'padding_y', 'content_width',
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

                                                    // 2026-09-11: pedido del Tech Lead sobre este mismo texto —
                                                    // "trae" no se entiende, corregido a lenguaje llano, sin
                                                    // mencionar el API: este campo no controla qué se comparte
                                                    // públicamente (eso lo decide el toggle "Visible" del propio
                                                    // módulo Testimonios) — solo cuántos y en qué orden se
                                                    // muestran EN ESTE BLOQUE. Mismo criterio de tono que
                                                    // ADR-051 (español neutro, sin jerga técnica).
                                                    Section::make('Filtro de testimonios')
                                                        ->description('Los testimonios se administran en el módulo Testimonios, disponible en el menú lateral. Aquí solo se define cuántos se muestran en este bloque y en qué orden. Se muestran únicamente los marcados como visibles en ese módulo.')
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

                                                    // 2026-09-11 (pedido del Tech Lead: "todos deberian
                                                    // permitir personalizar el fondo en 3 tipos") — sube a
                                                    // `background_type_image` + imagen/filtros, mismo patrón
                                                    // que el resto de los bloques.
                                                    Section::make('Personalización de estilos')
                                                        ->description('Color de fondo (sólido, degradado o imagen) de la sección y de las tarjetas, color de texto, espaciado y animación de entrada.')
                                                        ->collapsed()
                                                        ->schema([
                                                            PropertiesSchema::make(['background_type_image', 'background_color', 'background_color_secondary', 'gradient_direction', 'item_background_color', 'item_background_opacity', 'text_color', 'padding_y'])
                                                                ->columns(2),

                                                            MediaUpload::make('content.background_image_id', 'Imagen de fondo')
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
                                                        ->description('Cargar acá todos los logos necesarios (tope de 28) — cuáles y cuántos de estos llegan al sitio público se controla en "Límite compartido con la API" más abajo.')
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
                                                    // 2026-09-11 (pedido del Tech Lead: "todos deberian
                                                    // permitir personalizar el fondo en 3 tipos") — sube a
                                                    // `background_type_image` + imagen, mismo patrón que el
                                                    // resto de los bloques, CON UNA EXCEPCIÓN puntual: este
                                                    // bloque ya usa `media_filter_grayscale`/`media_opacity`
                                                    // para un efecto propio (escala de grises + opacidad
                                                    // aplicado a CADA logo, ver `Logos.astro`) — agregar esos
                                                    // 2 campos de nuevo acá, ahora para la imagen de fondo,
                                                    // pisaría el mismo valor para 2 cosas distintas (los
                                                    // logos Y el fondo comparten la property). Se deja la
                                                    // imagen de fondo con el resto del set de filtros
                                                    // (blend/brillo/saturación/sepia/contraste/matiz/
                                                    // desenfoque + overlay), SIN esos 2 puntuales — limitación
                                                    // conocida de este bloque en particular, no de los demás.
                                                    Section::make('Personalización de estilos')
                                                        ->description('Color de fondo (sólido, degradado o imagen), espaciado, ancho de contenido y filtro de escala de grises/opacidad de los logos.')
                                                        ->collapsed()
                                                        ->schema([
                                                            PropertiesSchema::make(['background_type_image', 'background_color', 'background_color_secondary', 'gradient_direction', 'padding_y', 'content_width', 'media_filter_grayscale', 'media_opacity'])
                                                                ->columns(2),

                                                            MediaUpload::make('content.background_image_id', 'Imagen de fondo')
                                                                ->visible(fn (Get $get) => $get('properties.background_type') === 'image'),

                                                            Grid::make(2)
                                                                ->schema(PropertiesSchema::makeComponents([
                                                                    'media_blend_mode', 'overlay_opacity',
                                                                    'media_brightness',
                                                                    'media_filter_saturate', 'media_filter_sepia',
                                                                    'media_filter_contrast', 'media_filter_hue_rotate',
                                                                    'media_filter_blur',
                                                                ]))
                                                                ->visible(fn (Get $get) => $get('properties.background_type') === 'image'),
                                                        ]),
                                                ]),

                                            // 12. SERVICES GRID Block
                                            Builder\Block::make('services_grid')
                                                ->label('Grid de Servicios')
                                                ->icon('heroicon-o-squares-2x2')
                                                // 2026-09-10 (ADR pendiente desde ADR-034 resuelto): mismo
                                                // rediseño que `testimonials` (ADR-033) — este bloque ya NO
                                                // guarda los servicios inline en `content.items` (el
                                                // `Repeater` de antes, con título/subtítulo/imagen/destino
                                                // manuales). Los servicios se gestionan en su propio módulo
                                                // ("Servicios" en el menú lateral, `ServiceResource`) y este
                                                // bloque solo elige CUÁNTOS trae y en qué orden — se resuelve
                                                // en runtime contra la tabla real `services`
                                                // (`ResolvesPublicLinks::attachResolvedBlockContent()`).
                                                // También se sacan los `TextInput::make('title')`/
                                                // `TextInput::make('subtitle')` sueltos que convivían con
                                                // `HeadingFieldset::make()` (mismo anti-patrón de campos
                                                // duplicados que ADR-031/032/033 ya corrigieron en otros
                                                // bloques — `HeadingFieldset` ya provee `title`/`subtitle`).
                                                ->schema([
                                                    Forms\Components\Hidden::make('lang_iso')
                                                        ->default('es'),

                                                    Forms\Components\Toggle::make('is_visible')
                                                        ->label('Visible')
                                                        ->helperText('Oculta el bloque en el sitio público sin borrarlo del editor.')
                                                        ->default(true)
                                                        ->required(),

                                                    HeadingFieldset::make(),

                                                    // 2026-09-11 (pedido del Tech Lead: "las descripciones de las
                                                    // secciones y campos necesita mejor copywriter y mejor UX,
                                                    // parece las conversaciones que hemos tenido") — copy
                                                    // reescrito sin jerga de desarrollo ni referencias entre
                                                    // paréntesis tipo nota interna. Corrección inmediata del
                                                    // mismo Tech Lead sobre el 1er intento (que usaba voseo
                                                    // rioplatense, "definís"/"Dejalo"/"indicá"/"Agregá"): "todo
                                                    // lo de stamless tiene que estar en un tono hispano neutral
                                                    // y amigable... nada de argento o jergas" — el voseo es
                                                    // exclusivo de la MARCA de CICA360 (frontend, tenant), NO
                                                    // del producto Stamless (Console/Studio, multi-tenant). Acá
                                                    // se usan construcciones neutras/infinitivas ("Dejar
                                                    // vacío...", "Agregar un botón...") en vez de "vos"/"tú"/
                                                    // "usted", ver ADR-051 en DECISIONS.md.
                                                    Section::make('Catálogo de servicios')
                                                        ->description('Los servicios se administran en el módulo Servicios, disponible en el menú lateral. Este bloque solo permite elegir cuántos mostrar y en qué orden; siempre se muestran los publicados.')
                                                        ->schema([
                                                            Grid::make(2)
                                                                ->schema([
                                                                    Forms\Components\TextInput::make('content.limit')
                                                                        ->label('Cantidad a mostrar')
                                                                        ->helperText('Dejar vacío para mostrar todo el catálogo publicado, o escribir un número para limitarlo.')
                                                                        ->numeric()
                                                                        ->minValue(1)
                                                                        ->maxValue(200)
                                                                        ->nullable(),

                                                                    Forms\Components\Select::make('content.order')
                                                                        ->label('Orden')
                                                                        ->helperText('Sigue el orden que definiste arrastrando las filas en el módulo Servicios.')
                                                                        ->options([
                                                                            'asc' => 'Orden manual del catálogo',
                                                                            'desc' => 'Orden manual invertido',
                                                                        ])
                                                                        ->default('asc')
                                                                        ->required(),
                                                                ]),
                                                        ]),

                                                    Section::make('Enlace "Ver más" (opcional)')
                                                        ->description('Agregar un botón debajo del catálogo que lleve a otra página, a una entrada del blog o a una URL externa. No es necesario si esta página ya muestra el catálogo completo de Servicios.')
                                                        ->schema([
                                                            Grid::make(1)
                                                                ->schema(PropertiesSchema::makeComponents(['show_link'])),

                                                            Grid::make(2)
                                                                ->schema($servicesGridLinkFields['main'])
                                                                ->visible(fn (Get $get) => (bool) $get('properties.show_link')),

                                                            Fieldset::make('Propiedades del enlace')
                                                                ->schema($servicesGridLinkFields['properties'])
                                                                ->columns(2)
                                                                ->visible(fn (Get $get) => (bool) $get('properties.show_link')),
                                                        ])
                                                        ->collapsible()
                                                        ->collapsed(),

                                                    // 2026-09-11 (pedido del Tech Lead: "todos deberian
                                                    // permitir personalizar el fondo en 3 tipos") — sube a
                                                    // `background_type_image` + imagen/filtros, mismo patrón
                                                    // que el resto de los bloques.
                                                    Section::make('Personalización de estilos')
                                                        ->description('Color de fondo (sólido, degradado o imagen), color de texto, espaciado y animación de entrada.')
                                                        ->collapsed()
                                                        ->schema([
                                                            PropertiesSchema::make(['background_type_image', 'background_color', 'background_color_secondary', 'gradient_direction', 'text_color', 'padding_y'])
                                                                ->columns(2),

                                                            MediaUpload::make('content.background_image_id', 'Imagen de fondo')
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

                                            // 13. TESTIMONIALS GRID Block (2026-09-11, pedido del Tech
                                            // Lead, con captura de mockup de "Casos de éxito" — grid 3×3
                                            // de tarjetas con avatar circular/frase/nombre + botón "MÁS
                                            // CASOS"): "es un bloque de testimonios que creamos a modo
                                            // preview o resumen solo para home u otras paginas, pero este
                                            // tiene que ser un bloque nuevo especial como el de servicios,
                                            // donde va el heading y luego la configuracion todo igual al
                                            // de servicios en el admin". Mismo tratamiento EXACTO que
                                            // `services_grid` (ver ese bloque arriba y ADR-049) pero
                                            // resuelto contra la tabla `testimonials` en vez de `services`
                                            // — reusa la MISMA query batched que ya arma el bloque
                                            // `testimonials` (teaser) para no duplicar el `SELECT`
                                            // (`ResolvesPublicLinks`), solo que acá el orden es manual
                                            // (`sort_order`, el mismo que cura el drag-reorder de
                                            // `TestimonialResource`) en vez de recencia — el bloque
                                            // `testimonials` (teaser) y este (`testimonials_grid`,
                                            // catálogo completo) son 2 bloques DISTINTOS a propósito, cada
                                            // uno con su propia semántica de orden, mismo criterio que
                                            // separa `services_grid` de un hipotético teaser de servicios.
                                            Builder\Block::make('testimonials_grid')
                                                ->label('Grid de Casos de Éxito')
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

                                                    // 2026-09-11 — mismo copywriting pass que `services_grid`
                                                    // (ver comentario de ese bloque más arriba).
                                                    Section::make('Catálogo de casos de éxito')
                                                        ->description('Los testimonios se administran en el módulo Testimonios, disponible en el menú lateral. Este bloque solo permite elegir cuántos mostrar y en qué orden; siempre se muestran los marcados como visibles.')
                                                        ->schema([
                                                            Grid::make(2)
                                                                ->schema([
                                                                    Forms\Components\TextInput::make('content.limit')
                                                                        ->label('Cantidad a mostrar')
                                                                        ->helperText('Dejar vacío para mostrar todos los testimonios visibles, o escribir un número para limitarlo.')
                                                                        ->numeric()
                                                                        ->minValue(1)
                                                                        ->maxValue(200)
                                                                        ->nullable(),

                                                                    Forms\Components\Select::make('content.order')
                                                                        ->label('Orden')
                                                                        ->helperText('Sigue el orden que definiste arrastrando las filas en el módulo Testimonios.')
                                                                        ->options([
                                                                            'asc' => 'Orden manual del catálogo',
                                                                            'desc' => 'Orden manual invertido',
                                                                        ])
                                                                        ->default('asc')
                                                                        ->required(),
                                                                ]),
                                                        ]),

                                                    Section::make('Enlace "Ver más" (opcional)')
                                                        ->description('Agregar un botón debajo del catálogo que lleve a otra página, a una entrada del blog o a una URL externa. No es necesario si esta página ya muestra el catálogo completo de Casos de éxito.')
                                                        ->schema([
                                                            Grid::make(1)
                                                                ->schema(PropertiesSchema::makeComponents(['show_link'])),

                                                            Grid::make(2)
                                                                ->schema($testimonialsGridLinkFields['main'])
                                                                ->visible(fn (Get $get) => (bool) $get('properties.show_link')),

                                                            Fieldset::make('Propiedades del enlace')
                                                                ->schema($testimonialsGridLinkFields['properties'])
                                                                ->columns(2)
                                                                ->visible(fn (Get $get) => (bool) $get('properties.show_link')),
                                                        ])
                                                        ->collapsible()
                                                        ->collapsed(),

                                                    // 2026-09-11 (pedido del Tech Lead: "todos deberian
                                                    // permitir personalizar el fondo en 3 tipos") — sube a
                                                    // `background_type_image` + imagen/filtros, mismo patrón
                                                    // que el resto de los bloques.
                                                    Section::make('Personalización de estilos')
                                                        ->description('Color de fondo (sólido, degradado o imagen), color de texto, espaciado y animación de entrada.')
                                                        ->collapsed()
                                                        ->schema([
                                                            PropertiesSchema::make(['background_type_image', 'background_color', 'background_color_secondary', 'gradient_direction', 'text_color', 'padding_y'])
                                                                ->columns(2),

                                                            MediaUpload::make('content.background_image_id', 'Imagen de fondo')
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
                                                        ->options(function () {
                                                            $tenantId = Filament::getTenant()?->id;

                                                            return Page::query()
                                                                ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
                                                                ->where('type', PageTypeEnum::Footer->value)
                                                                ->pluck('title', 'id');
                                                        })
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

                                                            CharacterTextarea::make('description')
                                                                ->label('Descripción breve')
                                                                ->rows(3)
                                                                ->characterLimit(120),

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
                                                        ->options(function () {
                                                            $tenantId = Filament::getTenant()?->id ?? auth()->user()?->tenant_id;

                                                            return Menu::query()
                                                                ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
                                                                ->pluck('name', 'id');
                                                        })
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

                                        // Reglas de compatibilidad bloque↔tipo de página
                                        // centralizadas en `self::blockCompatibilityRules()`
                                        // (2026-09-13, extraídas de acá al agregar "Copiar a
                                        // otra página" — ese filtro de páginas destino necesita
                                        // exactamente la misma regla que este selector de
                                        // bloques disponibles). Comentarios originales de cada
                                        // regla, sin cambios de fondo:
                                        //
                                        // `colophon`/`footer_bottom` (2026-09-02): exclusivos de
                                        // contenidos tipo `Footer` — no tiene sentido un pie de
                                        // página multi-columna ni una barra de copyright dentro de
                                        // una Página/Landing normal. Mismo mecanismo de exclusión que
                                        // ya usa `footer` (nunca en `$footerAllowedBlocks`, ver
                                        // arriba), solo que acá es al revés: SIEMPRE disponibles para
                                        // `Footer`, NUNCA para el resto.
                                        //
                                        // `legal_notice` (2026-09-11, pedido del Tech Lead con captura
                                        // del selector de bloques de una Página normal mostrando "Aviso
                                        // Legal / Contenido..."): exclusivo de contenidos tipo `Legal`
                                        // — no tiene sentido ofrecerlo en una Página/Landing/Footer
                                        // corriente, es contenido pensado específicamente para las
                                        // páginas de la tab "Legales" (`PageTypeEnum::Legal`).
                                        //
                                        // Al revés (2026-09-11, mismo pedido, segunda captura): un
                                        // contenido `Legal` es texto normativo simple — no tiene
                                        // sentido ofrecerle bloques pensados para landing/marketing
                                        // (hero con slider, imagen suelta como sección, grids de
                                        // features/servicios/casos de éxito/testimonios, split). Se
                                        // deja disponible el resto (heading, texto enriquecido, CTA,
                                        // formulario de contacto, logos) por si un Aviso Legal necesita
                                        // un banner simple o un CTA de contacto al pie. `rich_text`
                                        // (2026-09-11, tercera vuelta del mismo pedido: "quita texto
                                        // enriquecido") se suma después: redundante con `legal_notice`,
                                        // que ya es el bloque de texto largo pensado para este tipo.
                                        $blockRules = self::blockCompatibilityRules();
                                        $footerOnlyBlocks = $blockRules['footerOnly'];
                                        $legalOnlyBlocks = $blockRules['legalOnly'];
                                        $legalExcludedBlocks = $blockRules['legalExcluded'];

                                        if ($typeVal === PageTypeEnum::Footer->value) {
                                            return array_values(array_filter(
                                                $allBlocks,
                                                fn (Builder\Block $block) => in_array($block->getName(), $blockRules['footerAllowed'], true)
                                            ));
                                        }

                                        if ($typeVal === PageTypeEnum::Legal->value) {
                                            $legalBlocks = array_values(array_filter(
                                                $allBlocks,
                                                fn (Builder\Block $block) => ! in_array($block->getName(), [...$footerOnlyBlocks, ...$legalExcludedBlocks], true)
                                            ));

                                            // Reordena para que `legal_notice` quede justo debajo de
                                            // `heading` (2026-09-11: "mueve aviso legal en segundo
                                            // orden, debajo de heading") en vez de su posición natural
                                            // (bastante más abajo en `$allBlocks`) — el resto conserva
                                            // su orden relativo original. `usort` es estable desde PHP
                                            // 8.0, así que los bloques sin rango explícito (todo lo que
                                            // no sea heading/legal_notice) no cambian de orden entre sí.
                                            usort($legalBlocks, fn (Builder\Block $a, Builder\Block $b) => match ($a->getName()) {
                                                'heading' => 0,
                                                'legal_notice' => 1,
                                                default => 2,
                                            } <=> match ($b->getName()) {
                                                'heading' => 0,
                                                'legal_notice' => 1,
                                                default => 2,
                                            });

                                            return $legalBlocks;
                                        }

                                        $excludedBlocks = [...$footerOnlyBlocks, ...$legalOnlyBlocks];

                                        return array_values(array_filter(
                                            $allBlocks,
                                            fn (Builder\Block $block) => ! in_array($block->getName(), $excludedBlocks, true)
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
                                            // Copiar a otra página (2026-09-13, pedido del Tech
                                            // Lead: "como podríamos resolver... copiar un bloque
                                            // de una página a otra... tiene que ser un copiar").
                                            // A diferencia de "Duplicar" (nativo de Filament,
                                            // arriba) esto cruza a una página DISTINTA, que no
                                            // está cargada en este formulario — no hay forma de
                                            // dejarlo "pendiente hasta Guardar" sin un mecanismo
                                            // de portapapeles con estado propio. Se resolvió como
                                            // acción inmediata: al confirmar, el bloque se
                                            // persiste ya mismo en la página destino
                                            // (`Block::create()`, UUID nuevo vía `HasUuid`, al
                                            // final de sus bloques) independientemente del botón
                                            // "Guardar" de la página actual, que queda intacta —
                                            // el usuario después entra a la página destino a
                                            // reordenar/ajustar si hace falta. El Select de página
                                            // destino se filtra con la MISMA regla de
                                            // compatibilidad bloque↔tipo de página que ya usa el
                                            // selector de "Agregar bloque"
                                            // (`self::isBlockAllowedForPageType()`, extraída de
                                            // ahí para esto) — no tiene sentido ofrecer copiar un
                                            // `colophon` a una Página normal, por ejemplo.
                                    ->extraItemActions([
                                        Actions\Action::make('copyToPage')
                                            ->label('Copiar a otro contenido')
                                            ->icon('heroicon-o-clipboard-document')
                                            ->modalHeading('Copiar bloque a otro contenido')
                                            ->modalDescription('El bloque se copia tal cual está (título, contenido, imágenes, enlaces) al final de los bloques del contenido elegido. Este contenido no se modifica.')
                                            ->modalSubmitActionLabel('Copiar')
                                            ->schema(function (array $arguments, Builder $component): array {
                                                $item = $component->getRawState()[$arguments['item']] ?? null;
                                                $blockName = $item['type'] ?? null;
                                                $record = $component->getRecord();

                                                return [
                                                    Forms\Components\Select::make('target_page_id')
                                                        ->label('Contenido destino')
                                                        ->options(fn () => self::getTargetPageOptionsForBlock($record, $blockName))
                                                        ->searchable()
                                                        ->required()
                                                        ->helperText('Sólo se listan contenidos que aceptan este tipo de bloque.'),
                                                ];
                                            })
                                            ->action(function (array $data, array $arguments, Builder $component): void {
                                                $item = $component->getRawState()[$arguments['item']] ?? null;

                                                if (! $item) {
                                                    return;
                                                }

                                                $tenantId = $component->getRecord()?->tenant_id ?? Filament::getTenant()?->id;

                                                $targetPage = Page::query()
                                                    ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
                                                    ->find($data['target_page_id']);

                                                if (! $targetPage) {
                                                    return;
                                                }

                                                $blockData = $item['data'] ?? [];

                                                // `count()`, no `max('sort_order') + 1`: el resto del
                                                // recurso reindexa `sort_order` como 0,1,2... sin
                                                // huecos (ver `saveRelationshipsUsing` arriba) — contar
                                                // los bloques existentes mantiene esa misma convención
                                                // en vez de heredar un posible hueco del máximo.
                                                $nextSortOrder = $targetPage->blocks()->count();

                                                $targetPage->blocks()->create([
                                                    'tenant_id' => $targetPage->tenant_id,
                                                    'type' => $item['type'],
                                                    'lang_iso' => $blockData['lang_iso'] ?? 'es',
                                                    'pretitle' => $blockData['pretitle'] ?? null,
                                                    'title' => $blockData['title'] ?? null,
                                                    'subtitle' => $blockData['subtitle'] ?? null,
                                                    'is_visible' => $blockData['is_visible'] ?? true,
                                                    'links' => self::unwrapFileUploadState($blockData['links'] ?? []),
                                                    'properties' => self::backfillSliderDefaults(self::unwrapFileUploadState($blockData['properties'] ?? [])),
                                                    'content' => self::unwrapFileUploadState($blockData['content'] ?? []),
                                                    'sort_order' => $nextSortOrder,
                                                ]);

                                                Notification::make()
                                                    ->title('Bloque copiado a "'.$targetPage->title.'"')
                                                    ->success()
                                                    ->send();
                                            }),
                                    ])
                                    ->columnSpanFull(),
                            ]),

                        Tabs\Tab::make('SEO / Enlaces')
                            // Footer es un PARTIAL compartido, sin URL
                            // pública propia (2026-09-02, pedido en vivo del
                            // Tech Lead: "no necesita SEO/Enlaces, nada de lo
                            // que hay en ese tab") — SEO, Open Graph, enlaces
                            // relacionados y estilo de página no aplican
                            // porque no se indexa ni se comparte como una
                            // página independiente. Mismo criterio ya usado
                            // para ocultar el slug de este tipo en la
                            // tabla (`in_array($record->type, [Footer])`,
                            // más abajo en este archivo). "Header" tenía el
                            // mismo tratamiento hasta que se descartó por
                            // completo (2026-09-11, ver ADR-053).
                            ->hidden(fn (Get $get): bool => $get('type') === PageTypeEnum::Footer->value)
                            ->schema([
                                Section::make('Metadata SEO')
                                    ->description('Título, palabras clave y descripción que Google muestra en los resultados de búsqueda.')
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
                                    ])
                                    ->collapsed(),

                                Section::make('Open Graph (Redes Sociales)')
                                    ->description('Título, descripción e imágenes con las que se ve la página al compartirla en redes sociales o chats.')
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
                                        PropertiesSchema::make(['background_type', 'background_color', 'text_color'])
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
                        ($record->type === PageTypeEnum::Footer
                            ? ''
                            : 'Slug: '.e($record->slug).' ')
                        .self::typeBadgeHtml($record)
                    )),

                // 2026-09-13 (ADR-059, addendum): "la fecha de publicacion
                // pasar debajo del estado como segunda linea" — mismo
                // patrón `description()` usado en `ApiTokens::table()`.
                // La columna suelta `published_at` de más abajo se saca
                // (queda duplicada acá).
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
                    ->description(fn (Page $record): ?string => FriendlyDate::format($record->published_at))
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
                        // 2026-09-13: el "apagar las demás Home" ya NO se
                        // maneja acá — vive centralizado en el hook
                        // `Page::booted()` (`static::saving()`), que corre
                        // sin importar el camino de guardado (este ícono,
                        // el form de editar/crear, etc.). Este action solo
                        // necesita, ahora, togglear el propio valor.
                        Actions\Action::make('toggleIsHome')
                            ->disabled(fn (Page $record): bool => ! in_array($record->type, self::IS_HOME_ELIGIBLE_TYPES, true))
                            ->action(function (Page $record): void {
                                if (! in_array($record->type, self::IS_HOME_ELIGIBLE_TYPES, true)) {
                                    return;
                                }

                                $record->update(['is_home' => ! $record->is_home]);
                            })
                    )
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
                // 2026-09-13 (ADR-059, addendum): acciones de fila agrupadas
                // en un menú desplegable (mismo patrón que `ApiTokens::
                // table()`).
                Actions\ActionGroup::make([
                    Actions\EditAction::make()
                        ->slideOver()
                        ->modalHeading(fn (Page $record) => 'Editar '.($record->type?->getLabel() ?? 'Contenido')),
                    // 2026-09-13, pedido del Tech Lead: "duplicar... para
                    // editarlos con sus properties definidos y asi heredar
                    // lo configurado anteriormente" — a diferencia de
                    // `PostResource`/`ServiceResource`, acá el contenido
                    // real vive en los `blocks` (relación aparte, no una
                    // columna de `Page`), así que `ReplicateAction` sola NO
                    // alcanza: clona la fila de `Page` pero no sus bloques.
                    // `->after()` corre una vez que el duplicado ya está
                    // guardado (`$action->getReplica()`), y clona cada
                    // `Block` original apuntándolo al `page_id` nuevo
                    // (`->replicate(['uuid'])` para que cada bloque también
                    // reciba su propio UUID nuevo, no uno copiado).
                    // `is_home`/`status`/`published_at` se resetean para que
                    // el duplicado nazca como Borrador sin ser la página de
                    // Inicio (solo puede haber una por tenant).
                    Actions\ReplicateAction::make()
                        ->label('Duplicar')
                        // 2026-09-13 (ADR-061, addendum UX): el modal de
                        // confirmación por defecto de Filament dice "Replicar
                        // :label" / botón "Replicar" — inconsistente con el
                        // label "Duplicar" del botón y sin explicar qué pasa
                        // con la copia. Se personaliza para que el modal use
                        // la misma palabra y explique el resultado (copia
                        // como borrador, lista para editar) en vez de un
                        // genérico "¿Confirmar?".
                        ->modalHeading(fn (Page $record): string => "¿Duplicar \"{$record->title}\"?")
                        ->modalDescription('Se creará una copia con el mismo contenido y bloques, guardada como borrador para que puedas revisarla y publicarla cuando quieras.')
                        ->modalSubmitActionLabel('Sí, duplicar')
                        ->modalFooterActionsAlignment('center')
                        ->excludeAttributes(['uuid', 'slug', 'is_home', 'status', 'published_at'])
                        ->beforeReplicaSaved(function (Page $record, Page $replica): void {
                            $replica->title = "{$record->title} (copia)";
                            $replica->slug = self::duplicateSlug($record);
                            $replica->status = PublishStatusEnum::Draft;
                            $replica->is_home = false;
                            $replica->published_at = null;
                        })
                        ->after(function (Page $record, Actions\ReplicateAction $action): void {
                            $replica = $action->getReplica();

                            foreach ($record->blocks as $block) {
                                $newBlock = $block->replicate(['uuid']);
                                $newBlock->page_id = $replica->id;
                                $newBlock->save();
                            }
                        })
                        // Mismo límite de plan que crear un contenido nuevo
                        // del mismo tipo (`Tenant::maxContentsPerType()`) —
                        // duplicar no debe ser una forma de esquivarlo.
                        ->disabled(fn (Page $record): bool => self::isContentLimitReached($record->type))
                        ->tooltip(fn (Page $record): ?string => self::isContentLimitReached($record->type) ? self::contentLimitMessage($record->type) : null)
                        ->before(function (Actions\ReplicateAction $action, Page $record) {
                            if (! self::isContentLimitReached($record->type)) {
                                return;
                            }

                            Notification::make()->danger()->title('Límite del plan alcanzado')->body(self::contentLimitMessage($record->type))->send();
                            $action->halt();
                        })
                        ->successNotificationTitle('Contenido duplicado'),
                    Actions\DeleteAction::make(),
                    // Restaurar / borrado permanente por fila — Filament ya
                    // trae la visibilidad correcta por default
                    // (`RestoreAction` solo aparece si el registro está
                    // papelereado, `ForceDeleteAction` idem), no hace falta
                    // condicionarla a mano.
                    Actions\RestoreAction::make(),
                    Actions\ForceDeleteAction::make(),
                ]),
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
                    ->visible(fn ($livewire): bool => ($livewire->activeTab ?? 'paginas') === 'paginas')
                    ->disabled(fn (): bool => self::isContentLimitReached(PageTypeEnum::Page))
                    ->tooltip(fn (): ?string => self::isContentLimitReached(PageTypeEnum::Page) ? self::contentLimitMessage(PageTypeEnum::Page) : null)
                    ->before(function (Actions\CreateAction $action) {
                        if (! self::isContentLimitReached(PageTypeEnum::Page)) {
                            return;
                        }

                        Notification::make()->danger()->title('Límite del plan alcanzado')->body(self::contentLimitMessage(PageTypeEnum::Page))->send();
                        $action->halt();
                    }),

                Actions\CreateAction::make('create_from_empty_state_legal')
                    ->label('Crear Aviso Legal')
                    ->icon('heroicon-o-shield-check')
                    ->model(Page::class)
                    ->form(fn (Schema $schema) => static::form($schema))
                    ->fillForm(['type' => PageTypeEnum::Legal->value, 'lang_iso' => LanguageEnum::Spanish->value, 'status' => PublishStatusEnum::Draft->value])
                    ->mutateFormDataUsing(fn (array $data): array => array_merge($data, ['type' => PageTypeEnum::Legal->value, 'lang_iso' => $data['lang_iso'] ?? LanguageEnum::Spanish->value, 'status' => $data['status'] ?? PublishStatusEnum::Draft->value]))
                    ->slideOver()
                    ->visible(fn ($livewire): bool => ($livewire->activeTab ?? 'paginas') === 'legales')
                    ->disabled(fn (): bool => self::isContentLimitReached(PageTypeEnum::Legal))
                    ->tooltip(fn (): ?string => self::isContentLimitReached(PageTypeEnum::Legal) ? self::contentLimitMessage(PageTypeEnum::Legal) : null)
                    ->before(function (Actions\CreateAction $action) {
                        if (! self::isContentLimitReached(PageTypeEnum::Legal)) {
                            return;
                        }

                        Notification::make()->danger()->title('Límite del plan alcanzado')->body(self::contentLimitMessage(PageTypeEnum::Legal))->send();
                        $action->halt();
                    }),

                // "Secciones" agrupaba Header + Footer (ver `getTabs()` en
                // `ManagePages.php`) — "Header" se descartó por completo
                // (2026-09-11, ver ADR-053: no tenía ningún mecanismo real
                // de consumo, a diferencia de Footer). El botón de estado
                // vacío crea directamente un Footer.
                Actions\CreateAction::make('create_from_empty_state_partial')
                    ->label('Crear Pie de página (Footer)')
                    ->icon('heroicon-o-rectangle-stack')
                    ->model(Page::class)
                    ->form(fn (Schema $schema) => static::form($schema))
                    ->fillForm(['type' => PageTypeEnum::Footer->value, 'lang_iso' => LanguageEnum::Spanish->value, 'status' => PublishStatusEnum::Draft->value])
                    ->mutateFormDataUsing(fn (array $data): array => array_merge($data, ['type' => PageTypeEnum::Footer->value, 'lang_iso' => $data['lang_iso'] ?? LanguageEnum::Spanish->value, 'status' => $data['status'] ?? PublishStatusEnum::Draft->value]))
                    ->slideOver()
                    ->visible(fn ($livewire): bool => ($livewire->activeTab ?? 'paginas') === 'partials')
                    ->disabled(fn (): bool => self::isContentLimitReached(PageTypeEnum::Footer))
                    ->tooltip(fn (): ?string => self::isContentLimitReached(PageTypeEnum::Footer) ? self::contentLimitMessage(PageTypeEnum::Footer) : null)
                    ->before(function (Actions\CreateAction $action) {
                        if (! self::isContentLimitReached(PageTypeEnum::Footer)) {
                            return;
                        }

                        Notification::make()->danger()->title('Límite del plan alcanzado')->body(self::contentLimitMessage(PageTypeEnum::Footer))->send();
                        $action->halt();
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
