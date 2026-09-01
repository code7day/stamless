<?php

namespace Tests\Feature\Api\V1;

use App\Enums\BlockTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\MediaDiskEnum;
use App\Enums\PageTypeEnum;
use App\Enums\PublishStatusEnum;
use App\Models\Block;
use App\Models\Media;
use App\Models\Page;
use App\Models\Slider;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PageApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $slug, bool $isActive = true): Tenant
    {
        return Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'is_active' => $isActive]);
    }

    /**
     * Autentica al guard `sanctum` (helper de test oficial de Sanctum) con
     * un usuario real del tenant dado — así `ResolvesTenant::resolveTenant()`
     * encuentra `request()->user('sanctum')->tenant_id` como lo haría en
     * producción con un token real.
     */
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
            'slug' => 'home',
            'title' => 'Home',
            'type' => PageTypeEnum::Page,
            'status' => PublishStatusEnum::Published,
        ], $overrides));
    }

    public function test_pages_are_isolated_by_tenant_in_the_api(): void
    {
        $tenantA = $this->makeTenant('tenant-a');
        $tenantB = $this->makeTenant('tenant-b');

        $this->makePage($tenantA, ['title' => 'Home de Tenant A']);
        $this->makePage($tenantB, ['title' => 'Home de Tenant B']);

        $this->actingAsTenant($tenantA);
        $this->getJson('/v1/tenant-a/pages/home')
            ->assertOk()
            ->assertJsonPath('data.title', 'Home de Tenant A');

        $this->actingAsTenant($tenantB);
        $this->getJson('/v1/tenant-b/pages/home')
            ->assertOk()
            ->assertJsonPath('data.title', 'Home de Tenant B');
    }

    public function test_unknown_tenant_returns_a_404_envelope(): void
    {
        // Token válido de OTRO tenant: alcanza para llegar al controller,
        // pero el tenant del path no existe.
        $this->actingAsTenant($this->makeTenant('tenant-a'));

        $response = $this->getJson('/v1/does-not-exist/pages/home');

        $response->assertStatus(404);
        $response->assertJson(['success' => false, 'status_code' => 404]);
        $response->assertJsonPath('errors.code', 'not_found');
    }

    public function test_inactive_tenant_is_treated_as_not_found(): void
    {
        $this->makeTenant('inactivo', isActive: false);
        $this->actingAsTenant($this->makeTenant('tenant-a'));

        $response = $this->getJson('/v1/inactivo/pages/home');

        $response->assertStatus(404);
        $response->assertJsonPath('errors.code', 'not_found');
    }

    public function test_show_page_by_slug_returns_only_visible_blocks_ordered(): void
    {
        $tenant = $this->makeTenant('tenant-a');
        $this->actingAsTenant($tenant);
        $page = $this->makePage($tenant, ['slug' => 'about', 'title' => 'Sobre nosotros']);

        Block::create([
            'tenant_id' => $tenant->id,
            'page_id' => $page->id,
            'type' => BlockTypeEnum::RichText,
            'content' => ['html' => 'segundo'],
            'sort_order' => 2,
            'is_visible' => true,
        ]);

        Block::create([
            'tenant_id' => $tenant->id,
            'page_id' => $page->id,
            'type' => BlockTypeEnum::Hero,
            'content' => ['html' => 'primero'],
            'sort_order' => 1,
            'is_visible' => true,
        ]);

        Block::create([
            'tenant_id' => $tenant->id,
            'page_id' => $page->id,
            'type' => BlockTypeEnum::Faq,
            'content' => [],
            'sort_order' => 0,
            'is_visible' => false,
        ]);

        $response = $this->getJson('/v1/tenant-a/pages/about');

        $response->assertOk();
        $response->assertJsonCount(2, 'data.blocks');
        $response->assertJsonPath('data.blocks.0.type', 'hero');
        $response->assertJsonPath('data.blocks.1.type', 'rich_text');
    }

    public function test_draft_page_is_not_found_via_the_api(): void
    {
        $tenant = $this->makeTenant('tenant-a');
        $this->actingAsTenant($tenant);
        $this->makePage($tenant, ['slug' => 'draft-page', 'status' => PublishStatusEnum::Draft]);

        $response = $this->getJson('/v1/tenant-a/pages/draft-page');

        $response->assertStatus(404);
    }

    public function test_pages_index_is_paginated_with_the_standard_envelope(): void
    {
        $tenant = $this->makeTenant('tenant-a');
        $this->actingAsTenant($tenant);

        foreach (range(1, 3) as $i) {
            $this->makePage($tenant, ['slug' => "page-{$i}", 'title' => "Página {$i}"]);
        }

        $response = $this->getJson('/v1/tenant-a/pages');

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'status_code',
            'data',
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            'links' => ['first', 'prev', 'next', 'last'],
        ]);
        $response->assertJsonPath('meta.total', 3);
        $response->assertJsonCount(3, 'data');
    }

    public function test_pages_index_filters_by_is_home(): void
    {
        $tenant = $this->makeTenant('tenant-a');
        $this->actingAsTenant($tenant);

        $this->makePage($tenant, ['slug' => 'home', 'is_home' => true]);
        $this->makePage($tenant, ['slug' => 'servicios', 'title' => 'Servicios', 'is_home' => false]);

        $response = $this->getJson('/v1/tenant-a/pages?is_home=1');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.slug', 'home');
    }

    /**
     * Contrato público (ADR-018): ni `content.slider_id` ni
     * `links[].source_id` deberían aparecer nunca en la response — solo
     * `slider_slug`/`source_slug`+`href` ya resueltos.
     */
    public function test_page_response_does_not_expose_internal_ids(): void
    {
        $tenant = $this->makeTenant('tenant-a');
        $this->actingAsTenant($tenant);

        $contacto = $this->makePage($tenant, ['slug' => 'contacto', 'title' => 'Contacto']);
        $home = $this->makePage($tenant, ['slug' => 'home', 'title' => 'Home', 'is_home' => true]);

        $slider = Slider::create([
            'tenant_id' => $tenant->id,
            'lang_iso' => LanguageEnum::Spanish->value,
            'title' => 'Slider principal',
            'slug' => 'home',
            'is_active' => true,
        ]);

        Block::create([
            'tenant_id' => $tenant->id,
            'page_id' => $home->id,
            'type' => BlockTypeEnum::Hero,
            'content' => ['mode' => 'slider', 'slider_id' => $slider->id],
            'sort_order' => 0,
        ]);

        Block::create([
            'tenant_id' => $tenant->id,
            'page_id' => $home->id,
            'type' => BlockTypeEnum::Cta,
            'title' => 'CTA',
            'content' => [],
            'links' => [[
                'type' => 'primary',
                'label' => 'Ir a contacto',
                'source_type' => 'page',
                'source_id' => $contacto->id,
                'url' => null,
                'target' => '_self',
            ]],
            'sort_order' => 1,
        ]);

        $response = $this->getJson('/v1/tenant-a/pages/home');

        $response->assertOk();

        $response->assertJsonPath('data.blocks.0.content.slider_slug', 'home');
        $response->assertJsonMissingPath('data.blocks.0.content.slider_id');

        $response->assertJsonPath('data.blocks.1.links.0.source_slug', 'contacto');
        $response->assertJsonPath('data.blocks.1.links.0.href', '/contacto');
        $response->assertJsonMissingPath('data.blocks.1.links.0.source_id');

        // `assertJsonPath` decodifica el JSON como array asociativo, así que
        // `{}` y `[]` colapsan al mismo `[]` en PHP — se verifica el string
        // crudo de la response para confirmar que serializa como objeto.
        $this->assertStringContainsString('"properties":{}', $response->getContent());
    }

    /**
     * Cierra el gap señalado en ADR-018: los ids anidados dentro de
     * `content.items[]` (services_grid, en este caso `image_id` y
     * `page_id`) también se resuelven a datos públicos, no solo los
     * `links[]`/`hero.slider_id` de nivel bloque.
     */
    public function test_page_response_resolves_nested_media_and_page_ids_inside_block_items(): void
    {
        $tenant = $this->makeTenant('tenant-a');
        $this->actingAsTenant($tenant);

        $servicios = $this->makePage($tenant, ['slug' => 'servicios', 'title' => 'Servicios']);
        $home = $this->makePage($tenant, ['slug' => 'home', 'title' => 'Home', 'is_home' => true]);

        $media = Media::create([
            'tenant_id' => $tenant->id,
            'name' => 'icono-seguros',
            'file_name' => 'icono-seguros.png',
            'mime_type' => 'image/png',
            'path' => 'icono-seguros.png',
            'disk' => MediaDiskEnum::Public,
            'size' => 1024,
            'alt_text' => 'Icono de seguros',
        ]);

        Block::create([
            'tenant_id' => $tenant->id,
            'page_id' => $home->id,
            'type' => BlockTypeEnum::ServicesGrid,
            'title' => 'Servicios',
            'content' => [
                'items' => [
                    [
                        'title' => 'Seguros generales',
                        'subtitle' => 'Cobertura a medida.',
                        'image_id' => $media->id,
                        'page_id' => $servicios->id,
                        'url' => null,
                        'badge' => null,
                    ],
                ],
            ],
            'sort_order' => 0,
        ]);

        $response = $this->getJson('/v1/tenant-a/pages/home');

        $response->assertOk();
        $response->assertJsonPath('data.blocks.0.content.items.0.page_slug', 'servicios');
        $response->assertJsonPath('data.blocks.0.content.items.0.href', '/servicios');
        $response->assertJsonPath('data.blocks.0.content.items.0.image.uuid', $media->uuid);
        $response->assertJsonPath('data.blocks.0.content.items.0.image.alt_text', 'Icono de seguros');
        $response->assertJsonMissingPath('data.blocks.0.content.items.0.page_id');
        $response->assertJsonMissingPath('data.blocks.0.content.items.0.image_id');
    }
}
