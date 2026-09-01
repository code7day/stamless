<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LanguageEnum;
use App\Enums\PageTypeEnum;
use App\Http\Resources\Api\V1\PageResource;
use App\Http\Resources\Api\V1\PageSummaryResource;
use App\Models\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PageController extends Controller
{
    public function index(Request $request, string $tenant_slug): JsonResponse
    {
        $this->resolveTenant($tenant_slug);

        $query = Page::query()
            ->forLanguage(LanguageEnum::Spanish)
            ->published();

        if ($type = PageTypeEnum::tryFrom((string) $request->query('type'))) {
            $query->ofType($type);
        }

        if ($request->has('is_home')) {
            $query->where('is_home', filter_var($request->query('is_home'), FILTER_VALIDATE_BOOLEAN));
        }

        $pages = $query->orderBy('title')->paginate($this->perPage($request));

        return $this->paginated($pages, PageSummaryResource::class);
    }

    public function show(string $tenant_slug, string $slug): JsonResponse
    {
        $this->resolveTenant($tenant_slug);

        $page = Page::query()
            ->forLanguage(LanguageEnum::Spanish)
            ->published()
            ->where('slug', $slug)
            ->with(['blocks' => fn ($query) => $query->where('is_visible', true)])
            ->first();

        if (! $page) {
            return $this->error('Página no encontrada.', 404, ['code' => 'not_found']);
        }

        $this->attachResolvedLinks([$page, ...$page->blocks->all()]);
        $this->attachResolvedBlockContent($page->blocks);

        return $this->success(new PageResource($page));
    }
}
