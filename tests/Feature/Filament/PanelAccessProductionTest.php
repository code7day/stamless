<?php

namespace Tests\Feature\Filament;

use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAccessProductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_access_both_platform_and_cms_panels(): void
    {
        $superAdmin = User::create([
            'name' => 'Eduardo Master',
            'email' => 'zedu77@gmail.com',
            'password' => 'password123',
            'is_super_admin' => true,
        ]);

        $platformPanel = Filament::getPanel('platform');
        $cmsPanel = Filament::getPanel('cms');

        $this->assertTrue($superAdmin->canAccessPanel($platformPanel));
        $this->assertTrue($superAdmin->canAccessPanel($cmsPanel));
    }

    public function test_tenant_user_can_access_cms_panel_but_not_platform_panel(): void
    {
        $tenant = Tenant::create([
            'name' => 'CICA360',
            'slug' => 'cica360',
            'is_active' => true,
            'plan' => 'free',
        ]);

        $tenantUser = User::create([
            'name' => 'Tenant Owner',
            'email' => 'owner@cica360.com',
            'password' => 'password123',
            'tenant_id' => $tenant->id,
            'is_super_admin' => false,
        ]);

        $platformPanel = Filament::getPanel('platform');
        $cmsPanel = Filament::getPanel('cms');

        $this->assertFalse($tenantUser->canAccessPanel($platformPanel));
        $this->assertTrue($tenantUser->canAccessPanel($cmsPanel));
    }

    public function test_user_without_tenant_and_not_super_admin_cannot_access_any_panel(): void
    {
        $orphanUser = User::create([
            'name' => 'Orphan User',
            'email' => 'orphan@example.com',
            'password' => 'password123',
            'tenant_id' => null,
            'is_super_admin' => false,
        ]);

        $platformPanel = Filament::getPanel('platform');
        $cmsPanel = Filament::getPanel('cms');

        $this->assertFalse($orphanUser->canAccessPanel($platformPanel));
        $this->assertFalse($orphanUser->canAccessPanel($cmsPanel));
    }

    public function test_logged_in_tenant_user_accessing_platform_is_redirected_to_studio(): void
    {
        $tenant = Tenant::create([
            'name' => 'CICA360',
            'slug' => 'cica360',
            'is_active' => true,
            'plan' => 'free',
        ]);

        $tenantUser = User::create([
            'name' => 'Tenant Owner',
            'email' => 'owner@cica360.com',
            'password' => 'password123',
            'tenant_id' => $tenant->id,
            'is_super_admin' => false,
        ]);

        $platformHost = parse_url(config('stamless.urls.platform'), PHP_URL_HOST);

        $response = $this->actingAs($tenantUser)
            ->get("http://{$platformHost}/");

        $response->assertRedirect(config('stamless.urls.studio'));
    }

    public function test_orphan_user_accessing_platform_receives_403(): void
    {
        $orphanUser = User::create([
            'name' => 'Orphan User',
            'email' => 'orphan@example.com',
            'password' => 'password123',
            'tenant_id' => null,
            'is_super_admin' => false,
        ]);

        $platformHost = parse_url(config('stamless.urls.platform'), PHP_URL_HOST);

        $response = $this->actingAs($orphanUser)
            ->get("http://{$platformHost}/");

        $response->assertForbidden();
    }
}
