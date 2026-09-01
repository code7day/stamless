<?php

namespace Tests\Feature\Api\V1;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Cliente0PostsSeeder;
use Database\Seeders\Cliente0Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PostApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_posts_index_returns_the_seeded_cica360_posts(): void
    {
        $this->seed(Cliente0Seeder::class);
        $this->seed(Cliente0PostsSeeder::class);

        $tenant = Tenant::where('slug', 'cica360')->firstOrFail();
        $owner = User::where('tenant_id', $tenant->id)->firstOrFail();

        Sanctum::actingAs($owner, ['content:read']);

        $response = $this->getJson('/v1/cica360/posts');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 3);

        $slugs = collect($response->json('data'))->pluck('slug')->all();

        $this->assertContains('como-elegir-seguro-de-vida', $slugs);
        $this->assertContains('errores-comunes-en-contratos-comerciales', $slugs);
        $this->assertContains('como-organizar-las-finanzas-de-una-pyme', $slugs);
    }

    public function test_post_show_returns_full_content_by_slug(): void
    {
        $this->seed(Cliente0Seeder::class);
        $this->seed(Cliente0PostsSeeder::class);

        $tenant = Tenant::where('slug', 'cica360')->firstOrFail();
        $owner = User::where('tenant_id', $tenant->id)->firstOrFail();

        Sanctum::actingAs($owner, ['content:read']);

        $response = $this->getJson('/v1/cica360/posts/como-elegir-seguro-de-vida');

        $response->assertOk();
        $response->assertJsonPath('data.slug', 'como-elegir-seguro-de-vida');
        $response->assertJsonStructure(['data' => ['uuid', 'slug', 'title', 'excerpt', 'content', 'meta', 'links', 'properties', 'published_at']]);
    }
}
