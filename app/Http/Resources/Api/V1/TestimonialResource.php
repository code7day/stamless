<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Testimonial;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Forma pública de un `Testimonial` (2026-09-02, agregado a pedido en vivo
 * del Tech Lead tras notar que "no hay testimonios" en el API
 * Playground/Documentation) — hasta ahora este módulo solo se consumía
 * EMBEBIDO dentro de `content.items[]` del bloque `testimonials` de una
 * Página (ver `ResolvesPublicLinks::transformBlockContent()`, mismo shape
 * `{name, role, quote, avatar}`); este resource es el mismo shape, para el
 * catálogo standalone `GET /v1/{tenant}/testimonials`.
 *
 * Sin `slug`/detalle individual a propósito: `testimonials` no tiene
 * columna `slug` (ver migración) ni caso de uso real para pedir "un solo
 * testimonio" — siempre se consume como colección.
 *
 * @mixin Testimonial
 */
class TestimonialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'role' => $this->role,
            'quote' => $this->quote,
            'avatar' => MediaResource::optional($this->avatar),
        ];
    }
}
