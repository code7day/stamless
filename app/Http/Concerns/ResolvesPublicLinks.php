<?php

namespace App\Http\Concerns;

use App\Enums\BlockTypeEnum;
use App\Enums\MenuItemTypeEnum;
use App\Http\Resources\Api\V1\Concerns\NormalizesJsonFields;
use App\Models\Block;
use App\Models\Media;
use App\Models\Menu;
use App\Models\Page;
use App\Models\Post;
use App\Models\Service;
use App\Models\Slider;
use App\Models\Testimonial;
use App\Services\TenantManager;
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
    use NormalizesJsonFields;

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
        // 2026-09-01, rediseño del bloque `cta`: imagen de fondo opcional,
        // capa superpuesta sobre `properties.background_color` — ver
        // `PageResource.php` (bloque `cta`) y `Cta.astro` en cica360.
        'cta' => ['background_image_id' => 'background_image'],
        // 2026-09-02, `colophon` gana la misma 3ª opción de fondo
        // (`background_type: image`, excluyente con color/degradado) que
        // ya tenía `cta` — ver ADR-041 y su actualización del mismo día.
        'colophon' => ['background_image_id' => 'background_image'],
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
     * Extrae `source_id` de un link crudo (`source_type`/`source_id`/`url`,
     * mismo shape en todos lados — `Page.links`, `Block.links`,
     * `colophon.content.columns[].blocks[].data.items[]`) hacia el array de
     * ids correspondiente, por referencia — helper compartido entre
     * `attachResolvedLinks()` (nivel bloque/página) y la recolección de
     * `colophon` en `attachResolvedBlockContent()` (nivel sub-bloque
     * anidado), para no duplicar esta misma condición 2 veces.
     *
     * @param  array<int, int|string>  $pageIds
     * @param  array<int, int|string>  $postIds
     */
    private function collectLinkIds(array $link, array &$pageIds, array &$postIds): void
    {
        if (($link['source_type'] ?? null) === 'page' && ! empty($link['source_id'])) {
            $pageIds[] = $link['source_id'];
        }

        if (($link['source_type'] ?? null) === 'post' && ! empty($link['source_id'])) {
            $postIds[] = $link['source_id'];
        }
    }

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
                $this->collectLinkIds($link, $pageIds, $postIds);
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
            // 2026-09-02: solo lo puebla el `link_list` de `colophon`
            // (`LinkIconEnum`, `LinkSchema::make(..., withIcon: true)`) —
            // en cualquier otro consumidor de este transform (CTAs, menús)
            // el link crudo nunca trae `icon`, así que sale `null` sin
            // efecto. Ver `LinkSchema::make()`.
            'icon' => $link['icon'] ?? null,
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
     * - `logos` además recorta/ordena `content.items[]` según
     *   `content.limit`/`content.order` del propio bloque (2026-09-01) —
     *   a diferencia de `testimonials`, sin tabla propia: el recorte es
     *   sobre el mismo array que ya vive en `content.items`.
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
     * @param  bool  $resolveFooterBlocks  Guard anti-recursión (2026-09-01, ver bloque `footer` más abajo):
     *                                     al resolver los bloques de la página REFERENCIADA por un bloque `footer`, se llama a este mismo
     *                                     método de nuevo con `false` — un `footer` jamás puede anidar OTRO `footer` (Filament ya no ofrece
     *                                     ese bloque en contenidos tipo `Footer`, ver `PageResource.php`, pero esto blinda igual contra data
     *                                     corrupta/legada sin este guard, la recursión sería infinita).
     */
    protected function attachResolvedBlockContent(iterable $blocks, bool $resolveFooterBlocks = true): void
    {
        $blocks = collect($blocks);

        $mediaIds = [];
        $pageIds = [];
        $postIds = [];
        // 2026-09-02, ver ADR-044: "Servicio" nuevo como tipo de enlace de
        // menú — mismo batching que $pageIds/$postIds, ver más abajo.
        $serviceIds = [];
        $sliderIds = [];
        $footerPageIds = [];
        $menuIds = [];
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

            // Bloque `footer` (2026-09-01, pedido del Tech Lead): referencia
            // un Content tipo `Footer` por id — mismo patrón que `hero` con
            // `slider_id`, pero acá se trae la página REFERENCIADA completa
            // (con sus propios bloques ya resueltos), no un slug suelto —
            // ver `transformBlockContent()` más abajo.
            if ($type === 'footer' && $resolveFooterBlocks && ! empty($content['footer_page_id'])) {
                $footerPageIds[] = $content['footer_page_id'];
            }

            // Bloque `footer_bottom` (2026-09-02, rediseño): `content.menu_id`
            // (lado derecho, "Mostrar menú") — solo el id acá, la resolución
            // real (items de nivel principal + hrefs) pasa más abajo, después
            // de tener los menús ya cargados (ver comentario junto a `$menus`).
            if ($type === 'footer_bottom' && ! empty($content['menu_id'])) {
                $menuIds[] = $content['menu_id'];
            }

            // Bloque `colophon` (2026-09-02, pedido del Tech Lead): estructura
            // anidada de 3 niveles (columna → sub-bloque `{type, data}` → sus
            // propios ids) que ningún mapa genérico de arriba cubre — se
            // recorre a mano acá mismo para juntar TODOS los ids de la página
            // en las mismas 4 queries batched de siempre (cero N+1 sin
            // importar cuántas columnas/sub-bloques tenga). `link_list` usa
            // el mismo shape de link que `Page.links`/`Block.links`
            // (`source_type`/`source_id`/`url`), así que sus ids van a los
            // mismos `$pageIds`/`$postIds` que ya alimentan `attachResolvedLinks()`
            // — `image_link` suma además su propio `image_id` a `$mediaIds`.
            if ($type === 'colophon') {
                foreach ($content['columns'] ?? [] as $column) {
                    foreach ($column['blocks'] ?? [] as $subBlock) {
                        $subType = $subBlock['type'] ?? null;
                        $subData = $subBlock['data'] ?? [];

                        if ($subType === 'link_list') {
                            foreach ($subData['items'] ?? [] as $link) {
                                $this->collectLinkIds($link, $pageIds, $postIds);
                            }
                        } elseif ($subType === 'image_link') {
                            if (! empty($subData['image_id'])) {
                                $mediaIds[] = $subData['image_id'];
                            }

                            foreach ($subData['links'] ?? [] as $link) {
                                $this->collectLinkIds($link, $pageIds, $postIds);
                            }
                        }
                    }
                }
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

        // `footer_bottom` > "Mostrar menú" (2026-09-02): un solo query
        // batched trae TODOS los menús referenciados con sus items de nivel
        // principal ya filtrados (`parent_id: null`, `is_active: true`,
        // ordenados) — "listar solo el nivel principal en caso tenga
        // submenus", pedido explícito del Tech Lead. Los `reference_id` de
        // esos items (Page/Post) se suman a los MISMOS `$pageIds`/`$postIds`
        // que ya alimentan `colophon`/`attachResolvedLinks()`, así que
        // `$pages`/`$posts` de abajo ya los resuelven sin una query aparte
        // — mismo criterio de batching que el resto del archivo.
        $menus = $menuIds
            ? Menu::whereIn('id', $this->uniqueScalarIds($menuIds, 'content.menu'))
                ->with(['items' => fn ($query) => $query->whereNull('parent_id')->where('is_active', true)->orderBy('sort_order')])
                ->get()
                ->keyBy('id')
            : collect();

        foreach ($menus as $menu) {
            foreach ($menu->items as $item) {
                if ($item->type === MenuItemTypeEnum::Page && $item->reference_id) {
                    $pageIds[] = $item->reference_id;
                } elseif ($item->type === MenuItemTypeEnum::Post && $item->reference_id) {
                    $postIds[] = $item->reference_id;
                } elseif ($item->type === MenuItemTypeEnum::Service && $item->reference_id) {
                    $serviceIds[] = $item->reference_id;
                }
            }
        }

        $media = $mediaIds ? Media::whereIn('id', $this->uniqueScalarIds($mediaIds, 'content.media'))->get()->keyBy('id') : collect();
        $pages = $pageIds ? Page::whereIn('id', $this->uniqueScalarIds($pageIds, 'content.page'))->get(['id', 'slug', 'is_home'])->keyBy('id') : collect();
        // Solo la necesitan los items de menú con tipo "Servicio" (ver
        // ADR-044) — mismo criterio que $posts justo abajo.
        $services = $serviceIds ? Service::whereIn('id', $this->uniqueScalarIds($serviceIds, 'content.service'))->get(['id', 'slug'])->keyBy('id') : collect();
        // Solo la necesita `colophon` (sub-bloques `link_list`/`image_link`
        // pueden apuntar a un Post, igual que cualquier otro link de la
        // app) — el resto de los tipos de bloque nunca resuelve posts acá,
        // así que hasta ahora nunca hizo falta esta query en este método
        // (`attachResolvedLinks()`, que sí la tenía, resuelve otro nivel:
        // `links` de Page/Post/Block, no `content` de bloque).
        $posts = $postIds ? Post::whereIn('id', $this->uniqueScalarIds($postIds, 'content.post'))->get(['id', 'slug'])->keyBy('id') : collect();
        $sliders = $sliderIds ? Slider::whereIn('id', $this->uniqueScalarIds($sliderIds, 'content.slider'))->get(['id', 'slug'])->keyBy('id') : collect();

        // Resuelve el `href` real de cada item de menú ya cargado arriba —
        // mismo criterio exacto que `MenuController::attachResolvedHrefs()`
        // (Page → `is_home` ? '/' : '/'.slug; Post → '/blog/'.slug;
        // External/Custom → columna `url` tal cual), sin duplicar esa
        // lógica en un trait compartido todavía (solo 2 consumidores).
        foreach ($menus as $menu) {
            foreach ($menu->items as $item) {
                $item->resolved_href = match ($item->type) {
                    MenuItemTypeEnum::Page => ($page = $pages->get($item->reference_id))
                        ? ($page->is_home ? '/' : '/'.$page->slug)
                        : null,
                    MenuItemTypeEnum::Post => ($post = $posts->get($item->reference_id))
                        ? '/blog/'.$post->slug
                        : null,
                    MenuItemTypeEnum::Service => ($service = $services->get($item->reference_id))
                        ? '/servicios/'.$service->slug
                        : null,
                    default => $item->url,
                };
            }
        }

        // A diferencia de `$pages` de arriba (solo `id`/`slug`/`is_home`,
        // usado para resolver hrefs de `links`), acá hace falta la página
        // REFERENCIADA completa con sus propios bloques visibles cargados
        // (`with(['blocks' => ...])`) — es lo que se va a resolver y
        // devolver anidado en `content.footer_page.blocks`.
        $footerPages = $footerPageIds
            ? Page::whereIn('id', $this->uniqueScalarIds($footerPageIds, 'content.footer_page'))
                ->with(['blocks' => fn ($query) => $query->where('is_visible', true)->orderBy('sort_order')])
                ->get()
                ->keyBy('id')
            : collect();

        foreach ($blocks as $block) {
            $block->resolved_content = $this->transformBlockContent($block, $media, $pages, $sliders, $testimonials, $footerPages, $resolveFooterBlocks, $posts, $menus);
        }
    }

    /**
     * @param  Collection<int, Media>  $media
     * @param  Collection<int, Page>  $pages
     * @param  Collection<int, Slider>  $sliders
     * @param  Collection<int, Testimonial>  $testimonials  Todos los visibles del tenant, sin recortar — cada bloque `testimonials` recorta acá según su propio `content.limit`/`content.order`.
     * @param  Collection<int, Page>  $footerPages  Páginas tipo `Footer` referenciadas por algún bloque `footer`, con sus propios bloques visibles ya cargados (`with('blocks')`) — ver `attachResolvedBlockContent()`.
     * @param  Collection<int, Post>  $posts  Solo la usa `colophon` (sub-bloques `link_list`/`image_link` con `source_type: post`) — ver `attachResolvedBlockContent()`.
     * @param  Collection<int, Menu>  $menus  Solo la usa `footer_bottom` ("Mostrar menú") — cada `Menu` ya trae `items` filtrados a nivel principal + activos, con `resolved_href` seteado — ver `attachResolvedBlockContent()`.
     * @return array<string, mixed>
     */
    private function transformBlockContent(object $block, Collection $media, Collection $pages, Collection $sliders, Collection $testimonials = new Collection, Collection $footerPages = new Collection, bool $resolveFooterBlocks = true, Collection $posts = new Collection, Collection $menus = new Collection): array
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

        // `logos`: límite/orden compartidos con la API (2026-09-01, pedido
        // del Tech Lead — "en el admin solo se deberia indicar cuantos se
        // listaran en el api... y el orden los mas recientes o los
        // primeros"). A diferencia de `testimonials` (bloque de abajo, que
        // resuelve `content.limit`/`content.order` en runtime contra su
        // propia tabla), acá NO hay una tabla — los logos siguen viviendo
        // en `content.items` (el `Repeater` de `PageResource.php`), así que
        // el recorte/orden se aplica directo sobre ese mismo array, ya con
        // los `media_id` resueltos a objetos `Media` (bloque de arriba).
        // Los items no tienen fecha propia: "más recientes" = el orden
        // invertido de la lista tal cual quedó armada en Studio (arriba =
        // más viejo, abajo = más nuevo — asumiendo que se van agregando al
        // final, que es el comportamiento por defecto de un `Repeater`);
        // "primeros" = la lista tal cual, sin invertir. Sin `content.limit`
        // seteado, no se recorta nada — mismo comportamiento que el bloque
        // tenía antes de que existiera este campo (cero regresión para
        // contenido ya sembrado sin el campo seteado).
        if ($type === 'logos' && isset($content['items'])) {
            if (($content['order'] ?? 'first') === 'recent') {
                $content['items'] = array_reverse($content['items']);
            }

            if (! empty($content['limit'])) {
                $content['items'] = array_slice($content['items'], 0, max(1, (int) $content['limit']));
            }

            unset($content['limit'], $content['order']);
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

        // Bloque `footer` (2026-09-01, pedido del Tech Lead): trae el
        // Content tipo `Footer` REFERENCIADO completo, con sus propios
        // bloques ya resueltos (media/links/testimonios/etc.), anidados
        // como `footer_page.blocks[]` con la MISMA forma que produce
        // `BlockResource` — así el frontend reutiliza su `BlockRenderer`
        // genérico sin lógica especial para bloques anidados.
        // `resolveFooterBlocks` es el guard anti-recursión: al resolver
        // los bloques PROPIOS de la página de footer referenciada, se
        // llama con `resolveFooterBlocks: false` para que un bloque
        // `footer` corrupto que apunte a otro footer no cause un loop
        // infinito (defensa en profundidad — la UI de Filament ya impide
        // esto al no incluir `footer` en la lista de bloques permitidos
        // para el tipo `Footer`).
        if ($type === 'footer') {
            $footerPage = $resolveFooterBlocks
                ? $footerPages->get(self::scalarOrNull($content['footer_page_id'] ?? null))
                : null;

            unset($content['footer_page_id']);

            if ($footerPage) {
                $footerBlocks = $footerPage->blocks;

                $this->attachResolvedLinks([...$footerBlocks->all()]);
                $this->attachResolvedBlockContent($footerBlocks, resolveFooterBlocks: false);

                $content['footer_page'] = [
                    'slug' => $footerPage->slug,
                    'blocks' => $footerBlocks->values()->map(fn (Block $footerBlock): array => [
                        'uuid' => $footerBlock->uuid,
                        'type' => $footerBlock->type?->value,
                        'pretitle' => $footerBlock->pretitle,
                        'title' => $footerBlock->title,
                        'subtitle' => $footerBlock->subtitle,
                        'content' => self::asObject($footerBlock->resolved_content ?? $footerBlock->content),
                        'links' => $footerBlock->resolved_links ?? [],
                        'properties' => self::asObject($footerBlock->properties),
                        'sort_order' => $footerBlock->sort_order,
                    ])->all(),
                ];
            } else {
                $content['footer_page'] = null;
            }
        }

        // Bloque `colophon` (2026-09-02): resuelve cada sub-bloque anidado
        // dentro de `content.columns[].blocks[]` — `link_list` reusa
        // `transformPublicLink()` (misma resolución page/post/url que
        // cualquier otro link de la app) sobre cada item; `image_link`
        // resuelve su `image_id` a un objeto Media (mismo `resolveMediaRef()`
        // que el resto de los bloques) y sus `links[]` con el mismo
        // `transformPublicLink()`; `social_links` no tiene ningún id que
        // resolver (`platform`/`url` viajan tal cual, el ícono lo decide el
        // frontend a partir de `platform`). `array_values()` en
        // `columns`/`blocks` por el mismo motivo que el resto del archivo:
        // si algún Repeater/Builder anidado quedó con keys no-secuenciales,
        // evita que salga como objeto JS en vez de array.
        //
        // Fix real (2026-09-02, `TypeError: (data.items ?? []).map is not a
        // function` en vivo en `Colophon.astro`): el `items` de
        // `social_links` "pasaba sin tocar" (sin `->values()`) porque no
        // necesita resolución de ids — pero el Builder anidado de
        // `colophon` en `PageResource.php` NO está bindeado por
        // `->relationship()`/`saveRelationshipsUsing()` propio, así que
        // hereda el mismo bug de origen ya documentado en ADR-037/PROGRESS
        // (el `saveRelationshipsUsing` del Builder de nivel página recibe el
        // estado crudo de Livewire, keyeado por uuid interno del widget, NO
        // por el `array_values()` que Filament aplica en su pipeline normal
        // de dehidratación) — cualquier Repeater anidado varios niveles
        // adentro (como `items` de `social_links`) puede guardarse con keys
        // no-secuenciales y salir como objeto `{}` en el JSON en vez de
        // array `[]`. `link_list`/`image_link` ya estaban blindados porque
        // `->values()->all()` es un efecto colateral de mapear con
        // `transformPublicLink()`; `social_links` no mapea nada, así que
        // necesita su propio `->values()->all()` explícito.
        if ($type === 'colophon' && isset($content['columns'])) {
            $content['columns'] = array_values(array_map(function (array $column) use ($media, $pages, $posts) {
                $column['blocks'] = array_values(array_map(function (array $subBlock) use ($media, $pages, $posts) {
                    $subType = $subBlock['type'] ?? null;
                    $data = $subBlock['data'] ?? [];

                    if ($subType === 'link_list') {
                        $data['items'] = collect($data['items'] ?? [])
                            ->map(fn (array $link) => $this->transformPublicLink($link, $pages, $posts))
                            ->values()
                            ->all();
                    } elseif ($subType === 'image_link') {
                        $data['image'] = $this->resolveMediaRef($data['image_id'] ?? null, $media);
                        unset($data['image_id']);

                        $data['links'] = collect($data['links'] ?? [])
                            ->map(fn (array $link) => $this->transformPublicLink($link, $pages, $posts))
                            ->values()
                            ->all();
                    } elseif ($subType === 'social_links') {
                        $data['items'] = collect($data['items'] ?? [])->values()->all();
                    }

                    return ['type' => $subType, 'data' => $data];
                }, $column['blocks'] ?? []));

                return $column;
            }, $content['columns']));
        }

        // Bloque `footer_bottom` (2026-09-02, rediseño completo — ver
        // PageResource.php para el porqué de cada campo):
        //
        // 1) Gate de white-label por SEGUNDA vez acá (defensa en
        //    profundidad, no confiar solo en que Filament oculte el campo
        //    en la UI) — 3 estados según el plan (ADR-043, antes solo 2):
        //      - Free/Freemium puro (`! canEditCopyright()`): `copyright_text`
        //        Y `copyright_html` se fuerzan a `null` — el FALLBACK
        //        hardcodeado de marca Stamless vive en el frontend.
        //      - Auspicio/Convenio (`isSponsorshipTier()`): lo guardado en
        //        `copyright_text` NO es el copyright final, es solo el
        //        fragmento "año + nombre" — se compone acá mismo el HTML
        //        final (`copyright_html`) envolviéndolo en la plantilla fija
        //        con "Powered by Stamless" (nunca removible en este plan), y
        //        se limpia `copyright_text` (el fragmento crudo no se expone
        //        suelto, ya quedó embebido en `copyright_html`). El
        //        fragmento se escapa con `e()` antes de insertarlo — es
        //        texto libre del tenant, la plantilla que lo rodea SÍ es
        //        HTML literal de confianza (controlado acá, no por el
        //        tenant).
        //      - Cualquier otro plan pago (blanco total): `copyright_text`
        //        queda tal cual, sin plantilla — `copyright_html` en `null`.
        // 2) `menu_id` se resuelve al `Menu` ya cargado (con sus items de
        //    nivel principal + `resolved_href`, ver `attachResolvedBlockContent()`)
        //    y se reemplaza por `menu: {name, items[]}` — nunca se expone el
        //    id interno (ADR-018, contrato público sin ids).
        if ($type === 'footer_bottom') {
            $tenant = app(TenantManager::class)->getTenant();
            $content['copyright_html'] = null;

            if (! ($tenant?->canEditCopyright() ?? false)) {
                $content['copyright_text'] = null;
            } elseif ($tenant->isSponsorshipTier()) {
                $fragment = trim((string) ($content['copyright_text'] ?? ''));
                $content['copyright_text'] = null;
                $content['copyright_html'] = $fragment !== ''
                    ? '©'.e($fragment).' - Todos los derechos son reservados <br/> Powered by <a href="https://stamless.com" target="_blank">Stamless</a>'
                    : null;
            }

            if (! empty($content['menu_id'])) {
                $menu = $menus->get($content['menu_id']);

                $content['menu'] = $menu ? [
                    'name' => $menu->name,
                    'items' => $menu->items
                        ->map(fn ($item) => ['title' => $item->title, 'href' => $item->resolved_href])
                        ->filter(fn (array $item) => filled($item['href']) && filled($item['title']))
                        ->values()
                        ->all(),
                ] : null;
            }

            unset($content['menu_id']);
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
