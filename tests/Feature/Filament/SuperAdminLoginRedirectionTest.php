<?php

namespace Tests\Feature\Filament;

use App\Http\Responses\FilamentLoginResponse;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SuperAdminLoginRedirectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_without_tenant_redirects_to_platform_on_studio_root(): void
    {
        $superAdmin = User::create([
            'name' => 'Eduardo Master',
            'email' => 'zedu77@gmail.com',
            'password' => 'password123',
            'tenant_id' => null,
            'is_super_admin' => true,
        ]);

        Tenant::create([
            'name' => 'CICA360',
            'slug' => 'cica360',
            'is_active' => true,
            'plan' => 'free',
        ]);

        $studioHost = parse_url(config('stamless.urls.studio'), PHP_URL_HOST);
        $platformUrl = config('stamless.urls.platform');

        $response = $this->actingAs($superAdmin)
            ->get("https://{$studioHost}/");

        $response->assertRedirect($platformUrl);
    }

    public function test_super_admin_with_tenant_redirects_to_their_tenant_on_studio_root(): void
    {
        $myTenant = Tenant::create([
            'name' => 'My Project',
            'slug' => 'my-project',
            'is_active' => true,
            'plan' => 'free',
        ]);

        $superAdmin = User::create([
            'name' => 'Eduardo Master',
            'email' => 'zedu77@gmail.com',
            'password' => 'password123',
            'tenant_id' => $myTenant->id,
            'is_super_admin' => true,
        ]);

        $studioHost = parse_url(config('stamless.urls.studio'), PHP_URL_HOST);

        $response = $this->actingAs($superAdmin)
            ->get("https://{$studioHost}/");

        $response->assertRedirect("https://{$studioHost}/my-project");
    }

    public function test_super_admin_can_access_any_tenant_studio_url_directly(): void
    {
        $superAdmin = User::create([
            'name' => 'Eduardo Master',
            'email' => 'zedu77@gmail.com',
            'password' => 'password123',
            'tenant_id' => null,
            'is_super_admin' => true,
        ]);

        $tenant = Tenant::create([
            'name' => 'CICA360',
            'slug' => 'cica360',
            'is_active' => true,
            'plan' => 'free',
        ]);

        $studioHost = parse_url(config('stamless.urls.studio'), PHP_URL_HOST);

        $response = $this->actingAs($superAdmin)
            ->get("https://{$studioHost}/cica360");

        $response->assertSuccessful();
    }

    public function test_regular_tenant_user_redirects_to_their_tenant_on_studio_root(): void
    {
        $tenant = Tenant::create([
            'name' => 'CICA360',
            'slug' => 'cica360',
            'is_active' => true,
            'plan' => 'free',
        ]);

        $clientUser = User::create([
            'name' => 'Cliente User',
            'email' => 'cliente@cica360.com',
            'password' => 'password123',
            'tenant_id' => $tenant->id,
            'is_super_admin' => false,
        ]);

        $studioHost = parse_url(config('stamless.urls.studio'), PHP_URL_HOST);

        $response = $this->actingAs($clientUser)
            ->get("https://{$studioHost}/");

        $response->assertRedirect("https://{$studioHost}/cica360");
    }

    public function test_filament_login_response_redirects_super_admin_without_tenant_to_platform(): void
    {
        $superAdmin = User::create([
            'name' => 'Eduardo Master',
            'email' => 'zedu77@gmail.com',
            'password' => 'password123',
            'tenant_id' => null,
            'is_super_admin' => true,
        ]);

        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('cms'));

        $loginResponse = new FilamentLoginResponse;
        $response = $loginResponse->toResponse(Request::create('/'));

        $this->assertEquals(config('stamless.urls.platform'), $response->getTargetUrl());
    }

    public function test_user_get_tenants_and_default_tenant_return_accurate_models(): void
    {
        $tenant = Tenant::create([
            'name' => 'CICA360',
            'slug' => 'cica360',
            'is_active' => true,
            'plan' => 'free',
        ]);

        $superAdminWithoutTenant = User::create([
            'name' => 'Eduardo Master',
            'email' => 'zedu77@gmail.com',
            'password' => 'password123',
            'tenant_id' => null,
            'is_super_admin' => true,
        ]);

        $superAdminWithTenant = User::create([
            'name' => 'Eduardo Client',
            'email' => 'edu@cica360.com',
            'password' => 'password123',
            'tenant_id' => $tenant->id,
            'is_super_admin' => true,
        ]);

        $cmsPanel = Filament::getPanel('cms');

        $this->assertNull($superAdminWithoutTenant->getDefaultTenant($cmsPanel));
        $this->assertCount(0, $superAdminWithoutTenant->getTenants($cmsPanel));

        $this->assertEquals($tenant->id, $superAdminWithTenant->getDefaultTenant($cmsPanel)->id);
        $this->assertCount(1, $superAdminWithTenant->getTenants($cmsPanel));
        $this->assertEquals($tenant->id, $superAdminWithTenant->getTenants($cmsPanel)->first()->id);
    }
}
