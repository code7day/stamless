<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\TestimonialResource;
use App\Models\Testimonial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Primer endpoint público del módulo de Testimonios (2026-09-02) — hasta
 * ahora `testimonials` solo se consumía embebido dentro de un bloque
 * `testimonials` de una Página; esto agrega el catálogo standalone, mismo
 * patrón que `ServiceController`/`PostController` (paginación, `is_visible`
 * como gate público — equivalente al `published()` de Post/Service, ver
 * `App\Models\Testimonial`).
 *
 * Solo `index()` a propósito: no hay `show()` por slug/uuid — el modelo no
 * tiene `slug` (ver migración de `testimonials`) y no existe un caso de uso
 * real de "un testimonio individual" fuera de la colección completa.
 */
class TestimonialController extends Controller
{
    public function index(Request $request, string $tenant_slug): JsonResponse
    {
        $this->resolveTenant($tenant_slug);

        $testimonials = Testimonial::query()
            ->where('is_visible', true)
            ->with('avatar')
            ->orderBy('sort_order')
            ->paginate($this->perPage($request));

        return $this->paginated($testimonials, TestimonialResource::class);
    }
}
