<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ChangePassword;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Cliente0Seeder;
use Database\Seeders\PlanSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class MustChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_must_change_password_is_redirected_to_change_password_page(): void
    {
        $tenant = Tenant::create([
            'name' => 'CICA 360',
            'slug' => 'cica360',
            'plan' => 'free',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'must_change_password' => true,
            'is_super_admin' => false,
        ]);

        $studioHost = parse_url(config('stamless.urls.studio'), PHP_URL_HOST);

        $response = $this->actingAs($user)
            ->get("https://{$studioHost}/cica360");

        $response->assertRedirect("https://{$studioHost}/cica360/change-password");
    }

    public function test_user_with_must_change_password_can_access_change_password_page(): void
    {
        $tenant = Tenant::create([
            'name' => 'CICA 360',
            'slug' => 'cica360',
            'plan' => 'free',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'must_change_password' => true,
            'is_super_admin' => false,
        ]);

        $studioHost = parse_url(config('stamless.urls.studio'), PHP_URL_HOST);

        $response = $this->actingAs($user)
            ->get("https://{$studioHost}/cica360/change-password");

        $response->assertOk();
    }

    public function test_user_can_update_password_and_clear_must_change_password_flag(): void
    {
        $tenant = Tenant::create([
            'name' => 'CICA 360',
            'slug' => 'cica360',
            'plan' => 'free',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password' => 'OldPassword123!',
            'must_change_password' => true,
            'is_super_admin' => false,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('cms'));
        Filament::setTenant($tenant);

        Livewire::test(ChangePassword::class)
            ->fillForm([
                'current_password' => 'OldPassword123!',
                'password' => 'NewSecurePassword2026!',
                'password_confirmation' => 'NewSecurePassword2026!',
            ])
            ->callAction('save')
            ->assertHasNoFormErrors();

        $user->refresh();

        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('NewSecurePassword2026!', $user->password));
    }

    public function test_cliente0_seeder_creates_owner_with_must_change_password_flag(): void
    {
        $this->seed(PlanSeeder::class);
        $this->seed(Cliente0Seeder::class);

        $owner = User::where('email', 'goncalvez.isaac@gmail.com')->first();

        $this->assertNotNull($owner);
        $this->assertEquals('Isaac Goncalvez', $owner->name);
        $this->assertTrue((bool) $owner->must_change_password);
        $this->assertTrue(Hash::check('Cica360#Secure!2026', $owner->password));
    }
}
