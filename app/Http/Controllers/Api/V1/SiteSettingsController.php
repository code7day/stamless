<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;

/**
 * Config de SITIO completo (no de una página/post/servicio puntual) — ver
 * genesis ADR-066. Primer y único consumidor por ahora: IDs de tracking
 * (Meta Pixel / Google Tag Manager) cargados en Preferencias > Integraciones
 * (`App\Filament\Pages\Preferences`), inyectados por
 * `cica360/src/layouts/BaseLayout.astro` con carga diferida (ver ADR-066).
 *
 * A propósito NO es un dump genérico de `Setting::pluck('value', 'key')`:
 * expone solo un whitelist explícito de claves pensadas para consumo
 * público — la tabla `settings` es de uso general y podría llegar a alojar
 * configuración interna/sensible en el futuro que nunca debería salir por
 * la API pública. Cualquier clave nueva que se quiera exponer acá debe
 * agregarse a mano, nunca "todo lo que haya".
 */
class SiteSettingsController extends Controller
{
    public function tracking(string $tenant_slug): JsonResponse
    {
        $this->resolveTenant($tenant_slug);

        return $this->success([
            'meta_pixel_id' => setting('tracking.meta_pixel_id'),
            'gtm_id' => setting('tracking.gtm_id'),
        ]);
    }
}
