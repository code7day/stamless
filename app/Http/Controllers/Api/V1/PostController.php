<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LanguageEnum;
use App\Http\Resources\Api\V1\PostResource;
use App\Http\Resources\Api\V1\PostSummaryResource;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostController extends Controller
{
    public function index(Request $request, string $tenant_slug): JsonResponse
    {
        $this->resolveTenant($tenant_slug);

        $posts = Post::query()
            ->forLanguage(LanguageEnum::Spanish)
            ->published()
            ->with('featuredImage')
            ->orderByDesc('published_at')
            ->paginate($this->perPage($request));

        return $this->paginated($posts, PostSummaryResource::class);
    }

    public function show(string $tenant_slug, string $slug): JsonResponse
    {
        $this->resolveTenant($tenant_slug);

        $post = Post::query()
            ->forLanguage(LanguageEnum::Spanish)
            ->published()
            ->where('slug', $slug)
            ->with('featuredImage')
            ->first();

        if (! $post) {
            return $this->error('Entrada no encontrada.', 404, ['code' => 'not_found']);
        }

        $this->attachResolvedLinks([$post]);

        return $this->success(new PostResource($post));
    }
}
