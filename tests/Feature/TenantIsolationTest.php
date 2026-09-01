<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected TenantManager $tenantManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantManager = app(TenantManager::class);
    }

    public function test_tenant_scoping_applies_correctly(): void
    {
        // 1. Create two tenants
        $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b']);

        // 2. Create users under each tenant
        $userA = User::create([
            'name' => 'User A',
            'email' => 'user-a@example.com',
            'password' => bcrypt('password'),
            'tenant_id' => $tenantA->id,
        ]);

        $userB = User::create([
            'name' => 'User B',
            'email' => 'user-b@example.com',
            'password' => bcrypt('password'),
            'tenant_id' => $tenantB->id,
        ]);

        // 3. Verify that without an active tenant context, we can see both users
        $this->tenantManager->setTenant(null);
        $this->assertCount(2, User::all());

        // 4. Verify scoping to Tenant A
        $this->tenantManager->setTenant($tenantA);
        $usersForA = User::all();
        $this->assertCount(1, $usersForA);
        $this->assertEquals($userA->id, $usersForA->first()->id);

        // 5. Verify scoping to Tenant B
        $this->tenantManager->setTenant($tenantB);
        $usersForB = User::all();
        $this->assertCount(1, $usersForB);
        $this->assertEquals($userB->id, $usersForB->first()->id);
    }

    public function test_models_with_hastenant_automatically_assign_tenant_id_on_creation(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $this->tenantManager->setTenant($tenantA);

        // Create user with active tenant in context, without specifying tenant_id explicitly
        $user = User::create([
            'name' => 'New Tenant User',
            'email' => 'new-user@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->assertEquals($tenantA->id, $user->tenant_id);
    }

    public function test_middleware_resolves_tenant_by_header_and_parameter(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']);

        // 1. Resolve by query parameter
        $response = $this->get('/up?tenant=tenant-a');
        $response->assertStatus(200);
        $this->assertEquals($tenant->id, $this->tenantManager->getTenantId());

        // Reset
        $this->tenantManager->setTenant(null);

        // 2. Resolve by custom header
        $response = $this->get('/up', ['X-Tenant-Slug' => 'tenant-a']);
        $response->assertStatus(200);
        $this->assertEquals($tenant->id, $this->tenantManager->getTenantId());
    }

    public function test_models_automatically_generate_uuids_on_creation(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $this->tenantManager->setTenant($tenant);

        $user = User::create([
            'name' => 'New User',
            'email' => 'new@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->assertNotEmpty($tenant->uuid);
        $this->assertNotEmpty($user->uuid);
        $this->assertNotEquals($tenant->uuid, $user->uuid);
    }

    public function test_setting_helper_retrieves_and_saves_tenant_settings_correctly(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b']);

        // 1. Save settings under Tenant A context
        $this->tenantManager->setTenant($tenantA);
        setting(['site_name' => 'A Site', 'theme' => 'dark']);
        $this->assertEquals('A Site', setting('site_name'));
        $this->assertEquals('dark', setting('theme'));

        // 2. Save settings under Tenant B context
        $this->tenantManager->setTenant($tenantB);
        setting(['site_name' => 'B Site', 'theme' => 'light']);
        $this->assertEquals('B Site', setting('site_name'));
        $this->assertEquals('light', setting('theme'));

        // 3. Switch back to Tenant A and verify cache invalidation / correct values
        $this->tenantManager->setTenant($tenantA);
        $this->assertEquals('A Site', setting('site_name'));
        $this->assertEquals('dark', setting('theme'));
    }
}
