<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Versión liviana de `Page` para el listado (`GET pages`): sin
 * `blocks`/`meta`/`properties`/`links`, para mantener el payload chico en
 * listados paginados.
 */
class PageSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'type' => $this->type?->value,
            'is_home' => $this->is_home,
            'pretitle' => $this->pretitle,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'published_at' => $this->published_at?->toISOString(),
        ];
    }
}
