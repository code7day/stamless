<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\Concerns\NormalizesJsonFields;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SliderResource extends JsonResource
{
    use NormalizesJsonFields;

    /**
     * `properties` (2026-09-01, bug real encontrado por el Tech Lead: la
     * flecha de scroll del Hero no se mostraba nunca, ni con
     * `show_scroll_indicator: true` sembrado en la DB). Cuando esa
     * property se movió de "por slide" a "por Slider" (ver PROGRESS.md,
     * tareas de esa sesión), `Hero.astro`/el seeder/`SliderResource`
     * (Filament) se actualizaron, pero este resource de la API PÚBLICA
     * quedó afuera — nunca exponía `properties` del Slider (a diferencia
     * de `SlideResource`/`BlockResource`, que sí lo hacían desde antes).
     * `slider.properties.show_scroll_indicator` en `Hero.astro` siempre
     * leía `undefined` sin importar el valor real en DB. Mismo patrón que
     * `BlockResource`/`SlideResource`: `NormalizesJsonFields::asObject()`
     * para que `properties` vacío serialice como `{}`, no `[]` (ADR-018).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'slug' => $this->slug,
            'properties' => self::asObject($this->properties),
            'slides' => SlideResource::collection($this->whenLoaded('slides')),
        ];
    }
}
