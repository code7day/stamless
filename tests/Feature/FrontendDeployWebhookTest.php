<?php

namespace Tests\Feature;

use App\Enums\BlockTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\PageTypeEnum;
use App\Enums\PublishStatusEnum;
use App\Jobs\TriggerFrontendDeploy;
use App\Models\Block;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Page;
use App\Models\Setting;
use App\Models\Tenant;
use App\Services\FrontendDeployService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 6 (post-MVP) adelantada, 2026-09-17 — ver ADR nuevo en DECISIONS.md
 * y `Page::booted()`/`DeployTriggerObserver`/`TriggerFrontendDeploy`/
 * `FrontendDeployService`. Cubre las 2 (ahora 3) puertas del mecanismo:
 *   1) `Tenant::hasDeployWebhookConfigured()` — ¿tiene credenciales? (repo
 *      + token, sin importar el checkbox de automatización).
 *   1b) `Tenant::hasAutoDeployActive()` (2026-09-18, 2da actualización) —
 *      el gate REAL de disparo automático: credenciales + `deploy_enabled`.
 *   2) El observer NO encola nada para un tenant sin la automatización
 *      activa (sin credenciales, O con credenciales pero el checkbox
 *      apagado), y SÍ encola (con el delay de debounce correcto) cuando
 *      ambas condiciones se cumplen.
 *   3) `FrontendDeployService::dispatch()` arma el request HTTP correcto
 *      contra la API de GitHub — y es un no-op si el tenant no tiene
 *      credenciales (sigue usando `hasDeployWebhookConfigured()` sin
 *      cambios, no `hasAutoDeployActive()` — ver docblock del método en
 *      `Tenant.php`: es la capacidad técnica, no la política de
 *      automatización, pensando en un futuro botón manual).
 *
 * `makeTenant(withWebhook: true)` arma un tenant "completamente operativo"
 * (credenciales Y `deploy_enabled = true`) — sigue siendo el fixture que
 * usa la mayoría de los tests de abajo, que verifican el disparo real, no
 * solo la presencia de credenciales.
 */
class FrontendDeployWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(bool $withWebhook): Tenant
    {
        return Tenant::create([
            'name' => 'Tenant Deploy Test',
            'slug' => 'tenant-deploy-test-'.uniqid(),
            'deploy_repo' => $withWebhook ? 'eduflores/cica360' : null,
            'deploy_token' => $withWebhook ? 'ghp_faketoken123' : null,
            'deploy_enabled' => $withWebhook,
        ]);
    }

    public function test_has_deploy_webhook_configured_requires_both_fields(): void
    {
        $none = $this->makeTenant(withWebhook: false);
        $both = $this->makeTenant(withWebhook: true);

        $onlyRepo = Tenant::create([
            'name' => 'Tenant Only Repo',
            'slug' => 'tenant-only-repo-'.uniqid(),
            'deploy_repo' => 'eduflores/cica360',
        ]);

        $this->assertFalse($none->hasDeployWebhookConfigured());
        $this->assertFalse($onlyRepo->hasDeployWebhookConfigured());
        $this->assertTrue($both->hasDeployWebhookConfigured());
    }

    /**
     * 2026-09-18 (2da actualización): tener repo+token cargados YA NO
     * alcanza para que se dispare el rebuild automático — el tenant tiene
     * que además tildar "Automatización activa" (`deploy_enabled`) desde
     * Preferencias. `hasDeployWebhookConfigured()` (credenciales) sigue
     * dando `true` en el caso "configurado pero apagado" — son gates
     * distintos a propósito, ver docblocks en `Tenant.php`.
     */
    public function test_has_auto_deploy_active_requires_enabled_flag_on_top_of_credentials(): void
    {
        $configuredButDisabled = Tenant::create([
            'name' => 'Tenant Configured Disabled',
            'slug' => 'tenant-configured-disabled-'.uniqid(),
            'deploy_repo' => 'eduflores/cica360',
            'deploy_token' => 'ghp_faketoken123',
            'deploy_enabled' => false,
        ]);

        $configuredAndEnabled = $this->makeTenant(withWebhook: true);

        $this->assertTrue($configuredButDisabled->hasDeployWebhookConfigured());
        $this->assertFalse($configuredButDisabled->hasAutoDeployActive());
        $this->assertTrue($configuredAndEnabled->hasAutoDeployActive());
    }

    public function test_saving_content_without_webhook_configured_does_not_queue_deploy(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant(withWebhook: false);

        Page::create([
            'tenant_id' => $tenant->id,
            'title' => 'Home',
            'slug' => 'home',
            'lang_iso' => LanguageEnum::Spanish,
            'type' => PageTypeEnum::Page,
            'status' => PublishStatusEnum::Published,
        ]);

        Queue::assertNotPushed(TriggerFrontendDeploy::class);
    }

    /**
     * 2026-09-18 (2da actualización): el caso nuevo que separa
     * `hasDeployWebhookConfigured()` de `hasAutoDeployActive()` — un tenant
     * con repo+token cargados (ej. recién los vinculó desde Preferencias)
     * pero SIN tildar "Automatización activa" todavía no debe disparar
     * nada al guardar contenido.
     */
    public function test_saving_content_with_credentials_but_automation_disabled_does_not_queue_deploy(): void
    {
        Queue::fake();

        $tenant = Tenant::create([
            'name' => 'Tenant Credentials Only',
            'slug' => 'tenant-credentials-only-'.uniqid(),
            'deploy_repo' => 'eduflores/cica360',
            'deploy_token' => 'ghp_faketoken123',
            'deploy_enabled' => false,
        ]);

        Page::create([
            'tenant_id' => $tenant->id,
            'title' => 'Home',
            'slug' => 'home',
            'lang_iso' => LanguageEnum::Spanish,
            'type' => PageTypeEnum::Page,
            'status' => PublishStatusEnum::Published,
        ]);

        Queue::assertNotPushed(TriggerFrontendDeploy::class);
    }

    public function test_saving_content_with_webhook_configured_queues_debounced_deploy(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant(withWebhook: true);

        Page::create([
            'tenant_id' => $tenant->id,
            'title' => 'Home',
            'slug' => 'home',
            'lang_iso' => LanguageEnum::Spanish,
            'type' => PageTypeEnum::Page,
            'status' => PublishStatusEnum::Published,
        ]);

        Queue::assertPushed(TriggerFrontendDeploy::class, function (TriggerFrontendDeploy $job) use ($tenant) {
            return $job->tenantId === $tenant->id;
        });
    }

    public function test_saving_setting_with_webhook_configured_also_queues_deploy(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant(withWebhook: true);

        Setting::create([
            'tenant_id' => $tenant->id,
            'key' => 'seo.default_title',
            'value' => 'CICA360',
            'type' => 'string',
        ]);

        Queue::assertPushed(TriggerFrontendDeploy::class);
    }

    public function test_deleting_content_with_webhook_configured_also_queues_deploy(): void
    {
        $tenant = $this->makeTenant(withWebhook: true);

        $page = Page::create([
            'tenant_id' => $tenant->id,
            'title' => 'Home',
            'slug' => 'home',
            'lang_iso' => LanguageEnum::Spanish,
            'type' => PageTypeEnum::Page,
            'status' => PublishStatusEnum::Published,
        ]);

        Queue::fake();

        $page->delete();

        Queue::assertPushed(TriggerFrontendDeploy::class);
    }

    public function test_frontend_deploy_service_dispatches_repository_dispatch_event(): void
    {
        Http::fake([
            'api.github.com/repos/eduflores/cica360/dispatches' => Http::response([], 204),
        ]);

        $tenant = $this->makeTenant(withWebhook: true);

        app(FrontendDeployService::class)->dispatch($tenant);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.github.com/repos/eduflores/cica360/dispatches'
                && $request->method() === 'POST'
                && $request['event_type'] === 'content-updated'
                && $request->hasHeader('Authorization', 'Bearer ghp_faketoken123');
        });
    }

    public function test_frontend_deploy_service_is_noop_without_webhook_configured(): void
    {
        Http::fake();

        $tenant = $this->makeTenant(withWebhook: false);

        app(FrontendDeployService::class)->dispatch($tenant);

        Http::assertNothingSent();
    }

    /**
     * Bug real reportado por el Tech Lead (2026-09-18): guardó cambios en
     * un bloque de footer/colophon y el deploy nunca se disparó. `Block`
     * se había quedado afuera del observer que sí tienen Page/Post/etc.
     * — cubre exactamente ese caso: guardar/borrar un Block DIRECTO (no vía
     * el `save()` de la Page padre), que es lo que hace el Repeater de
     * Filament.
     */
    public function test_saving_block_directly_with_webhook_configured_queues_deploy(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant(withWebhook: true);

        $page = Page::create([
            'tenant_id' => $tenant->id,
            'title' => 'Home',
            'slug' => 'home',
            'lang_iso' => LanguageEnum::Spanish,
            'type' => PageTypeEnum::Page,
            'status' => PublishStatusEnum::Published,
        ]);

        Queue::fake(); // reset: el create() de arriba ya encoló el suyo

        Block::create([
            'tenant_id' => $tenant->id,
            'page_id' => $page->id,
            'lang_iso' => LanguageEnum::Spanish,
            'type' => BlockTypeEnum::Colophon,
            'sort_order' => 1,
            'is_visible' => true,
        ]);

        Queue::assertPushed(TriggerFrontendDeploy::class, function (TriggerFrontendDeploy $job) use ($tenant) {
            return $job->tenantId === $tenant->id;
        });
    }

    public function test_deleting_block_with_webhook_configured_queues_deploy(): void
    {
        $tenant = $this->makeTenant(withWebhook: true);

        $page = Page::create([
            'tenant_id' => $tenant->id,
            'title' => 'Home',
            'slug' => 'home',
            'lang_iso' => LanguageEnum::Spanish,
            'type' => PageTypeEnum::Page,
            'status' => PublishStatusEnum::Published,
        ]);

        $block = Block::create([
            'tenant_id' => $tenant->id,
            'page_id' => $page->id,
            'lang_iso' => LanguageEnum::Spanish,
            'type' => BlockTypeEnum::Colophon,
            'sort_order' => 1,
            'is_visible' => true,
        ]);

        Queue::fake();

        $block->delete();

        Queue::assertPushed(TriggerFrontendDeploy::class);
    }

    /**
     * `FormField` no tiene columna `tenant_id` propia -- se resuelve vía el
     * accessor `tenantId()` que lee `form.tenant_id`. Cubre que ese
     * resuelva bien y el trigger se dispare igual.
     */
    public function test_saving_form_field_with_webhook_configured_queues_deploy(): void
    {
        $tenant = $this->makeTenant(withWebhook: true);

        Queue::fake();

        $form = Form::create([
            'tenant_id' => $tenant->id,
            'lang_iso' => LanguageEnum::Spanish,
            'name' => 'Contacto',
            'slug' => 'contacto',
            'is_active' => true,
        ]);

        Queue::fake(); // reset: el create() de arriba ya encoló el suyo

        FormField::create([
            'form_id' => $form->id,
            'label' => 'Email',
            'name' => 'email',
            'type' => 'email',
            'is_required' => true,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        Queue::assertPushed(TriggerFrontendDeploy::class, function (TriggerFrontendDeploy $job) use ($tenant) {
            return $job->tenantId === $tenant->id;
        });
    }
}
