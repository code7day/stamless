<?php

namespace Tests\Feature\Filament;

use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelSwitcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_switch_button_and_menu_item_rendered_for_super_admin(): void
    {
        $tenant = Tenant::create([
            'name' => 'CICA360',
            'slug' => 'cica360',
            'is_active' => true,
            'plan' => 'free',
        ]);

        $superAdmin = User::create([
            'name' => 'Eduardo Master',
            'email' => 'zedu77@gmail.com',
            'password' => 'password123',
            'is_super_admin' => true,
            'tenant_id' => $tenant->id,
        ]);

        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('cms'));
        Filament::setTenant($tenant);

        // In Studio: switcher button links to Platform Manager
        $studioView = (string) view('filament.components.panel-switch-button', ['targetPanel' => 'platform'])->render();
        $this->assertStringContainsString('Manager', $studioView);
        $this->assertStringContainsString(config('stamless.urls.platform'), $studioView);

        // In Studio: user menu item has "Ir a Platform Manager"
        $cmsItems = Filament::getPanel('cms')->getUserMenuItems();
        $cmsLabels = array_map(fn ($item) => $item->getLabel(), $cmsItems);
        $this->assertContains('Ir a Platform Manager', $cmsLabels);

        // In Platform: switcher button links to Studio
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        Filament::setTenant(null);
        $platformView = (string) view('filament.components.panel-switch-button', ['targetPanel' => 'cms'])->render();
        $this->assertStringContainsString('Studio', $platformView);
        $this->assertStringContainsString('/cica360', $platformView);

        // In Platform: user menu item has "Ir a Studio"
        $platformItems = Filament::getPanel('platform')->getUserMenuItems();
        $platformLabels = array_map(fn ($item) => $item->getLabel(), $platformItems);
        $this->assertContains('Ir a Studio', $platformLabels);
    }

    public function test_panel_switch_button_and_menu_item_hidden_for_regular_tenant_user(): void
    {
        $tenant = Tenant::create([
            'name' => 'CICA360',
            'slug' => 'cica360',
            'is_active' => true,
            'plan' => 'free',
        ]);

        $tenantUser = User::create([
            'name' => 'Regular User',
            'email' => 'regular@cica360.com',
            'password' => 'password123',
            'tenant_id' => $tenant->id,
            'is_super_admin' => false,
        ]);

        $this->actingAs($tenantUser);
        Filament::setCurrentPanel(Filament::getPanel('cms'));
        Filament::setTenant($tenant);

        // In Studio: switcher button is empty for non-super-admin
        $studioView = (string) view('filament.components.panel-switch-button', ['targetPanel' => 'platform'])->render();
        $this->assertEmpty(trim($studioView));

        // In Studio: user menu item does NOT show "Ir a Platform Manager"
        $cmsItems = Filament::getPanel('cms')->getUserMenuItems();
        $cmsLabels = array_map(fn ($item) => $item->getLabel(), $cmsItems);
        $this->assertNotContains('Ir a Platform Manager', $cmsLabels);
    }

    public function test_session_cookie_domain_covers_subdomains(): void
    {
        $sessionDomain = config('session.domain');

        // Should be .stamless.host or leading dot domain matching the host
        $this->assertNotNull($sessionDomain);
        $this->assertStringStartsWith('.', $sessionDomain);
        $this->assertStringContainsString('stamless', $sessionDomain);
    }
}
