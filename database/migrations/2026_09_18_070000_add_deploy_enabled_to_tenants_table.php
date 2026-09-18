<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Complementa `add_deploy_webhook_fields_to_tenants_table` (ADR
     * "Fase 6 post-MVP: deploy webhook por tenant", 2026-09-17).
     *
     * Hasta hoy `deploy_repo`/`deploy_token` los seteaba el operador de la
     * plataforma a mano vía tinker — pedido del Tech Lead (2026-09-18): que
     * el propio tenant pueda vincular su repo/token desde Preferencias en
     * Studio, con un checkbox explícito de "Automatización activa" que solo
     * se pueda tildar cuando AMBOS campos estén completos.
     *
     * Antes de este cambio, `Tenant::hasDeployWebhookConfigured()`
     * (`filled(deploy_repo) && filled(deploy_token)`) era el ÚNICO gate,
     * tanto para "¿tiene credenciales?" como para "¿debe dispararse el
     * rebuild automático al guardar contenido?" — los mismos dos conceptos.
     * Eso deja de alcanzar en cuanto el tenant administra sus propias
     * credenciales: puede querer completar el repo/token sin activar
     * todavía el disparo automático (ej. mientras termina de configurar su
     * pipeline de CI del lado del front), o pausarlo temporalmente sin
     * borrar el token. `deploy_enabled` separa esa decisión ("¿debe
     * dispararse?") de la mera presencia de credenciales ("¿PUEDE
     * dispararse?", que sigue siendo `hasDeployWebhookConfigured()`, sin
     * cambios — lo sigue usando `FrontendDeployService::dispatch()` para el
     * caso de un futuro botón manual "Publicar ahora" que no dependa de
     * este toggle). El nuevo gate combinado vive en
     * `Tenant::hasAutoDeployActive()`, consumido por
     * `DeployTriggerObserver`/`TriggerFrontendDeploy`.
     *
     * `default(false)` — mismo criterio "opt-in" del resto del mecanismo:
     * ningún tenant existente empieza a disparar deploys automáticos por el
     * simple hecho de correr esta migración, aunque ya tuviera
     * `deploy_repo`/`deploy_token` seteados por tinker antes de hoy.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('deploy_enabled')->default(false)->after('deploy_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('deploy_enabled');
        });
    }
};
