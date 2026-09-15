<?php

namespace App\Filament\Widgets;

use App\Models\Tenant;
use Filament\Facades\Filament;
use Filament\Widgets\AccountWidget;

/**
 * 2026-09-13, pedido del Tech Lead (Nivel 1 del plan de Dashboard): "quitar
 * el widget Filament, dejar el de bienvenida (pero agregar en un badge el
 * tipo de plan)". El "widget Filament" era `Filament\Widgets\
 * FilamentInfoWidget` (promo/versión del framework — sin valor para un
 * cliente del panel, se saca directo de `PanelCmsProvider::widgets()`). El
 * "de bienvenida" es `Filament\Widgets\AccountWidget` (saludo + nombre +
 * botón de salir) — se conserva, pero no se puede tocar su vista vendor sin
 * pisarla en `resources/views/vendor/...`, así que se extiende como clase
 * propia con vista propia (`filament.cms.widgets.welcome-widget`), que
 * reusa el mismo markup y le agrega el badge de plan (`Tenant::planLabel()`).
 * `canView()` se hereda tal cual del padre (requiere sesión autenticada).
 */
class WelcomeWidget extends AccountWidget
{
    protected string $view = 'filament.cms.widgets.welcome-widget';

    /**
     * `AccountWidget` trae `-3` por default; renumerado a `-50` junto con
     * el resto de widgets del Escritorio para dejar hueco de sobra entre
     * cada uno (`PlanStatusWidget` -40, `LeadsOverviewWidget` -30,
     * `PlanUsageWidget` -20, `RecentContactsWidget` -10) — más fácil
     * insertar uno nuevo en el medio a futuro sin tener que renumerar todo
     * de nuevo.
     */
    protected static ?int $sort = -50;

    /**
     * 2026-09-13: quedó en `'full'` por un momento (para tapar el hueco
     * que dejaba al lado, columna 1 de las 2 fijas del Dashboard, sin
     * nada en la columna 2) — revertido de inmediato: el Tech Lead pidió
     * llenar ESE hueco con contenido de verdad (`PlanStatusWidget`, plan
     * actual + botón "Mejorar plan"), no taparlo estirando el saludo.
     * Columna 1 (default de `Widget`), comparte fila con `PlanStatusWidget`
     * (columna 2).
     */
    protected int|string|array $columnSpan = 1;

    public function getPlanLabel(): ?string
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Tenant ? $tenant->planLabel() : null;
    }

    /**
     * Verde para planes pagos (mismo gate de negocio que ya usa
     * `Tenant::canPersonalizeStudioBrand()`), gris para Free/Freemium — no
     * es un estado de alerta, solo una distinción visual discreta.
     */
    public function getPlanBadgeColor(): string
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return 'gray';
        }

        return $tenant->canPersonalizeStudioBrand() ? 'success' : 'gray';
    }
}
