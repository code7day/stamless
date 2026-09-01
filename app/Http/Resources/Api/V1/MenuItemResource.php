<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `href` viene precomputado por `MenuController` (batch de 2 queries para
 * resolver `reference_id` de Page/Post a slug real, sin N+1) en el atributo
 * transitorio `resolved_href`. `is_home` viene del mismo batch, en
 * `resolved_is_home` — expone `Page::is_home` explícito para que el front
 * no tenga que inferir "es el link de Home" comparando `href === '/'`
 * (un item podría apuntar a la home con slug/título distinto). `children`
 * viene de un árbol ya armado en memoria por el controller (`setRelation`),
 * no dispara queries acá.
 */
class MenuItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'type' => $this->type?->value,
            'href' => $this->resolved_href,
            'is_home' => (bool) $this->resolved_is_home,
            'target' => $this->target?->value,
            'sort_order' => $this->sort_order,
            'children' => self::collection($this->children),
        ];
    }
}
