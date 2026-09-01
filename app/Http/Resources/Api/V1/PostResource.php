<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\Concerns\NormalizesJsonFields;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `links` viene resuelto por `ResolvesPublicLinks::attachResolvedLinks()`
 * — sin `source_id` interno (ver ADR-018).
 */
class PostResource extends JsonResource
{
    use NormalizesJsonFields;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'pretitle' => $this->pretitle,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'excerpt' => $this->excerpt,
            'content' => $this->content,
            'meta' => self::asObject($this->meta),
            'links' => $this->resolved_links ?? [],
            'properties' => self::asObject($this->properties),
            'published_at' => $this->published_at?->toISOString(),
            'featured_image' => MediaResource::optional($this->featuredImage),
        ];
    }
}
