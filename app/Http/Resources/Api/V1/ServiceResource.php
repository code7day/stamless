<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\Concerns\NormalizesJsonFields;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Forma completa de un `Service` (`GET /v1/{tenant}/services/{slug}`) —
 * mismo patrón que `PostResource`/`PageResource`: `links` viene resuelto
 * por `ResolvesPublicLinks::attachResolvedLinks()` (sin `source_id`
 * interno, ver ADR-018), `countries` ya resuelto a `[{iso, name}, ...]`
 * (ver `Service::countriesResolved()`, ADR-035).
 *
 * 2026-09-02 — primer endpoint público del módulo de Servicios (ADR-034
 * lo dejó explícitamente pendiente: "no se tocó services_grid ni se
 * agregó un endpoint /v1/{tenant}/services"). Ver ADR-044.
 *
 * @mixin Service
 */
class ServiceResource extends JsonResource
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
            'content' => self::asObject($this->content),
            'countries' => $this->countriesResolved(),
            'meta' => self::asObject($this->meta),
            'links' => $this->resolved_links ?? [],
            'properties' => self::asObject($this->properties),
            'published_at' => $this->published_at?->toISOString(),
            'image' => MediaResource::optional($this->image),
        ];
    }
}
