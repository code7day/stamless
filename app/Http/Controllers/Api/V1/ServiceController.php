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
            // 2026-09-14: `imageDetail` solo se carga acá (detalle), no en
            // `index()` (catálogo) — el catálogo siempre usa `image`
            // (la principal), nunca la secundaria.
            ->with(['image', 'imageDetail'])
            ->first();

        if (! $service) {
            return $this->error('Servicio no encontrado.', 404, ['code' => 'not_found']);
        }

        $this->attachResolvedLinks([$service]);
        // 2026-09-13: fallback de SEO/OG a nivel tenant, ver
        // `ResolvesPublicLinks::attachResolvedSeoMeta()`.
        $this->attachResolvedSeoMeta([$service]);

        // 2026-09-14: `content.why_choose_us.text` pasó de `Textarea` a
        // `RichEditor` en `ServiceResource.php` (Filament) — como
        // `Service::$casts()['content'] = 'array'` (jsonb), Filament lo
        // guarda como documento TipTap/ProseMirror (JSON), no HTML. Mismo
        // fix ya aplicado a `content.body` de los bloques (ver
        // `ResolvesPublicLinks::normalizeBlockContent()`): se convierte acá
        // a HTML sanitizado ANTES de salir en la response, para que el
        // front (`set:html`) no reciba `[object Object]`.
        // `renderRichContent()` es blindado — un `string` plano (contenido
        // legado, o un servicio que nunca usó negrita/enlaces) pasa tal
        // cual, sin reprocesar.
        if (isset($service->content['why_choose_us']['text'])) {
            $content = $service->content;
            $content['why_choose_us']['text'] = $this->renderRichContent($content['why_choose_us']['text']);
            $service->content = $content;
        }

        // 2026-09-15: resolución del footer dinámico elegido en Studio (ver
        // `ResolvesPublicLinks::resolveFooterPage()`). Si el servicio tiene
        // `properties.footer_page_id`, entrega el objeto {slug, blocks: [...]}
        // con todos sus bloques hijos resueltos. Si no, entrega null.
        $service->resolved_footer = $this->resolveFooterPage(
            isset($service->properties['footer_page_id']) ? (int) $service->properties['footer_page_id'] : null
        );

        return $this->success(new ServiceResource($service));
    }
}
