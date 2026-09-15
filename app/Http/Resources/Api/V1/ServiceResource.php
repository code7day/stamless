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
            // `resolved_meta` (fallback de tenant + `og_image_*` resueltas a
            // URL) lo setea `ResolvesPublicLinks::attachResolvedSeoMeta()` en
            // el controller — ver su docblock. `?? $this->meta` es defensivo
            // (nunca debería faltar viniendo de `ServiceController::show()`).
            'meta' => self::asObject($this->resolved_meta ?? $this->meta),
            'links' => $this->resolved_links ?? [],
            'properties' => self::asObject($this->properties),
            'published_at' => $this->published_at?->toISOString(),
            'image' => MediaResource::optional($this->image),
            // 2026-09-14: imagen secundaria/opcional, pensada para el header
            // del detalle (más panorámica). El fallback "si es null, usar
            // `image`" NO se resuelve acá — queda a criterio del frontend
            // consumidor, mismo patrón que la cadena de `ogImage` en
            // `cica360/[slug].astro`.
            'image_detail' => MediaResource::optional($this->imageDetail),
            // 2026-09-15: footer dinámico elegido en Studio (ver
            // `ServiceController::show()` y `ResolvesPublicLinks::resolveFooterPage()`).
            // Objeto `{slug, blocks: [...]}` o null si no tiene asignado.
            'footer' => $this->resolved_footer ?? null,
        ];
    }
}
