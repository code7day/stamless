<?php

namespace Tests\Feature\Filament;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantAccessRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_user_accessing_another_tenant_route_is_redirected_to_own_tenant(): void
    {
        $tenantA = Tenant::create([
            'name' => 'Cliente A',
            'slug' => 'cliente-a',
            'plan' => 'free',
            'is_active' => true,
        ]);

        $tenantB = Tenant::create([
            'name' => 'Cliente B',
            'slug' => 'cliente-b',
            'plan' => 'free',
            'is_active' => true,
        ]);

        $userA = User::factory()->create([
            'tenant_id' => $tenantA->id,
            'is_super_admin' => false,
        ]);

        $studioHost = parse_url(config('stamless.urls.studio'), PHP_URL_HOST);

        $response = $this->actingAs($userA)
            ->get("https://{$studioHost}/cliente-b");

        $response->assertRedirect("https://{$studioHost}/cliente-a");
    }

    public function test_client_user_accessing_non_existent_tenant_route_is_redirected_to_own_tenant(): void
    {
        $tenantA = Tenant::create([
            'name' => 'Cliente A',
            'slug' => 'cliente-a',
            'plan' => 'free',
            'is_active' => true,
        ]);

        $userA = User::factory()->create([
            'tenant_id' => $tenantA->id,
            'is_super_admin' => false,
        ]);

        $studioHost = parse_url(config('stamless.urls.studio'), PHP_URL_HOST);

        // Intento de acceso a un slug que no existe en BD (ej. bookmark antiguo)
        $response = $this->actingAs($userA)
            ->get("https://{$studioHost}/stamless-landing/sliders");

        $response->assertRedirect("https://{$studioHost}/cliente-a");
    }

    public function test_client_user_accessing_own_tenant_route_is_allowed(): void
    {
        $tenantA = Tenant::create([
            'name' => 'Cliente A',
            'slug' => 'cliente-a',
            'plan' => 'free',
            'is_active' => true,
        ]);

        $userA = User::factory()->create([
            'tenant_id' => $tenantA->id,
            'is_super_admin' => false,
        ]);

        $studioHost = parse_url(config('stamless.urls.studio'), PHP_URL_HOST);

        $response = $this->actingAs($userA)
            ->get("https://{$studioHost}/cliente-a");

        $response->assertOk();
    }

    public function test_super_admin_can_access_other_tenants(): void
    {
        $masterTenant = Tenant::create([
            'name' => 'Master Tenant',
            'slug' => 'stamless',
            'plan' => 'sponsorship',
            'is_active' => true,
        ]);

        $clientTenant = Tenant::create([
            'name' => 'Client Tenant',
            'slug' => 'cica360',
            'plan' => 'free',
            'is_active' => true,
        ]);

        $superAdmin = User::factory()->create([
            'tenant_id' => $masterTenant->id,
            'is_super_admin' => true,
        ]);

        $studioHost = parse_url(config('stamless.urls.studio'), PHP_URL_HOST);

        $response = $this->actingAs($superAdmin)
            ->get("https://{$studioHost}/cica360");

        $response->assertOk();
    }

    public function test_super_admin_accessing_non_existent_tenant_slug_is_redirected_to_own_tenant(): void
    {
        $masterTenant = Tenant::create([
            'name' => 'Master Tenant',
            'slug' => 'stamless',
            'plan' => 'sponsorship',
            'is_active' => true,
        ]);

        $superAdmin = User::factory()->create([
            'tenant_id' => $masterTenant->id,
            'is_super_admin' => true,
        ]);

        $studioHost = parse_url(config('stamless.urls.studio'), PHP_URL_HOST);

        $response = $this->actingAs($superAdmin)
            ->get("https://{$studioHost}/stamless-landing/sliders");

        $response->assertRedirect("https://{$studioHost}/stamless");
    }
}
