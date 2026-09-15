<?php

namespace App\Filament\Widgets;

use App\Models\Tenant;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * 2026-09-13, pedido del Tech Lead con captura ("falta un widget en
 * segundo orden que seria para mostrar el plan y con un boton de mejorar
 * plan") — llena el hueco que dejaba `WelcomeWidget` en columna 1 sola,
 * compartiendo la primera fila del Escritorio con contenido de verdad en
 * vez de estirar el saludo a `'full'` (primer intento, revertido de
 * inmediato en el mismo cambio).
 *
 * El botón "Mejorar plan" NO dispara ningún flujo de pago real — billing
 * está explícitamente fuera de alcance del MVP (`docs/context/TASK.md`).
 * Es un `mailto:` hacia `config('stamless.contact.sales_email')` con
 * asunto/cuerpo precompletados (nombre del tenant + plan actual), para que
 * el equipo comercial gestione el upgrade a mano — mismo criterio ya usado
 * en este proyecto de no fingir una capacidad que todavía no existe (ver
 * el propio `TASK.md`: "Billing / pasarelas de pago" sigue "Fuera de
 * alcance ahora"). Cuando exista self-serve billing, este botón se
 * reemplaza por un link a un checkout real.
 */
class PlanStatusWidget extends Widget
{
    protected string $view = 'filament.cms.widgets.plan-status-widget';

    /**
     * Columna 1 (comparte la primera fila con `WelcomeWidget`, también
     * columna 1 — ver su docblock).
     */
    protected int|string|array $columnSpan = 1;

    protected static ?int $sort = -40;

    public function getTenant(): ?Tenant
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Tenant ? $tenant : null;
    }

    /**
     * `sponsorship` es, hoy, el plan más alto del catálogo (no hay ningún
     * plan por encima todavía) — mismo gate que ya usa
     * `Tenant::canPersonalizeStudioBrand()`, reusado acá para no duplicar
     * la lista de planes "ya con beneficios pagos" en dos lugares.
     */
    public function isOnTopPlan(): bool
    {
        return $this->getTenant()?->canPersonalizeStudioBrand() ?? false;
    }

    /**
     * 2026-09-13, corrección del Tech Lead: el botón se oculta por completo
     * en el plan más alto (`isOnTopPlan()`, ver la vista) — ya no hay
     * "consulta sobre tu plan actual" para mostrar, así que esta URL solo
     * se genera (y solo tiene sentido) para tenants que SÍ pueden mejorar.
     */
    public function getUpgradeMailtoUrl(): string
    {
        $tenant = $this->getTenant();
        $salesEmail = config('stamless.contact.sales_email');

        $body = $tenant
            ? "Hola, escribo desde \"{$tenant->name}\" (plan actual: {$tenant->planLabel()})."
            : 'Hola, quisiera más información sobre los planes disponibles.';

        return 'mailto:'.$salesEmail.'?subject='.rawurlencode('Quiero mejorar mi plan').'&body='.rawurlencode($body);
    }
}
