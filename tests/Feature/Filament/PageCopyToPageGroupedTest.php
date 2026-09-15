<?php

namespace Tests\Feature\Filament;

use App\Enums\PageTypeEnum;
use App\Filament\Resources\PageResource;
use App\Models\Page;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-15, pedido del Tech Lead: en el modal "Copiar a otra página"
 * de PageResource, agrupar las opciones de página destino por tipo de
 * contenido (<optgroup> por Páginas, Pie de página, Avisos Legales, etc.).
 */
class PageCopyToPageGroupedTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantWithUser(): array
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'is_active' => true,
            'plan' => 'free',
        ]);

        $user = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id,
        ]);

        return [$tenant, $user];
    }

    private function bootPanel(Tenant $tenant, User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('cms'));
        Filament::setTenant($tenant);
    }

    public function test_target_page_options_are_grouped_by_content_type(): void
    {
        [$tenant, $user] = $this->makeTenantWithUser();
        $this->bootPanel($tenant, $user);

        $currentPage = Page::create([
            'tenant_id' => $tenant->id,
            'title' => 'Página Origen',
            'slug' => 'pagina-origen',
            'type' => PageTypeEnum::Page->value,
            'lang_iso' => 'es',
        ]);

        $page2 = Page::create([
            'tenant_id' => $tenant->id,
            'title' => 'Contacto',
            'slug' => 'contacto',
            'type' => PageTypeEnum::Page->value,
            'lang_iso' => 'es',
        ]);

        $footer = Page::create([
            'tenant_id' => $tenant->id,
            'title' => 'Footer Principal',
            'slug' => 'footer-principal',
            'type' => PageTypeEnum::Footer->value,
            'lang_iso' => 'es',
        ]);

        $legal = Page::create([
            'tenant_id' => $tenant->id,
            'title' => 'Términos y Condiciones',
            'slug' => 'terminos-condiciones',
            'type' => PageTypeEnum::Legal->value,
            'lang_iso' => 'es',
        ]);

        // 'cta' es compatible con Page, Footer, Legal
        $options = PageResource::getTargetPageOptionsForBlock($currentPage, 'cta');

        // Verifica que las claves de primer nivel son los nombres de grupo
        $this->assertArrayHasKey('Páginas', $options);
        $this->assertArrayHasKey('Pie de página (Footer)', $options);
        $this->assertArrayHasKey('Avisos Legales', $options);

        // La página origen debe estar excluida del grupo 'Páginas'
        $this->assertArrayNotHasKey($currentPage->id, $options['Páginas']);
        $this->assertArrayHasKey($page2->id, $options['Páginas']);
        $this->assertSame('Contacto', $options['Páginas'][$page2->id]);

        // Footer debe estar en su grupo
        $this->assertArrayHasKey($footer->id, $options['Pie de página (Footer)']);
        $this->assertSame('Footer Principal', $options['Pie de página (Footer)'][$footer->id]);

        // Legal debe estar en su grupo
        $this->assertArrayHasKey($legal->id, $options['Avisos Legales']);
        $this->assertSame('Términos y Condiciones', $options['Avisos Legales'][$legal->id]);

        // Verifica el orden de los grupos
        $this->assertSame(
            ['Páginas', 'Pie de página (Footer)', 'Avisos Legales'],
            array_keys($options)
        );
    }

    public function test_target_page_options_filters_incompatible_blocks(): void
    {
        [$tenant, $user] = $this->makeTenantWithUser();
        $this->bootPanel($tenant, $user);

        $page = Page::create([
            'tenant_id' => $tenant->id,
            'title' => 'Inicio',
            'slug' => 'inicio',
            'type' => PageTypeEnum::Page->value,
            'lang_iso' => 'es',
        ]);

        $footer = Page::create([
            'tenant_id' => $tenant->id,
            'title' => 'Footer Principal',
            'slug' => 'footer-principal',
            'type' => PageTypeEnum::Footer->value,
            'lang_iso' => 'es',
        ]);

        // 'colophon' solo es permitido en páginas tipo Footer
        $options = PageResource::getTargetPageOptionsForBlock($page, 'colophon');

        $this->assertArrayNotHasKey('Páginas', $options);
        $this->assertArrayHasKey('Pie de página (Footer)', $options);
        $this->assertArrayHasKey($footer->id, $options['Pie de página (Footer)']);
    }

    public function test_legal_notice_block_only_shows_legal_group(): void
    {
        [$tenant, $user] = $this->makeTenantWithUser();
        $this->bootPanel($tenant, $user);

        Page::create([
            'tenant_id' => $tenant->id,
            'title' => 'Inicio',
            'slug' => 'inicio',
            'type' => PageTypeEnum::Page->value,
            'lang_iso' => 'es',
        ]);

        $legal = Page::create([
            'tenant_id' => $tenant->id,
            'title' => 'Política de Privacidad',
            'slug' => 'privacidad',
            'type' => PageTypeEnum::Legal->value,
            'lang_iso' => 'es',
        ]);

        // 'legal_notice' solo es permitido en páginas tipo Legal
        $options = PageResource::getTargetPageOptionsForBlock(null, 'legal_notice');

        $this->assertArrayNotHasKey('Páginas', $options);
        $this->assertArrayHasKey('Avisos Legales', $options);
        $this->assertArrayHasKey($legal->id, $options['Avisos Legales']);
    }

    public function test_target_page_options_strictly_respects_tenant_scope(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();

        $tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b',
            'is_active' => true,
            'plan' => 'free',
        ]);
        $userB = User::create([
            'name' => 'User B',
            'email' => 'admin@tenant-b.com',
            'password' => bcrypt('password'),
            'tenant_id' => $tenantB->id,
        ]);

        $pageA = Page::create([
            'tenant_id' => $tenantA->id,
            'title' => 'Página Tenant A',
            'slug' => 'pagina-a',
            'type' => PageTypeEnum::Page->value,
            'lang_iso' => 'es',
        ]);

        $pageB = Page::create([
            'tenant_id' => $tenantB->id,
            'title' => 'Página Tenant B',
            'slug' => 'pagina-b',
            'type' => PageTypeEnum::Page->value,
            'lang_iso' => 'es',
        ]);

        // Booting Tenant A
        $this->bootPanel($tenantA, $userA);
        $optionsA = PageResource::getTargetPageOptionsForBlock(null, 'cta');

        $this->assertArrayHasKey('Páginas', $optionsA);
        $this->assertArrayHasKey($pageA->id, $optionsA['Páginas']);
        $this->assertArrayNotHasKey($pageB->id, $optionsA['Páginas']);

        // Booting Tenant B
        $this->bootPanel($tenantB, $userB);
        $optionsB = PageResource::getTargetPageOptionsForBlock(null, 'cta');

        $this->assertArrayHasKey('Páginas', $optionsB);
        $this->assertArrayHasKey($pageB->id, $optionsB['Páginas']);
        $this->assertArrayNotHasKey($pageA->id, $optionsB['Páginas']);
    }
}
