<?php

namespace App\Filament\Widgets;

use App\Enums\PageTypeEnum;
use App\Filament\Resources\PageResource;
use App\Filament\Resources\PostResource;
use App\Filament\Resources\ServiceResource;
use App\Filament\Resources\TestimonialResource;
use App\Models\Page;
use App\Models\Post;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\Testimonial;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * 2026-09-18, pedido del Tech Lead: "crear un widget con los últimos
 * cambios, ya sea en contenidos por tipo página, legales, secciones, y
 * blog, servicios, testimonios, mostrar los 10 últimos contenidos que
 * fueron actualizados". Une los 4 modelos de contenido del MVP (`Page` —
 * cubre Página/Landing/Legal/Footer vía `PageTypeEnum`, `Post`, `Service`,
 * `Testimonial`) en una sola lista ordenada por `updated_at`, algo que
 * ninguno de los 4 Resources muestra combinado hoy (cada uno vive en su
 * propia pantalla). Vista propia (`filament.cms.widgets.recent-content
 * -changes-widget`), mismo criterio ya usado en esta app para datos que no
 * encajan en un solo `Table`/Eloquent query (`PlanUsageWidget`,
 * `WelcomeWidget`) — acá el motivo es más fuerte todavía: la fuente son 4
 * modelos DISTINTOS, imposible de expresar como un único `Builder` sin una
 * migración a una tabla polimórfica que está fuera de alcance de este
 * pedido puntual.
 *
 * Corrección del mismo Tech Lead sobre el alcance: "que tengan acceso los
 * roles de marketing, editores y redactores" — junto con Admin/Soporte,
 * los 5 roles ven este widget (a diferencia de `LeadsOverviewWidget`/
 * `RecentContactsWidget`, gateados a Contactos+Formularios). Cada tipo de
 * contenido se filtra igual por su Policy real (`can('viewAny', X::class)`)
 * en vez de hardcodear la lista de roles — hoy los 5 roles tienen acceso de
 * lectura a los 4 tipos (`TenantRolePolicy`, ver ADR-078), así que en la
 * práctica todos ven la lista completa; si a futuro un tipo de contenido se
 * le retira a algún rol, este widget se ajusta solo.
 *
 * **Link de cada item**: abre directo el `EditAction` (slide-over) del
 * registro en su Resource, no solo la pantalla índice — mismo mecanismo
 * que usa el buscador global nativo de Filament (`HasGlobalSearch::
 * getGlobalSearchResultUrl()`, ver `vendor/filament/filament/src/Resources/
 * Resource/Concerns/HasGlobalSearch.php`): `Resource::getUrl(parameters:
 * ['tableAction' => 'edit', 'tableActionRecord' => $record])` deep-linkea
 * a la acción de tabla por su nombre + el record, sin necesitar una ruta
 * `/edit/{record}` propia (los 4 Resources acá usan el patrón "Manage" de
 * una sola página). Los 4 `EditAction::make()` de estos Resources usan el
 * nombre default `'edit'` (confirmado, ninguno lo sobreescribe).
 */
class RecentContentChangesWidget extends Widget
{
    protected string $view = 'filament.cms.widgets.recent-content-changes-widget';

    /**
     * Comparte fila con `PlanUsageWidget`/`RecentContactsWidget` (columna
     * 1, grilla de 2 columnas del Escritorio).
     */
    protected int|string|array $columnSpan = 1;

    protected static ?int $sort = -15;

    public static function canView(): bool
    {
        if (! Filament::getTenant() instanceof Tenant) {
            return false;
        }

        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $user->can('viewAny', Page::class)
            || $user->can('viewAny', Post::class)
            || $user->can('viewAny', Service::class)
            || $user->can('viewAny', Testimonial::class);
    }

    /**
     * Trae hasta 10 registros MÁS RECIENTES de cada tipo (acotado por rol
     * vía Policy) y se queda con los 10 más recientes del conjunto
     * combinado — suficiente para un tenant típico del MVP (decenas de
     * registros por tipo, no miles); una unión SQL real (`UNION ALL`
     * sobre 4 tablas con columnas distintas) sería una optimización
     * prematura para este alcance.
     *
     * @return array<int, array{title: string, type: string, color: string, icon: string, url: string, updated_at: \Illuminate\Support\Carbon}>
     */
    public function getItems(): array
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return [];
        }

        $user = auth()->user();

        if (! $user) {
            return [];
        }

        $items = collect();

        if ($user->can('viewAny', Page::class)) {
            $items = $items->concat(
                Page::query()
                    ->where('tenant_id', $tenant->id)
                    ->latest('updated_at')
                    ->limit(10)
                    ->get()
                    ->map(fn (Page $page): array => [
                        'title' => $page->title,
                        'type' => $page->type instanceof PageTypeEnum ? $page->type->getLabel() : 'Página',
                        'color' => match ($page->type) {
                            PageTypeEnum::Legal => 'warning',
                            PageTypeEnum::Footer => 'gray',
                            PageTypeEnum::Landing => 'info',
                            default => 'primary',
                        },
                        'icon' => 'heroicon-o-document-text',
                        'url' => PageResource::getUrl(parameters: [
                            'tableAction' => 'edit',
                            'tableActionRecord' => $page,
                        ]),
                        'updated_at' => $page->updated_at,
                    ])
            );
        }

        if ($user->can('viewAny', Post::class)) {
            $items = $items->concat(
                Post::query()
                    ->where('tenant_id', $tenant->id)
                    ->latest('updated_at')
                    ->limit(10)
                    ->get()
                    ->map(fn (Post $post): array => [
                        'title' => $post->title,
                        'type' => 'Blog',
                        'color' => 'success',
                        'icon' => 'heroicon-o-document-duplicate',
                        'url' => PostResource::getUrl(parameters: [
                            'tableAction' => 'edit',
                            'tableActionRecord' => $post,
                        ]),
                        'updated_at' => $post->updated_at,
                    ])
            );
        }

        if ($user->can('viewAny', Service::class)) {
            $items = $items->concat(
                Service::query()
                    ->where('tenant_id', $tenant->id)
                    ->latest('updated_at')
                    ->limit(10)
                    ->get()
                    ->map(fn (Service $service): array => [
                        'title' => $service->title,
                        'type' => 'Servicio',
                        'color' => 'amber',
                        'icon' => 'heroicon-o-briefcase',
                        'url' => ServiceResource::getUrl(parameters: [
                            'tableAction' => 'edit',
                            'tableActionRecord' => $service,
                        ]),
                        'updated_at' => $service->updated_at,
                    ])
            );
        }

        if ($user->can('viewAny', Testimonial::class)) {
            $items = $items->concat(
                Testimonial::query()
                    ->where('tenant_id', $tenant->id)
                    ->latest('updated_at')
                    ->limit(10)
                    ->get()
                    ->map(fn (Testimonial $testimonial): array => [
                        'title' => $testimonial->name,
                        'type' => 'Testimonio',
                        'color' => 'purple',
                        'icon' => 'heroicon-o-chat-bubble-left-right',
                        'url' => TestimonialResource::getUrl(parameters: [
                            'tableAction' => 'edit',
                            'tableActionRecord' => $testimonial,
                        ]),
                        'updated_at' => $testimonial->updated_at,
                    ])
            );
        }

        return $items
            ->sortByDesc('updated_at')
            ->take(10)
            ->values()
            ->all();
    }
}
