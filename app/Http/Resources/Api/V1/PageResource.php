<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\Concerns\NormalizesJsonFields;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Detalle completo de una `Page` (endpoint `GET pages/{slug}`), incluye
 * `blocks` ya filtrados/ordenados por el controller (`is_visible = true`,
 * `sort_order` asc vía la relación). `links` viene resuelto por
 * `ResolvesPublicLinks::attachResolvedLinks()` — sin `source_id` interno
 * (ver ADR-018).
 */
class PageResource extends JsonResource
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
            'type' => $this->type?->value,
            'is_home' => $this->is_home,
            'pretitle' => $this->pretitle,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'meta' => self::asObject($this->meta),
            'links' => $this->resolved_links ?? [],
            'properties' => self::asObject($this->properties),
            'published_at' => $this->published_at?->toISOString(),
            'blocks' => BlockResource::collection($this->whenLoaded('blocks')),
        ];
    }
}
