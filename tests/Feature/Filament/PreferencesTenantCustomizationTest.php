<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Preferences;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PreferencesTenantCustomizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_user_can_change_name_and_slug_once(): void
    {
        $tenant = Tenant::create([
            'name' => 'Original Name',
            'slug' => 'original-slug',
            'is_active' => true,
            'plan' => 'sponsorship',
            'slug_changes_count' => 0,
            'slug_changes_allowed' => 1,
        ]);

        $user = User::create([
            'name' => 'Tenant Admin',
            'email' => 'admin@original.com',
            'password' => 'password123',
            'tenant_id' => $tenant->id,
            'is_super_admin' => false,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('cms'));
        Filament::setTenant($tenant);

        $this->assertTrue($tenant->canChangeSlug());
        $this->assertSame(1, $tenant->remainingSlugChanges());

        Livewire::test(Preferences::class)
            ->assertFormSet([
                'tenant_name' => 'Original Name',
                'tenant_slug' => 'original-slug',
            ])
            ->fillForm([
                'tenant_name' => 'Nuevo Nombre',
                'tenant_slug' => 'nuevo-slug',
                'locale' => 'es',
                'timezone' => 'America/Montevideo',
            ])
            ->callAction('save')
            ->assertHasNoFormErrors();

        $tenant->refresh();

        $this->assertSame('Nuevo Nombre', $tenant->name);
        $this->assertSame('nuevo-slug', $tenant->slug);
        $this->assertSame(1, $tenant->slug_changes_count);
        $this->assertFalse($tenant->canChangeSlug());
        $this->assertSame(0, $tenant->remainingSlugChanges());
    }

    public function test_tenant_cannot_change_slug_when_limit_reached(): void
    {
        $tenant = Tenant::create([
            'name' => 'Locked Tenant',
            'slug' => 'locked-slug',
            'is_active' => true,
            'plan' => 'sponsorship',
            'slug_changes_count' => 1,
            'slug_changes_allowed' => 1,
        ]);

        $user = User::create([
            'name' => 'Tenant Admin',
            'email' => 'admin@locked.com',
            'password' => 'password123',
            'tenant_id' => $tenant->id,
            'is_super_admin' => false,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('cms'));
        Filament::setTenant($tenant);

        $this->assertFalse($tenant->canChangeSlug());

        Livewire::test(Preferences::class)
            ->assertFormFieldIsDisabled('tenant_slug')
            ->fillForm([
                'tenant_name' => 'Updated Name',
                'tenant_slug' => 'attempted-slug-change',
                'locale' => 'es',
                'timezone' => 'America/Montevideo',
            ])
            ->callAction('save')
            ->assertHasNoFormErrors();

        $tenant->refresh();

        // Name was updated, but slug was protected
        $this->assertSame('Updated Name', $tenant->name);
        $this->assertSame('locked-slug', $tenant->slug);
        $this->assertSame(1, $tenant->slug_changes_count);
    }
}
