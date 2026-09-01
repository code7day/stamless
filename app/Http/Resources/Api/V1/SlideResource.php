<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\Concerns\NormalizesJsonFields;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `links` viene resuelto por `ResolvesPublicLinks::attachResolvedLinks()`
 * — sin `source_id` interno (ver ADR-018).
 */
class SlideResource extends JsonResource
{
    use NormalizesJsonFields;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'pretitle' => $this->pretitle,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'background_type' => $this->background_type?->value,
            'media' => [
                'image_desktop' => MediaResource::optional($this->imageDesktop),
                'image_tablet' => MediaResource::optional($this->imageTablet),
                'image_mobile' => MediaResource::optional($this->imageMobile),
                'video_desktop' => MediaResource::optional($this->videoDesktop),
                'video_mobile' => MediaResource::optional($this->videoMobile),
            ],
            'has_presentation_video' => $this->has_presentation_video,
            'presentation_youtube_id' => $this->presentation_youtube_id,
            'links' => $this->resolved_links ?? [],
            'properties' => self::asObject($this->properties),
            'sort_order' => $this->sort_order,
        ];
    }
}
