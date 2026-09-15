<?php

namespace Tests\Feature\Api\V1;

use App\Enums\BlockTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\PageTypeEnum;
use App\Enums\PublishStatusEnum;
use App\Models\Block;
use App\Models\Page;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $slug, bool $isActive = true): Tenant
    {
        return Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'is_active' => $isActive]);
    }

    private function actingAsTenant(Tenant $tenant, array $abilities = ['content:read']): User
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test-'.uniqid().'@example.com',
            'password' => 'password',
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($user, $abilities);

        return $user;
    }

    private function makeService(Tenant $tenant, array $overrides = []): Service
    {
        return Service::create(array_merge([
            'tenant_id' => $tenant->id,
            'lang_iso' => LanguageEnum::Spanish,
            'slug' => 'test-service',
            'title' => 'Test Service',
            'status' => PublishStatusEnum::Published,
            'content' => [],
            'properties' => [],
            'sort_order' => 0,
        ], $overrides));
    }

    public function test_services_are_isolated_by_tenant(): void
    {
        $tenantA = $this->makeTenant('tenant-a');
        $tenantB = $this->makeTenant('tenant-b');

        $this->makeService($tenantA, ['title' => 'Servicio A', 'slug' => 'servicio-a']);
        $this->makeService($tenantB, ['title' => 'Servicio B', 'slug' => 'servicio-b']);

        $this->actingAsTenant($tenantA);
        $this->getJson('/v1/tenant-a/services')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Servicio A');

        $this->getJson('/v1/tenant-a/services/servicio-a')
            ->assertOk()
            ->assertJsonPath('data.title', 'Servicio A');

        $this->getJson('/v1/tenant-a/services/servicio-b')
            ->assertStatus(404);
    }

    public function test_service_detail_resolves_dynamic_footer(): void
    {
        $tenant = $this->makeTenant('tenant-a');
        $this->actingAsTenant($tenant);

        // Crear una página tipo Footer
        $footer = Page::create([
            'tenant_id' => $tenant->id,
            'lang_iso' => LanguageEnum::Spanish,
            'slug' => 'footer-principal',
            'title' => 'Footer Principal',
            'type' => PageTypeEnum::Footer,
            'status' => PublishStatusEnum::Published,
        ]);

        // Crear bloques en el footer
        Block::create([
            'tenant_id' => $tenant->id,
            'page_id' => $footer->id,
            'type' => BlockTypeEnum::Cta,
            'title' => 'Contacto Rápido',
            'sort_order' => 1,
            'is_visible' => true,
        ]);

        Block::create([
            'tenant_id' => $tenant->id,
            'page_id' => $footer->id,
            'type' => BlockTypeEnum::Colophon,
            'title' => 'Enlaces Legales',
            'sort_order' => 2,
            'is_visible' => true,
        ]);

        // Crear servicio apuntando a ese footer
        $this->makeService($tenant, [
            'slug' => 'seguros-vida',
            'title' => 'Seguros de Vida',
            'properties' => [
                'footer_page_id' => $footer->id,
            ],
        ]);

        $response = $this->getJson('/v1/tenant-a/services/seguros-vida')
            ->assertOk()
            ->assertJsonPath('data.title', 'Seguros de Vida')
            ->assertJsonPath('data.footer.slug', 'footer-principal')
            ->assertJsonCount(2, 'data.footer.blocks');

        $this->assertSame('cta', $response->json('data.footer.blocks.0.type'));
        $this->assertSame('colophon', $response->json('data.footer.blocks.1.type'));
    }

    public function test_service_detail_returns_null_when_no_footer_assigned(): void
    {
        $tenant = $this->makeTenant('tenant-a');
        $this->actingAsTenant($tenant);

        $this->makeService($tenant, [
            'slug' => 'sin-footer',
            'title' => 'Sin Footer',
            'properties' => [],
        ]);

        $this->getJson('/v1/tenant-a/services/sin-footer')
            ->assertOk()
            ->assertJsonPath('data.footer', null);
    }

    public function test_service_detail_does_not_leak_footer_from_another_tenant(): void
    {
        $tenantA = $this->makeTenant('tenant-a');
        $tenantB = $this->makeTenant('tenant-b');

        // Footer pertenece al Tenant B
        $footerB = Page::create([
            'tenant_id' => $tenantB->id,
            'lang_iso' => LanguageEnum::Spanish,
            'slug' => 'footer-privado-b',
            'title' => 'Footer Privado B',
            'type' => PageTypeEnum::Footer,
            'status' => PublishStatusEnum::Published,
        ]);

        // Servicio del Tenant A intenta apuntar al footer del Tenant B
        $this->makeService($tenantA, [
            'slug' => 'servicio-a',
            'title' => 'Servicio A',
            'properties' => [
                'footer_page_id' => $footerB->id,
            ],
        ]);

        $this->actingAsTenant($tenantA);

        // TenantScope debe prevenir cargar el footer de tenant B
        $this->getJson('/v1/tenant-a/services/servicio-a')
            ->assertOk()
            ->assertJsonPath('data.footer', null);
    }
}
