<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\Concerns\NormalizesJsonFields;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `content`/`links` vienen precomputados por el controller
 * (`ResolvesPublicLinks::attachResolvedHeroContent()`/`attachResolvedLinks()`)
 * en los atributos transitorios `resolved_content`/`resolved_links` —
 * nunca se exponen `slider_id`/`source_id` internos (ver ADR-018).
 */
class BlockResource extends JsonResource
{
    use NormalizesJsonFields;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'type' => $this->type?->value,
            'pretitle' => $this->pretitle,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'content' => self::asObject($this->resolved_content ?? $this->content),
            'links' => $this->resolved_links ?? [],
            'properties' => self::asObject($this->properties),
            'sort_order' => $this->sort_order,
        ];
    }
}
