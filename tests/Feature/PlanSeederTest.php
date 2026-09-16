<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Cliente0Seeder;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_seeder_creates_free_and_sponsorship_plans(): void
    {
        $this->seed(PlanSeeder::class);

        $this->assertDatabaseHas(Plan::class, [
            'slug' => 'free',
            'name' => 'Free',
            'is_free' => true,
            'max_users' => 1,
            'max_pages' => 20,
            'max_posts' => 10,
            'max_storage_mb' => 500,
        ]);

        $this->assertDatabaseHas(Plan::class, [
            'slug' => 'sponsorship',
            'name' => 'Auspicio',
            'is_free' => true,
            'max_users' => 3,
            'max_pages' => 30,
            'max_posts' => 20,
            'max_storage_mb' => 1000,
        ]);

        $freePlan = Plan::where('slug', 'free')->first();
        $sponsorshipPlan = Plan::where('slug', 'sponsorship')->first();

        $this->assertNotNull($freePlan);
        $this->assertNotNull($sponsorshipPlan);

        $this->assertTrue(
            PlanFeature::where('plan_id', $freePlan->id)->where('key', 'max_users')->where('value', '1')->exists()
        );
        $this->assertTrue(
            PlanFeature::where('plan_id', $sponsorshipPlan->id)->where('key', 'max_users')->where('value', '3')->exists()
        );
        $this->assertTrue(
            PlanFeature::where('plan_id', $sponsorshipPlan->id)->where('key', 'max_storage_mb')->where('value', '1000')->exists()
        );
        $this->assertTrue(
            PlanFeature::where('plan_id', $sponsorshipPlan->id)->where('key', 'custom_copyright')->where('value', 'acotado')->exists()
        );
        $this->assertTrue(
            PlanFeature::where('plan_id', $sponsorshipPlan->id)->where('key', 'brand_personalization')->where('value', 'true')->exists()
        );
    }

    public function test_module_seeder_attaches_core_modules_to_both_free_and_sponsorship_plans(): void
    {
        $this->seed(PlanSeeder::class);
        $this->seed(ModuleSeeder::class);

        $freePlan = Plan::where('slug', 'free')->firstOrFail();
        $sponsorshipPlan = Plan::where('slug', 'sponsorship')->firstOrFail();

        $coreModulesCount = Module::where('is_core', true)->count();

        $this->assertSame($coreModulesCount, $freePlan->modules()->count());
        $this->assertSame($coreModulesCount, $sponsorshipPlan->modules()->count());
    }

    public function test_cliente0_seeder_subscribes_tenant_to_sponsorship_plan(): void
    {
        $this->seed(PlanSeeder::class);
        $this->seed(ModuleSeeder::class);
        $this->seed(Cliente0Seeder::class);

        $tenant = Tenant::where('slug', 'cica360')->firstOrFail();
        $sponsorshipPlan = Plan::where('slug', 'sponsorship')->firstOrFail();

        $this->assertSame('sponsorship', $tenant->plan);
        $this->assertSame($sponsorshipPlan->id, $tenant->planModel()?->id);

        $subscription = Subscription::where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($subscription);
        $this->assertSame($sponsorshipPlan->id, $subscription->plan_id);
        $this->assertSame('sponsorship', $tenant->currentSubscription()?->plan?->slug);
    }

    public function test_platform_seeder_creates_master_tenant_with_sponsorship_plan(): void
    {
        $this->seed(PlanSeeder::class);
        $this->seed(ModuleSeeder::class);
        $this->seed(PlatformSeeder::class);

        $masterTenant = Tenant::where('slug', 'stamless')->firstOrFail();
        $this->assertSame('sponsorship', $masterTenant->plan);
        $this->assertSame('Eduardo Flores', $masterTenant->name);

        $masterUser = User::where('email', 'zedu77@gmail.com')->firstOrFail();
        $this->assertTrue($masterUser->is_super_admin);
        $this->assertSame($masterTenant->id, $masterUser->tenant_id);

        $subscription = Subscription::where('tenant_id', $masterTenant->id)->first();
        $this->assertNotNull($subscription);
        $this->assertSame('sponsorship', $subscription->plan->slug);
    }
}
