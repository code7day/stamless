<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fase 6 (post-MVP) del front headless adelantada (2026-09-17, ver ADR
 * nuevo en DECISIONS.md) — dispara un rebuild+deploy automático del front
 * de un tenant vía la API de GitHub (`repository_dispatch`) cuando su
 * contenido cambia en Studio, sin que el hosting (shared, sin Node) tenga
 * que hacer nada: el build sigue corriendo en GitHub Actions, el único
 * cambio es QUIÉN lo dispara (antes: manual, "correr el workflow"; ahora:
 * este servicio, desde el propio guardado en Filament).
 *
 * No confundir con un "deploy" en el sentido de subir código de Genesis —
 * esto solo notifica al repo del FRONT del tenant (`Tenant::$deploy_repo`)
 * que hay contenido nuevo, vía el evento custom `content-updated` que su
 * workflow (`build-and-deploy.yml` en cica360, por ejemplo) escucha con
 * `on.repository_dispatch.types`.
 */
class FrontendDeployService
{
    /**
     * Debe coincidir EXACTO con `on.repository_dispatch.types` del workflow
     * del front — ver `.github/workflows/build-and-deploy.yml` en cica360.
     */
    private const EVENT_TYPE = 'content-updated';

    private const GITHUB_API_BASE = 'https://api.github.com';

    /**
     * Dispara el rebuild del front de este tenant. No-op silencioso si el
     * tenant no tiene el webhook configurado (`hasDeployWebhookConfigured()`)
     * — les toca a los callers (el Job) verificar ese gate ANTES de llamar
     * acá, pero se revalida acá también por si este servicio se usa desde
     * otro lado en el futuro (ej. un botón manual "Publicar ahora" en
     * Studio) sin pasar por el Job.
     *
     * Lanza `RequestException` en fallos HTTP — el caller (`TriggerFrontendDeploy`,
     * un Job con reintentos) decide qué hacer con eso; acá solo se loguea
     * contexto extra antes de relanzar, para que el log de fallo del Job
     * también diga DE QUÉ TENANT era.
     */
    public function dispatch(Tenant $tenant): void
    {
        if (! $tenant->hasDeployWebhookConfigured()) {
            return;
        }

        try {
            // Timeout corto explícito (2026-09-17, pedido del Tech Lead:
            // "considerar que lo que hagamos podría impactar a otros
            // clientes... el Studio será usado por muchos clientes y
            // proyectos") — sin esto, el default de Laravel (30s) podría
            // dejar un worker de cola colgado esperando a GitHub por cada
            // intento; en una cola compartida entre tenants (ver
            // `TriggerFrontendDeploy::$queue`), un proveedor externo lento
            // de UN tenant no debe poder acaparar el worker por tanto
            // tiempo a costa de los demás.
            Http::withToken($tenant->deploy_token)
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ])
                ->timeout(10)
                ->post(self::GITHUB_API_BASE."/repos/{$tenant->deploy_repo}/dispatches", [
                    'event_type' => self::EVENT_TYPE,
                ])
                ->throw();
        } catch (RequestException $exception) {
            Log::warning('FrontendDeployService: fallo al disparar repository_dispatch', [
                'tenant_id' => $tenant->id,
                'deploy_repo' => $tenant->deploy_repo,
                'status' => $exception->response?->status(),
            ]);

            throw $exception;
        }
    }
}
