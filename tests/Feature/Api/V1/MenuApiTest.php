<?php

namespace Tests\Feature\Api\V1;

use App\Enums\LanguageEnum;
use App\Enums\MenuItemTypeEnum;
use App\Enums\PageTypeEnum;
use App\Enums\PublishStatusEnum;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MenuApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'is_active' => true]);
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

    private function makePage(Tenant $tenant, array $overrides = []): Page
    {
        return Page::create(array_merge([
            'tenant_id' => $tenant->id,
            'lang_iso' => LanguageEnum::Spanish,
            'slug' => 'servicios',
            'title' => 'Servicios',
            'type' => PageTypeEnum::Page,
            'status' => PublishStatusEnum::Published,
        ], $overrides));
    }

    /**
     * Contrato del Tech Lead (2026-08-30): el front NO debe inferir "es el
     * link de Home" comparando `href === '/'` — un item de menú podría
     * apuntar a la home con un título/slug distinto ("Inicio", "Portada",
     * etc.). `is_home` se expone explícito desde `Page::is_home` (la misma
     * fuente que ya se administra en el Studio), y debe ser `false` para
     * cualquier item que no apunte a la página marcada como home.
     */
    public function test_menu_item_exposes_is_home_resolved_from_the_linked_page(): void
    {
        $tenant = $this->makeTenant('tenant-a');
        $this->actingAsTenant($tenant);

        $home = $this->makePage($tenant, ['slug' => 'home', 'title' => 'Portada', 'is_home' => true]);
        $servicios = $this->makePage($tenant, ['slug' => 'servicios', 'title' => 'Servicios', 'is_home' => false]);

        $menu = Menu::create([
            'tenant_id' => $tenant->id,
            'lang_iso' => LanguageEnum::Spanish,
            'name' => 'Menú principal',
            'slug' => 'menu-principal',
        ]);

        MenuItem::create([
            'tenant_id' => $tenant->id,
            'menu_id' => $menu->id,
            'title' => 'Inicio',
            'type' => MenuItemTypeEnum::Page,
            'reference_id' => $home->id,
            'sort_order' => 0,
        ]);

        MenuItem::create([
            'tenant_id' => $tenant->id,
            'menu_id' => $menu->id,
            'title' => 'Servicios',
            'type' => MenuItemTypeEnum::Page,
            'reference_id' => $servicios->id,
            'sort_order' => 1,
        ]);

        MenuItem::create([
            'tenant_id' => $tenant->id,
            'menu_id' => $menu->id,
            'title' => 'Consultar ahora',
            'type' => MenuItemTypeEnum::Custom,
            'url' => '/contacto',
            'sort_order' => 2,
        ]);

        $response = $this->getJson('/v1/tenant-a/menus/menu-principal');

        $response->assertOk();
        // El item "Inicio" apunta a la home con un título distinto de
        // "Home" — is_home debe salir true igual, resuelto por Page, no
        // por título ni por href.
        $response->assertJsonPath('data.items.0.title', 'Inicio');
        $response->assertJsonPath('data.items.0.href', '/');
        $response->assertJsonPath('data.items.0.is_home', true);

        $response->assertJsonPath('data.items.1.title', 'Servicios');
        $response->assertJsonPath('data.items.1.href', '/servicios');
        $response->assertJsonPath('data.items.1.is_home', false);

        // Custom/external: nunca es home.
        $response->assertJsonPath('data.items.2.title', 'Consultar ahora');
        $response->assertJsonPath('data.items.2.is_home', false);
    }
}
