<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LanguageEnum;
use App\Http\Resources\Api\V1\ServiceResource;
use App\Http\Resources\Api\V1\ServiceSummaryResource;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Primer endpoint público del módulo de Servicios (2026-09-02, ver
 * ADR-044) — hasta ahora `services` solo se consumía embebido dentro de
 * un bloque `services_grid` de una Página; esto agrega el catálogo/detalle
 * REST propios, mismo patrón que `PostController` (`published()` +
 * `forLanguage()`, paginación, `attachResolvedLinks()` para `links` sin
 * ids internos).
 */
class ServiceController extends Controller
{
    public function index(Request $request, string $tenant_slug): JsonResponse
    {
        $this->resolveTenant($tenant_slug);

        $services = Service::query()
            ->forLanguage(LanguageEnum::Spanish)
            ->published()
            ->with('image')
            ->orderBy('sort_order')
            ->paginate($this->perPage($request));

        return $this->paginated($services, ServiceSummaryResource::class);
    }

    public function show(string $tenant_slug, string $slug): JsonResponse
    {
        $this->resolveTenant($tenant_slug);

        $service = Service::query()
            ->forLanguage(LanguageEnum::Spanish)
            ->published()
            ->where('slug', $slug)
            ->with('image')
            ->first();

        if (! $service) {
            return $this->error('Servicio no encontrado.', 404, ['code' => 'not_found']);
        }

        $this->attachResolvedLinks([$service]);

        return $this->success(new ServiceResource($service));
    }
}
