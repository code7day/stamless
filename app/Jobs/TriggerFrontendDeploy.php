<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\FrontendDeployService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Debounce del rebuild del front (2026-09-17, ver ADR nuevo/DECISIONS.md):
 * un editor guardando contenido en Studio dispara VARIOS `saved` de
 * Eloquent en poco tiempo (autosave de campos, guardar un bloque a la vez,
 * etc.) — sin debounce, cada uno dispararía su propio build en GitHub
 * Actions, saturando el runner y publicando builds a medio terminar de
 * escribir.
 *
 * `ShouldBeUnique` + `$uniqueFor` es el mecanismo de debounce: mientras ya
 * exista un job con el mismo `uniqueId()` en cola (encolado O corriendo),
 * Laravel descarta en silencio cualquier `dispatch()` nuevo con esa misma
 * clave — no hace falta cancelar/reemplazar nada a mano. Combinado con el
 * `delay()` que aplica `DeployTriggerObserver` al encolar, el efecto neto es
 * "esperá a que dejen de guardarse cambios por `DEBOUNCE_SECONDS`, recién
 * ahí disparar UN solo build" — el patrón estándar de debounce, llevado a
 * un Job de cola en vez de un `setTimeout()` de frontend.
 */
class TriggerFrontendDeploy implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Ventana de debounce aplicada por el observer al encolar (`->delay()`).
     * Vive acá (no solo en el observer) para que `$uniqueFor` de abajo
     * siempre cubra al menos ese delay + margen de procesamiento, sin que
     * quede como un número mágico repetido en 2 archivos.
     *
     * Bajado de 90s a 30s (2026-09-18, pedido del Tech Lead: la espera se
     * sentía larga end-to-end). Sigue agrupando guardados rápidos seguidos
     * (varios bloques/campos en pocos segundos) en un solo build; con menos
     * margen que antes, pero el caso real de "guardar todo un tirón" rara
     * vez excede 30s entre guardados.
     */
    public const DEBOUNCE_SECONDS = 30;

    /**
     * Debe ser mayor a `DEBOUNCE_SECONDS` — si un job delayed todavía no
     * corrió, su "unique lock" tiene que seguir activo para que nuevos
     * `dispatch()` de ese mismo tenant sigan descartándose en silencio en
     * vez de encolar un segundo job en paralelo.
     */
    public int $uniqueFor = 90;

    public int $tries = 3;

    /**
     * Backoff simple entre reintentos (fallos de red/GitHub caído) — no
     * tiene sentido reintentar instantáneo un fallo de HTTP externo.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    /**
     * Cola dedicada (2026-09-17, pedido explícito del Tech Lead: "siempre
     * considerar que lo que hagamos podría impactar a otros clientes dentro
     * de Stamless, porque el Studio será usado por muchos clientes y
     * proyectos"). Genesis es multi-tenant: sin esto, este Job comparte la
     * cola `default` con CUALQUIER otro job que se agregue a futuro (ej. un
     * envío de mail encolado de OTRO tenant) — un tenant con `deploy_repo`
     * apuntando a un endpoint lento/caído no debe poder demorar trabajo de
     * cola de tenants que no tienen nada que ver. `queue:work` en
     * producción debe escuchar explícitamente esta cola además de
     * `default` (`--queue=default,deploys`) para que esto se procese.
     *
     * OJO (2026-09-17): NO declarar `$queue` como propiedad de clase acá —
     * `Illuminate\Bus\Queueable` ya la declara (`public $queue;`, sin
     * default). PHP exige que una propiedad de clase que "sobreescribe" una
     * de un trait tenga la firma IDÉNTICA, incluido el valor por defecto —
     * agregarle `= 'deploys'` (con o sin tipo) ya cuenta como firma distinta
     * y provoca `FatalError: ... define the same property ($queue) ...
     * However, the definition differs and is considered incompatible`. Por
     * eso se asigna en tiempo de ejecución acá en el constructor, sobre la
     * propiedad heredada del trait, en vez de redeclararla.
     */
    public function __construct(public readonly int $tenantId)
    {
        $this->queue = 'deploys';
    }

    /**
     * Clave de unicidad: UN job pendiente/corriendo por tenant, sin importar
     * cuántos modelos distintos se guardaron mientras tanto.
     */
    public function uniqueId(): string
    {
        return "trigger-frontend-deploy:{$this->tenantId}";
    }

    public function handle(FrontendDeployService $deployService): void
    {
        $tenant = Tenant::find($this->tenantId);

        if (! $tenant || ! $tenant->hasDeployWebhookConfigured()) {
            return;
        }

        $deployService->dispatch($tenant);
    }

    /**
     * Ver docblock de `FrontendDeployService::dispatch()` — ahí se loguea el
     * fallo puntual de cada intento; acá se loguea el fallo DEFINITIVO
     * (agotados los `$tries`) para que quede una sola línea clara de "esto
     * necesita atención humana" sin repetir el detalle del error HTTP.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('TriggerFrontendDeploy: agotados los reintentos, el front de este tenant no se reconstruyó', [
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
        ]);
    }
}
