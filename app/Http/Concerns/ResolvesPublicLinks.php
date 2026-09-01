<?php

namespace App\Http\Concerns;

use App\Enums\BlockTypeEnum;
use App\Models\Media;
use App\Models\Page;
use App\Models\Post;
use App\Models\Slider;
use App\Models\Testimonial;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Contrato público de responses (ADR-018): ningún `links`/`content` que
 * salga de la API expone un id interno (`source_id`, `slider_id`,
 * `image_id`, `page_id`, ...). En su lugar se resuelve a slug/uuid + datos
 * públicos, con el mismo patrón de batching por `whereIn()` que ya usa
 * `MenuController::attachResolvedHrefs` (cero N+1 sin importar cuántos
 * links/bloques/items tenga la respuesta).
 *
 * Los métodos setean atributos transitorios (`resolved_links`,
 * `resolved_content`) que los API Resources leen en vez de las columnas
 * crudas `links`/`content` — nunca se pisa la data real guardada en DB
 * (que sigue necesitando los ids internos para que Filament pueda
 * editarla).
 */
trait ResolvesPublicLinks
{
    /**
     * Campos `content.{key}_id` de nivel bloque (no dentro de `items[]`)
     * que se resuelven a un objeto Media público, por tipo de bloque.
     * `key` sin `_id` es el nombre final en la response.
     *
     * @var array<string, array<string, string>>
     */
    private const array BLOCK_MEDIA_FIELDS = [
        'heading' => ['image_desktop_id' => 'image_desktop', 'image_tablet_id' => 'image_tablet', 'image_mobile_id' => 'image_mobile'],
        'image' => ['media_id' => 'media'],
        'split' => ['media_id' => 'media'],
    ];

    /**
     * Ídem para el modo `manual` de `hero` (el modo `slider` se resuelve
     * aparte, a `slider_slug`).
     */
    private const array HERO_MANUAL_MEDIA_FIELDS = [
        'background_image_id' => 'background_image',
        'background_image_tablet_id' => 'background_image_tablet',
        'background_image_mobile_id' => 'background_image_mobile',
    ];

    /**
     * Campos `{key}_id` dentro de cada item de `content.items[]` que se
     * resuelven a un objeto Media público, por tipo de bloque.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const array ITEMS_MEDIA_FIELD = [
        'features' => ['image_id', 'image'],
        'logos' => ['media_id', 'media'],
        'services_grid' => ['image_id', 'image'],
    ];

    /**
     * @param  iterable<object{links: ?array}>  $records  Modelos con un atributo `links` (Page, Post, Block, Slide).
     */
    protected function attachResolvedLinks(iterable $records): void
    {
        $records = collect($records);

        $pageIds = [];
        $postIds = [];

        foreach ($records as $record) {
            foreach ($record->links ?? [] as $link) {
                if (($link['source_type'] ?? null) === 'page' && ! empty($link['source_id'])) {
                    $pageIds[] = $link['source_id'];
                }

                if (($link['source_type'] ?? null) === 'post' && ! empty($link['source_id'])) {
                    $postIds[] = $link['source_id'];
                }
            }
        }

        $pages = $pageIds
            ? Page::whereIn('id', $this->uniqueScalarIds($pageIds, 'links.page'))->get(['id', 'slug', 'is_home'])->keyBy('id')
            : collect();

        $posts = $postIds
            ? Post::whereIn('id', $this->uniqueScalarIds($postIds, 'links.post'))->get(['id', 'slug'])->keyBy('id')
            : collect();

        foreach ($records as $record) {
            // `->values()` antes de `->all()`: mismo motivo que los
            // `array_values()` de `attachResolvedBlockContent()` — si
            // `$record->links` tiene keys no-secuenciales (contenido legado),
            // `Collection::map()` las preserva, y el array resultante sale
            // como objeto JS (`{}`) en vez de array (`[]`) al serializar.
            // Bug real detectado (2026-09-01, tercer síntoma del mismo dato
            // corrupto): `block.links.map is not a function` en `Cta.astro`.
            $record->resolved_links = collect($record->links ?? [])
                ->map(fn (array $link) => $this->transformPublicLink($link, $pages, $posts))
                ->values()
                ->all();
        }
    }

    /**
     * @param  Collection<int, Page>  $pages
     * @param  Collection<int, Post>  $posts
     * @return array{type: string, label: ?string, source_type: string, source_slug: ?string, href: ?string, target: string}
     */
    private function transformPublicLink(array $link, Collection $pages, Collection $posts): array
    {
        $sourceType = $link['source_type'] ?? 'custom';
        $sourceSlug = null;
        $href = $link['url'] ?? null;

        if ($sourceType === 'page' && ($page = $pages->get(self::scalarOrNull($link['source_id'] ?? null)))) {
            $sourceSlug = $page->slug;
            $href = $page->is_home ? '/' : '/'.$page->slug;
        } elseif ($sourceType === 'post' && ($post = $posts->get(self::scalarOrNull($link['source_id'] ?? null)))) {
            $sourceSlug = $post->slug;
            $href = '/blog/'.$post->slug;
        }

        return [
            'type' => $link['type'] ?? 'primary',
            'label' => $link['label'] ?? null,
            'source_type' => $sourceType,
            'source_slug' => $sourceSlug,
            'href' => $href,
            'target' => $link['target'] ?? '_self',
        ];
    }

    /**
     * Resuelve TODOS los ids internos dentro de `content` de cada bloque a
     * datos públicos, sin importar el tipo de bloque ni cuántos ids
     * aparezcan:
     *
     * - `hero.content.slider_id` (modo `slider`) → `content.slider_slug`.
     * - `hero.content.background_image*_id` (modo `manual`) → objetos Media.
     * - `heading`/`image`/`split` → sus campos `*_id` de nivel bloque → objetos Media.
     * - `features`/`logos`/`services_grid` → el `*_id` de cada item en
     *   `content.items[]` → objeto Media.
     * - `services_grid` además resuelve `items[].page_id` → `page_slug` + `href`.
     * - `testimonials` (2026-08-31, ya no vive en `content.items` del
     *   formulario) → se resuelve en runtime contra la tabla real
     *   `testimonials` (`is_visible = true`, tenant-scoped via `HasTenant`),
     *   recortando/ordenando en memoria según `content.limit`/`content.order`
     *   de cada bloque, y se entrega en la misma forma `content.items[]`
     *   (`name`/`role`/`quote`/`avatar`) que ya esperaba el frontend.
     *
     * Todo en 4 queries batched (media/pages/sliders/testimonials) sin
     * importar cuántos bloques tenga la página — mismo patrón que
     * `attachResolvedLinks()`. Los testimonios se traen en una sola query
     * (todos los visibles del tenant) aunque la página tenga más de un
     * bloque `testimonials`, para no repetir el `SELECT` por bloque.
     *
     * @param  iterable<object{type: BlockTypeEnum, content: ?array}>  $blocks
     */
    protected function attachResolvedBlockContent(iterable $blocks): void
    {
        $blocks = collect($blocks);

        $mediaIds = [];
        $pageIds = [];
        $sliderIds = [];
        $hasTestimonialsBlock = false;

        foreach ($blocks as $block) {
            $content = $block->content ?? [];
            $type = $block->type?->value;

            if ($type === 'hero') {
                if (($content['mode'] ?? null) === 'slider') {
                    if (! empty($content['slider_id'])) {
                        $sliderIds[] = $content['slider_id'];
                    }
                } else {
                    foreach (array_keys(self::HERO_MANUAL_MEDIA_FIELDS) as $idKey) {
                        if (! empty($content[$idKey])) {
                            $mediaIds[] = $content[$idKey];
                        }
                    }
                }
            } elseif (isset(self::BLOCK_MEDIA_FIELDS[$type])) {
                foreach (array_keys(self::BLOCK_MEDIA_FIELDS[$type]) as $idKey) {
                    if (! empty($content[$idKey])) {
                        $mediaIds[] = $content[$idKey];
                    }
                }
            }

            if (isset(self::ITEMS_MEDIA_FIELD[$type])) {
                [$idKey] = self::ITEMS_MEDIA_FIELD[$type];

                foreach ($content['items'] ?? [] as $item) {
                    if (! empty($item[$idKey])) {
                        $mediaIds[] = $item[$idKey];
                    }
                }
            }

            if ($type === 'services_grid') {
                foreach ($content['items'] ?? [] as $item) {
                    if (! empty($item['page_id'])) {
                        $pageIds[] = $item['page_id'];
                    }
                }
            }

            if ($type === 'testimonials') {
                $hasTestimonialsBlock = true;
            }
        }

        // Tenant ya seteado por `ResolvesTenant` al inicio del request
        // (ver `App\Http\Concerns\ResolvesTenant`) — `HasTenant` filtra
        // esta query automáticamente, sin código extra acá.
        $testimonials = $hasTestimonialsBlock
            ? Testimonial::query()->where('is_visible', true)->get()
            : collect();

        foreach ($testimonials as $testimonial) {
            if ($testimonial->avatar_id) {
                $mediaIds[] = $testimonial->avatar_id;
            }
        }

        $media = $mediaIds ? Media::whereIn('id', $this->uniqueScalarIds($mediaIds, 'content.media'))->get()->keyBy('id') : collect();
        $pages = $pageIds ? Page::whereIn('id', $this->uniqueScalarIds($pageIds, 'content.page'))->get(['id', 'slug', 'is_home'])->keyBy('id') : collect();
        $sliders = $sliderIds ? Slider::whereIn('id', $this->uniqueScalarIds($sliderIds, 'content.slider'))->get(['id', 'slug'])->keyBy('id') : collect();

        foreach ($blocks as $block) {
            $block->resolved_content = $this->transformBlockContent($block, $media, $pages, $sliders, $testimonials);
        }
    }

    /**
     * @param  Collection<int, Media>  $media
     * @param  Collection<int, Page>  $pages
     * @param  Collection<int, Slider>  $sliders
     * @param  Collection<int, Testimonial>  $testimonials  Todos los visibles del tenant, sin recortar — cada bloque `testimonials` recorta acá según su propio `content.limit`/`content.order`.
     * @return array<string, mixed>
     */
    private function transformBlockContent(object $block, Collection $media, Collection $pages, Collection $sliders, Collection $testimonials = new Collection): array
    {
        $content = $block->content ?? [];
        $type = $block->type?->value;

        if ($type === 'hero') {
            if (($content['mode'] ?? null) === 'slider') {
                $slider = $sliders->get(self::scalarOrNull($content['slider_id'] ?? null));
                unset($content['slider_id']);
                $content['slider_slug'] = $slider?->slug;
            } else {
                foreach (self::HERO_MANUAL_MEDIA_FIELDS as $idKey => $newKey) {
                    $content[$newKey] = $this->resolveMediaRef($content[$idKey] ?? null, $media);
                    unset($content[$idKey]);
                }
            }
        } elseif (isset(self::BLOCK_MEDIA_FIELDS[$type])) {
            foreach (self::BLOCK_MEDIA_FIELDS[$type] as $idKey => $newKey) {
                $content[$newKey] = $this->resolveMediaRef($content[$idKey] ?? null, $media);
                unset($content[$idKey]);
            }
        }

        if (isset(self::ITEMS_MEDIA_FIELD[$type]) && isset($content['items'])) {
            [$idKey, $newKey] = self::ITEMS_MEDIA_FIELD[$type];

            // `array_values()` al final: si `content.items` en la DB tiene
            // keys no-secuenciales (contenido legado guardado antes de que
            // el Repeater normalizara al array_values() propio de Filament,
            // o tocado a mano), `array_map()` preserva esas keys tal cual —
            // y `json_encode()` de un array con keys no-secuenciales/no-int
            // sale como objeto JS (`{}`), no array (`[]`). El frontend
            // siempre espera `content.items` como array (`.filter()`,
            // `.map()`) — bug real detectado (2026-09-01, misma sesión que
            // los dos fixes de arriba): `(content.items ?? []).filter is
            // not a function` en `Logos.astro`, recién visible una vez que
            // los dos bugs anteriores dejaron de tapar este.
            $content['items'] = array_values(array_map(function (array $item) use ($idKey, $newKey, $media) {
                $item[$newKey] = $this->resolveMediaRef($item[$idKey] ?? null, $media);
                unset($item[$idKey]);

                return $item;
            }, $content['items']));
        }

        if ($type === 'services_grid' && isset($content['items'])) {
            $content['items'] = array_values(array_map(function (array $item) use ($pages) {
                $page = $pages->get(self::scalarOrNull($item['page_id'] ?? null));
                $item['page_slug'] = $page?->slug;
                $item['href'] = $page ? ($page->is_home ? '/' : '/'.$page->slug) : null;
                unset($item['page_id']);

                return $item;
            }, $content['items']));
        }

        if ($type === 'testimonials') {
            $ordered = ($content['order'] ?? 'desc') === 'asc'
                ? $testimonials->sortBy('created_at')
                : $testimonials->sortByDesc('created_at');

            $limit = max(1, (int) ($content['limit'] ?? 3));

            $content['items'] = $ordered->take($limit)->values()
                ->map(fn (Testimonial $testimonial): array => [
                    'name' => $testimonial->name,
                    'role' => $testimonial->role,
                    'quote' => $testimonial->quote,
                    'avatar' => $this->resolveMediaRef($testimonial->avatar_id, $media),
                ])
                ->all();

            unset($content['limit'], $content['order']);
        }

        if ($type === 'faq' && isset($content['items'])) {
            $content['items'] = array_map(function (array $item) {
                if (isset($item['answer'])) {
                    $item['answer'] = $this->renderRichContent($item['answer']);
                }

                return $item;
            }, $content['items']);
        }

        // Red de seguridad general (además de los `array_values()`
        // puntuales de arriba): CUALQUIER bloque con `content.items` que
        // este trait no transforma explícitamente (ej. `faq`, que pasa por
        // acá sin tocar `$content` en absoluto) sale con keys secuenciales
        // igual. Mismo motivo: un array PHP con keys no-secuenciales/no-int
        // se serializa como objeto JS (`{}`), no array (`[]`), y el
        // frontend siempre espera un array en `content.items`.
        if (isset($content['items']) && is_array($content['items'])) {
            $content['items'] = array_values($content['items']);
        }

        // `content.body` (`rich_text`/`split`/`legal_notice`, cada uno con
        // un `Forms\Components\RichEditor::make('content.body')` en
        // `PageResource.php`) se GUARDA como documento TipTap/ProseMirror
        // (JSON estructurado — `{"type":"doc","content":[...]}`), no como
        // HTML — el editor de Filament 5 lo persiste así por diseño. El
        // frontend (`RichText.astro`, `Split.astro`) siempre esperó un
        // STRING de HTML para `set:html`; nunca hubo una conversión acá,
        // así que `content.body` salía tal cual (el objeto JSON) hacia la
        // API. Bug real detectado (2026-09-01, mismo día que los fixes de
        // ids/keys de arriba, recién visible una vez que la home dejó de
        // tirar 500): `[object Object]` en pantalla en vez del texto — el
        // navegador stringifica el objeto al asignarlo como HTML. Fix:
        // `RichContentRenderer` (paquete oficial de Filament, ya en
        // `vendor/` — usa `ueberdosis/tiptap-php`) convierte el JSON a HTML
        // sanitizado (`Str::sanitizeHtml()`, protege contra XSS) ANTES de
        // salir en la response. Blindado para blocks que NO usan RichEditor
        // (ej. `cta`, `Forms\Components\Textarea::make('content.body')` —
        // ya es un string plano): `renderRichContent()` deja pasar un
        // string tal cual, sin tocarlo.
        if (isset($content['body'])) {
            $content['body'] = $this->renderRichContent($content['body']);
        }

        return $content;
    }

    /**
     * Convierte contenido de un `RichEditor` de Filament (JSON TipTap) a
     * HTML sanitizado. Si ya viene como string (un `Textarea` plano, o
     * contenido legado guardado como HTML antes de este fix), lo devuelve
     * tal cual — nunca se reprocesa un string como si fuera JSON.
     */
    private function renderRichContent(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            return RichContentRenderer::make($value)->toHtml();
        }

        return null;
    }

    /**
     * Filtra valores no-escalares antes de `array_unique()` — un solo id
     * corrupto (array anidado en vez de int/string, típicamente contenido
     * legado con una forma vieja de un campo, o un bug de guardado en
     * Studio) hace que `array_unique()` tire `ErrorException: Array to
     * string conversion` y se lleve puesto el response ENTERO de la
     * página, no solo ese bloque/link puntual. Bug real detectado
     * (2026-09-01): pasó en la página `home` de `cica360`, log con el
     * detalle en `storage/logs/laravel.log` pero sin poder identificar
     * a ciencia cierta cuál id específico venía mal (no hay acceso a la
     * DB real desde este entorno) — se optó por blindar el método en vez
     * de perseguir el dato puntual, y loguear un warning para que quede
     * rastro sin romper nada.
     *
     * @param  array<mixed>  $ids
     * @return array<int, int|string>
     */
    private function uniqueScalarIds(array $ids, string $label): array
    {
        $scalarIds = array_filter($ids, 'is_scalar');

        if (count($scalarIds) !== count($ids)) {
            Log::warning("ResolvesPublicLinks: se descartaron ids no-escalares al resolver '{$label}' — revisar el contenido crudo del bloque/link en Studio.", [
                'total' => count($ids),
                'scalar' => count($scalarIds),
            ]);
        }

        return array_values(array_unique($scalarIds));
    }

    /**
     * Contraparte de `uniqueScalarIds()` para lookups puntuales:
     * `Collection::get()` hace `array_key_exists($key, ...)` por debajo, que
     * tira `TypeError: Argument #1 ($key) must be a valid array offset
     * type` si `$key` es un array — mismo dato corrupto que `uniqueScalarIds()`
     * ya filtra para el `whereIn()`, pero acá se usa el valor CRUDO de
     * `content` para buscar en la Collection ya cargada, así que necesita
     * su propio guard. Bug real detectado (2026-09-01, misma causa que el
     * de `uniqueScalarIds()`): apareció recién DESPUÉS de blindar
     * `array_unique()` — la query ya no se rompía, pero `resolveMediaRef()`
     * seguía pasando el id crudo (el array corrupto) directo a `->get()`.
     */
    private static function scalarOrNull(mixed $value): int|string|null
    {
        return is_scalar($value) ? $value : null;
    }

    /**
     * @param  Collection<int, Media>  $media
     * @return array{uuid: string, url: string, alt_text: ?string, mime_type: ?string}|null
     */
    private function resolveMediaRef(mixed $mediaId, Collection $media): ?array
    {
        /** @var Media|null $item */
        $item = $media->get(self::scalarOrNull($mediaId));

        if (! $item) {
            return null;
        }

        return [
            'uuid' => $item->uuid,
            'url' => $item->url(),
            'alt_text' => $item->alt_text,
            'mime_type' => $item->mime_type,
        ];
    }
}
