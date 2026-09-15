<?php

namespace Tests\Feature;

use App\Enums\LanguageEnum;
use App\Enums\PageTypeEnum;
use App\Enums\PublishStatusEnum;
use App\Models\Media;
use App\Models\Menu;
use App\Models\Page;
use App\Models\Post;
use App\Models\Service;
use App\Models\Slider;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
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

    public function test_is_home_is_strictly_scoped_by_tenant_and_does_not_modify_other_tenants(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a', 'is_active' => true]);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b', 'is_active' => true]);

        // Tenant A creates first home page
        $this->tenantManager->setTenant($tenantA);
        $pageA1 = Page::create([
            'tenant_id' => $tenantA->id,
            'title' => 'Home A1',
            'slug' => 'home-a1',
            'lang_iso' => LanguageEnum::Spanish,
            'type' => PageTypeEnum::Page,
            'status' => PublishStatusEnum::Published,
            'is_home' => true,
        ]);

        // Tenant B creates home page
        $this->tenantManager->setTenant($tenantB);
        $pageB1 = Page::create([
            'tenant_id' => $tenantB->id,
            'title' => 'Home B1',
            'slug' => 'home-b1',
            'lang_iso' => LanguageEnum::Spanish,
            'type' => PageTypeEnum::Page,
            'status' => PublishStatusEnum::Published,
            'is_home' => true,
        ]);

        $this->assertTrue($pageA1->fresh()->is_home);
        $this->assertTrue($pageB1->fresh()->is_home);

        // Tenant A creates a second home page (is_home = true)
        $this->tenantManager->setTenant($tenantA);
        $pageA2 = Page::create([
            'tenant_id' => $tenantA->id,
            'title' => 'Home A2',
            'slug' => 'home-a2',
            'lang_iso' => LanguageEnum::Spanish,
            'type' => PageTypeEnum::Page,
            'status' => PublishStatusEnum::Published,
            'is_home' => true,
        ]);

        // Page A1 should no longer be home
        $this->assertFalse($pageA1->fresh()->is_home);
        // Page A2 should now be home
        $this->assertTrue($pageA2->fresh()->is_home);
        // Page B1 of Tenant B must remain home!
        $this->assertTrue($pageB1->fresh()->is_home);
    }

    public function test_soft_deleted_pages_do_not_block_new_active_page_with_same_slug(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a', 'is_active' => true]);
        $this->tenantManager->setTenant($tenant);

        $page1 = Page::create([
            'tenant_id' => $tenant->id,
            'title' => 'Contacto Original',
            'slug' => 'contacto',
            'lang_iso' => LanguageEnum::Spanish,
            'type' => PageTypeEnum::Page,
            'status' => PublishStatusEnum::Published,
        ]);

        $page1->delete();
        $this->assertSoftDeleted('pages', ['id' => $page1->id]);

        // Creating a new active page with the same slug 'contacto' should succeed
        $page2 = Page::create([
            'tenant_id' => $tenant->id,
            'title' => 'Contacto Nuevo',
            'slug' => 'contacto',
            'lang_iso' => LanguageEnum::Spanish,
            'type' => PageTypeEnum::Page,
            'status' => PublishStatusEnum::Published,
        ]);

        $this->assertEquals('contacto', $page2->slug);
        $this->assertDatabaseHas('pages', ['id' => $page2->id, 'deleted_at' => null]);
    }

    public function test_restoring_soft_deleted_page_with_colliding_slug_appends_suffix(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a', 'is_active' => true]);
        $this->tenantManager->setTenant($tenant);

        // 1. Create page1 and soft-delete it
        $page1 = Page::create([
            'tenant_id' => $tenant->id,
            'title' => 'Contacto 1',
            'slug' => 'contacto',
            'lang_iso' => LanguageEnum::Spanish,
            'type' => PageTypeEnum::Page,
            'status' => PublishStatusEnum::Published,
        ]);
        $page1->delete();

        // 2. Create active page2 with slug 'contacto'
        $page2 = Page::create([
            'tenant_id' => $tenant->id,
            'title' => 'Contacto 2',
            'slug' => 'contacto',
            'lang_iso' => LanguageEnum::Spanish,
            'type' => PageTypeEnum::Page,
            'status' => PublishStatusEnum::Published,
        ]);

        // 3. Restore page1 -> slug should automatically change to 'contacto-restaurado'
        $page1->restore();

        $this->assertEquals('contacto-restaurado', $page1->fresh()->slug);
        $this->assertEquals('contacto', $page2->fresh()->slug);
        $this->assertNull($page1->fresh()->deleted_at);
        $this->assertNull($page2->fresh()->deleted_at);

        // 4. Test multiple restored collision handling
        // Soft delete page2, and create page3 'contacto'.
        // Currently active pages are: page1 ('contacto-restaurado') and page3 ('contacto').
        $page2->delete();

        $page3 = Page::create([
            'tenant_id' => $tenant->id,
            'title' => 'Contacto 3',
            'slug' => 'contacto',
            'lang_iso' => LanguageEnum::Spanish,
            'type' => PageTypeEnum::Page,
            'status' => PublishStatusEnum::Published,
        ]);

        // Restore page2 -> since 'contacto' is taken by page3, and 'contacto-restaurado' is taken by page1,
        // page2 should automatically resolve to 'contacto-restaurado-2'.
        $page2->restore();

        $this->assertEquals('contacto-restaurado-2', $page2->fresh()->slug);
        $this->assertEquals('contacto', $page3->fresh()->slug);
        $this->assertEquals('contacto-restaurado', $page1->fresh()->slug);
    }

    public function test_identical_slugs_coexist_across_different_tenants(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a', 'is_active' => true]);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b', 'is_active' => true]);

        // Tenant A creates resources
        $this->tenantManager->setTenant($tenantA);
        $pageA = Page::create([
            'tenant_id' => $tenantA->id,
            'title' => 'Inicio A',
            'slug' => 'inicio',
            'lang_iso' => LanguageEnum::Spanish,
            'type' => PageTypeEnum::Page,
            'status' => PublishStatusEnum::Published,
        ]);
        $postA = Post::create([
            'tenant_id' => $tenantA->id,
            'title' => 'Noticia A',
            'slug' => 'noticia-1',
            'lang_iso' => LanguageEnum::Spanish,
            'status' => PublishStatusEnum::Published,
        ]);
        $serviceA = Service::create([
            'tenant_id' => $tenantA->id,
            'title' => 'Seguro Vida A',
            'slug' => 'seguro-vida',
            'lang_iso' => LanguageEnum::Spanish,
            'status' => PublishStatusEnum::Published,
        ]);
        $menuA = Menu::create([
            'tenant_id' => $tenantA->id,
            'name' => 'Principal A',
            'slug' => 'principal',
            'lang_iso' => LanguageEnum::Spanish,
        ]);
        $sliderA = Slider::create([
            'tenant_id' => $tenantA->id,
            'title' => 'Hero A',
            'slug' => 'hero-home',
            'lang_iso' => LanguageEnum::Spanish,
        ]);

        // Tenant B creates resources with the exact same slugs
        $this->tenantManager->setTenant($tenantB);
        $pageB = Page::create([
            'tenant_id' => $tenantB->id,
            'title' => 'Inicio B',
            'slug' => 'inicio',
            'lang_iso' => LanguageEnum::Spanish,
            'type' => PageTypeEnum::Page,
            'status' => PublishStatusEnum::Published,
        ]);
        $postB = Post::create([
            'tenant_id' => $tenantB->id,
            'title' => 'Noticia B',
            'slug' => 'noticia-1',
            'lang_iso' => LanguageEnum::Spanish,
            'status' => PublishStatusEnum::Published,
        ]);
        $serviceB = Service::create([
            'tenant_id' => $tenantB->id,
            'title' => 'Seguro Vida B',
            'slug' => 'seguro-vida',
            'lang_iso' => LanguageEnum::Spanish,
            'status' => PublishStatusEnum::Published,
        ]);
        $menuB = Menu::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Principal B',
            'slug' => 'principal',
            'lang_iso' => LanguageEnum::Spanish,
        ]);
        $sliderB = Slider::create([
            'tenant_id' => $tenantB->id,
            'title' => 'Hero B',
            'slug' => 'hero-home',
            'lang_iso' => LanguageEnum::Spanish,
        ]);

        $this->assertEquals('inicio', $pageA->slug);
        $this->assertEquals('inicio', $pageB->slug);
        $this->assertNotEquals($pageA->id, $pageB->id);

        $this->assertEquals('noticia-1', $postA->slug);
        $this->assertEquals('noticia-1', $postB->slug);

        $this->assertEquals('seguro-vida', $serviceA->slug);
        $this->assertEquals('seguro-vida', $serviceB->slug);

        $this->assertEquals('principal', $menuA->slug);
        $this->assertEquals('principal', $menuB->slug);

        $this->assertEquals('hero-home', $sliderA->slug);
        $this->assertEquals('hero-home', $sliderB->slug);
    }

    public function test_api_v1_cross_tenant_token_access_is_forbidden(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a', 'is_active' => true]);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b', 'is_active' => true]);

        $userB = User::create([
            'name' => 'User B',
            'email' => 'user-b@tenant-b.test',
            'password' => bcrypt('password'),
            'tenant_id' => $tenantB->id,
        ]);

        Sanctum::actingAs($userB, ['content:read']);

        $response = $this->getJson('/v1/tenant-a/pages');
        $response->assertStatus(403);
        $response->assertJsonPath('errors.code', 'forbidden');
    }

    public function test_api_v1_resource_from_another_tenant_returns_not_found(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a', 'is_active' => true]);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b', 'is_active' => true]);

        $userB = User::create([
            'name' => 'User B',
            'email' => 'user-b@tenant-b.test',
            'password' => bcrypt('password'),
            'tenant_id' => $tenantB->id,
        ]);

        Page::create([
            'tenant_id' => $tenantA->id,
            'title' => 'Página Privada A',
            'slug' => 'pagina-privada-a',
            'lang_iso' => LanguageEnum::Spanish,
            'type' => PageTypeEnum::Page,
            'status' => PublishStatusEnum::Published,
        ]);

        Sanctum::actingAs($userB, ['content:read']);

        $response = $this->getJson('/v1/tenant-b/pages/pagina-privada-a');
        $response->assertStatus(404);
        $response->assertJsonPath('errors.code', 'not_found');
    }

    public function test_api_v1_media_from_another_tenant_cannot_be_queried(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a', 'is_active' => true]);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b', 'is_active' => true]);

        $userB = User::create([
            'name' => 'User B',
            'email' => 'user-b@tenant-b.test',
            'password' => bcrypt('password'),
            'tenant_id' => $tenantB->id,
        ]);

        $mediaA = Media::create([
            'tenant_id' => $tenantA->id,
            'filename' => 'doc-a.pdf',
            'file_path' => 'tenants/tenant-a/media/doc-a.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'disk' => 'r2',
        ]);

        Sanctum::actingAs($userB, ['content:read']);

        $response = $this->getJson('/v1/tenant-b/media/'.$mediaA->uuid);
        $response->assertStatus(404);
        $response->assertJsonPath('errors.code', 'not_found');
    }
}
