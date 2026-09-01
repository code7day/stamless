<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\MediaResource;
use App\Models\Media;
use Illuminate\Http\JsonResponse;

class MediaController extends Controller
{
    public function show(string $tenant_slug, string $uuid): JsonResponse
    {
        $this->resolveTenant($tenant_slug);

        $media = Media::where('uuid', $uuid)->first();

        if (! $media) {
            return $this->error('Media no encontrada.', 404, ['code' => 'not_found']);
        }

        return $this->success(new MediaResource($media));
    }
}
