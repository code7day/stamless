<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\ApiTokens;
use App\Filament\Resources\MediaResource;
use App\Filament\Resources\MenuResource;
use App\Filament\Resources\PageResource;
use App\Filament\Resources\PostResource;
use App\Filament\Resources\ServiceResource;
use App\Filament\Resources\SliderResource;
use App\Filament\Resources\TestimonialResource;
use App\Models\Media;
use App\Models\Menu;
use App\Models\Post;
use App\Models\Service;
use App\Models\Slider;
use App\Models\Tenant;
use App\Models\Testimonial;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * 2026-09-13, "Nivel 1" del plan de Dashboard, continuación directa del
 * pedido original del Tech Lead ("un dashboard con barras de uso... eso
 * deberían tener en cada opción de menú") — los badges de uso YA existen
 * en el sidebar y en las tabs (ver ADR de esa vuelta), pero el propio
 * Escritorio se quedó vacío tras sacar `FilamentInfoWidget`. Este widget
 * junta en un solo lugar, con barra visual (no solo el número), el mismo
 * conteo que ya calcula cada Resource para su badge — CERO lógica de
 * negocio nueva, solo reutiliza `Tenant::maxX()` + los conteos ya resueltos
 * (`PageResource::navBadgeUsage()`, `ApiTokens::activeTokensCountForTenant()`,
 * ambos vueltos `public` en esta misma vuelta para este fin).
 *
 * Vista propia (`filament.cms.widgets.plan-usage-widget`) en vez de
 * `StatsOverviewWidget`: una barra de progreso real no es un "stat card"
 * (número + descripción), así que se arma a mano igual que `WelcomeWidget`
 * — mismo criterio ya usado en esta app para casos que no encajan en los
 * componentes prearmados de Filament.
 */
class PlanUsageWidget extends Widget
{
    protected string $view = 'filament.cms.widgets.plan-usage-widget';

    /**
     * 2026-09-13, corrección del Tech Lead con captura: en `'full'` este
     * widget ocupaba las 2 columnas del Escritorio (`Dashboard::
     * getColumns()` = 2 fijo) y dejaba a `RecentContactsWidget` (columna 1
     * por default) solo en su fila, con la otra mitad vacía. Columna 1
     * (default de `Widget`) para que ambos compartan fila, uno al lado del
     * otro, cada uno ocupando una columna pareja — la grilla interna de
     * barras pasa a 1 sola columna en la vista (antes 2) para no
     * apretarlas contra el ancho más angosto.
     */
    protected int|string|array $columnSpan = 1;

    protected static ?int $sort = -20;

    /**
     * @return array<int, array{label: string, count: int, limit: ?int, url: ?string}>
     */
    public function getRows(): array
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return [];
        }

        $contentUsage = PageResource::navBadgeUsage($tenant);

        return [
            [
                'label' => 'Contenidos (Páginas / Legales / Secciones)',
                'count' => $contentUsage['count'],
                'limit' => $contentUsage['limit'],
                'url' => PageResource::getUrl(),
            ],
            [
                'label' => 'Publicaciones',
                'count' => Post::where('tenant_id', $tenant->id)->count(),
                'limit' => $tenant->maxPosts(),
                'url' => PostResource::getUrl(),
            ],
            [
                'label' => 'Servicios',
                'count' => Service::where('tenant_id', $tenant->id)->count(),
                'limit' => $tenant->maxServices(),
                'url' => ServiceResource::getUrl(),
            ],
            [
                'label' => 'Sliders (Carruseles)',
                'count' => Slider::where('tenant_id', $tenant->id)->count(),
                'limit' => $tenant->maxSliders(),
                'url' => SliderResource::getUrl(),
            ],
            [
                'label' => 'Testimonios',
                'count' => Testimonial::where('tenant_id', $tenant->id)->count(),
                'limit' => $tenant->maxTestimonials(),
                'url' => TestimonialResource::getUrl(),
            ],
            [
                'label' => 'Menús',
                'count' => Menu::where('tenant_id', $tenant->id)->count(),
                'limit' => $tenant->maxMenus(),
                'url' => MenuResource::getUrl(),
            ],
            [
                'label' => 'Multimedia',
                'count' => Media::where('tenant_id', $tenant->id)->count(),
                'limit' => $tenant->maxMedia(),
                // 2026-09-14, ADR-067: Free/Auspicio perdieron el acceso a
                // `MediaResource` (ver `Tenant::canAccessMediaLibrary()`) —
                // esta fila queda como el ÚNICO lugar donde ese tenant
                // sigue viendo su conteo/tope de medios ("que quede el
                // límite de alguna forma avisar pero no tendrán acceso",
                // pedido textual del Tech Lead). `url` en `null` para ellos
                // en vez de apuntar a una página que les daría 403 al
                // entrar — la vista (`plan-usage-widget.blade.php`) ya
                // maneja `url === null` renderizando la fila sin el wrapper
                // `<a>`, como texto informativo no clickeable.
                'url' => $tenant->canAccessMediaLibrary() ? MediaResource::getUrl() : null,
            ],
            [
                'label' => 'API Tokens activos',
                'count' => ApiTokens::activeTokensCountForTenant($tenant),
                'limit' => $tenant->maxApiTokens(),
                'url' => ApiTokens::getUrl(),
            ],
        ];
    }

    /**
     * Porcentaje de la barra, siempre entre 0 y 100 — un plan "sin límite"
     * (`$limit === null`) se muestra siempre lleno al 100% en un color
     * neutro (no hay contra qué medir el consumo, pero una barra vacía
     * daría la impresión falsa de "no estás usando nada").
     */
    public function getPercent(int $count, ?int $limit): int
    {
        if ($limit === null || $limit <= 0) {
            return 100;
        }

        return (int) min(100, round(($count / $limit) * 100));
    }

    /**
     * Mismo umbral (80% / 100%) que `FormatsUsageBadge::usageBadgeColor()`,
     * traducido a clases Tailwind literales para el relleno de la barra —
     * se usan colores CORE de Tailwind (`amber-500`/`red-500`/`gray-400`),
     * no los nombres semánticos de Filament (`warning`/`danger`/`gray`):
     * esta app ya tuvo que revertir un intento de adivinar el formato de
     * las custom properties de color de Filament en Tailwind v4 (ver
     * `sidebar-project-info.blade.php`) — los literales de la paleta
     * default de Tailwind sí se compilan de forma segura vía `@source`.
     */
    public function getBarColorClass(int $count, ?int $limit): string
    {
        if ($limit === null || $limit <= 0) {
            return 'bg-gray-400 dark:bg-gray-500';
        }

        if ($count >= $limit) {
            return 'bg-red-500';
        }

        if ($count >= (int) ceil($limit * 0.8)) {
            return 'bg-amber-500';
        }

        return 'bg-gray-400 dark:bg-gray-500';
    }
}
