<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LanguageEnum;
use App\Enums\MenuItemTypeEnum;
use App\Http\Resources\Api\V1\MenuItemResource;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class MenuController extends Controller
{
    public function show(string $tenant_slug, string $slug): JsonResponse
    {
        $this->resolveTenant($tenant_slug);

        $menu = Menu::query()
            ->where('lang_iso', LanguageEnum::Spanish->value)
            ->where('slug', $slug)
            ->with(['items' => fn ($query) => $query->where('is_active', true)])
            ->first();

        if (! $menu) {
            return $this->error('Menú no encontrado.', 404, ['code' => 'not_found']);
        }

        $items = $menu->items;

        $this->attachResolvedHrefs($items);

        return $this->success([
            'uuid' => $menu->uuid,
            'name' => $menu->name,
            'slug' => $menu->slug,
            'items' => MenuItemResource::collection($this->buildTree($items)),
        ]);
    }

    /**
     * Arma el árbol en memoria a partir de la lista plana ya cargada
     * (`items` viene de un único query eager-loaded): cero queries extra
     * sin importar la profundidad de anidamiento.
     *
     * @param  Collection<int, MenuItem>  $items
     * @return Collection<int, MenuItem>
     */
    private function buildTree(Collection $items, ?int $parentId = null): Collection
    {
        return $items
            ->where('parent_id', $parentId)
            ->values()
            ->map(function (MenuItem $item) use ($items) {
                $item->setRelation('children', $this->buildTree($items, $item->id));

                return $item;
            });
    }

    /**
     * Resuelve `reference_id` (Page/Post) a un `href` real en dos queries
     * batched (whereIn), sin importar cuántos items tenga el menú —
     * evita N+1. `External`/`Custom` usan la columna `url` tal cual.
     *
     * De paso resuelve `is_home`: el front no debe inferir "es el link de
     * Home" comparando `href === '/'` (un item podría apuntar a la home
     * con un slug o título distinto) — se expone explícito desde la única
     * fuente de verdad (`Page::is_home`, ya administrado en el Studio).
     * Siempre `false` para Post/External/Custom.
     *
     * @param  Collection<int, MenuItem>  $items
     */
    private function attachResolvedHrefs(Collection $items): void
    {
        $pageIds = $items->where('type', MenuItemTypeEnum::Page)->pluck('reference_id')->filter()->all();
        $postIds = $items->where('type', MenuItemTypeEnum::Post)->pluck('reference_id')->filter()->all();

        $pages = $pageIds ? Page::whereIn('id', $pageIds)->get(['id', 'slug', 'is_home'])->keyBy('id') : collect();
        $posts = $postIds ? Post::whereIn('id', $postIds)->get(['id', 'slug'])->keyBy('id') : collect();

        $items->each(function (MenuItem $item) use ($pages, $posts) {
            $page = $item->type === MenuItemTypeEnum::Page ? $pages->get($item->reference_id) : null;

            $item->resolved_href = match ($item->type) {
                MenuItemTypeEnum::Page => $page ? ($page->is_home ? '/' : '/'.$page->slug) : null,
                MenuItemTypeEnum::Post => ($post = $posts->get($item->reference_id))
                    ? '/blog/'.$post->slug
                    : null,
                default => $item->url,
            };
            $item->resolved_is_home = (bool) ($page?->is_home);
        });
    }
}
