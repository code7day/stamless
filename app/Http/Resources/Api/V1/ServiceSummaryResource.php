<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Forma resumida de un `Service`, usada en el listado (`GET
 * /v1/{tenant}/services`) — mismo criterio que `PostSummaryResource`: sin
 * `content`/`links` completos, solo lo necesario para una tarjeta de
 * catálogo. `countries` ya viene resuelto a `[{iso, name}, ...]` (ver
 * `Service::countriesResolved()`, ADR-035) — el front arma la ruta de la
 * banderita a partir de `iso`, sin lógica de países en el cliente.
 *
 * @mixin Service
 */
class ServiceSummaryResource extends JsonResource
{
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
            'countries' => $this->countriesResolved(),
            'image' => MediaResource::optional($this->image),
        ];
    }
}
