<?php

namespace App\Observers;

use App\Jobs\TriggerFrontendDeploy;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Observer genérico y sin estado, reutilizado en TODOS los modelos de
 * contenido público (Page, Post, Service, Slider, Menu, MenuItem,
 * Testimonial — ver `booted()` de cada uno) — no necesita saber nada
 * específico del modelo más allá de su `tenant_id` (garantizado por
 * `HasTenant`, que todos estos modelos ya usan), así que UNA sola clase
 * cubre los 7 en vez de 7 observers casi idénticos.
 *
 * Solo encola (`TriggerFrontendDeploy::dispatch(...)->delay(...)`), nunca
 * llama a `FrontendDeployService` directo — el Job es quien decide si el
 * tenant tiene el webhook configurado (`hasDeployWebhookConfigured()`) y
 * aplica el debounce vía `ShouldBeUnique`. Encolar sin ese gate acá sería
 * doble validación innecesaria (el Job ya lo hace) sin ganar nada, salvo
 * evitar encolar filas de más en la tabla `jobs` para tenants sin webhook
 * — por eso SÍ se revisa acá también, es barato (ya se cargó el modelo) y
 * mantiene la tabla de jobs limpia para tenants (la mayoría, hoy) sin este
 * mecanismo activado.
 */
class DeployTriggerObserver
{
    public function created(Model $model): void
    {
        $this->queueDeploy($model);
    }

    public function updated(Model $model): void
    {
        $this->queueDeploy($model);
    }

    public function deleted(Model $model): void
    {
        $this->queueDeploy($model);
    }

    private function queueDeploy(Model $model): void
    {
        $tenantId = $model->getAttribute('tenant_id');

        if (! $tenantId) {
            return;
        }

        // Lookup directo (no vía `TenantManager`, que solo está poblado en
        // contexto HTTP con middleware de resolución de tenant): este
        // observer también corre en seeders/comandos de consola, donde
        // `TenantManager` no tiene nada resuelto. `select()` acotado a las 2
        // columnas que hacen falta — no carga el tenant completo por cada
        // guardado de contenido.
        $tenant = Tenant::query()->select(['id', 'deploy_repo', 'deploy_token'])->find($tenantId);

        if (! $tenant?->hasDeployWebhookConfigured()) {
            return;
        }

        TriggerFrontendDeploy::dispatch($tenantId)
            ->delay(now()->addSeconds(TriggerFrontendDeploy::DEBOUNCE_SECONDS));
    }
}
