<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_returns_spanish_error_when_accept_language_es_is_sent(): void
    {
        Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a', 'is_active' => true]);

        $response = $this->getJson('/v1/tenant-a/pages/home', [
            'Accept-Language' => 'es-ES,es;q=0.9',
        ]);

        $response->assertStatus(401);
        $response->assertHeader('Content-Language', 'es');
        $response->assertJsonPath('message', 'No autenticado. Se requiere un token Bearer en el header Authorization.');
    }

    public function test_api_returns_english_error_message_when_accept_language_en_is_sent(): void
    {
        Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a', 'is_active' => true]);

        $response = $this->getJson('/v1/tenant-a/pages/home', [
            'Accept-Language' => 'en-US,en;q=0.9',
        ]);

        $response->assertStatus(401);
        $response->assertHeader('Content-Language', 'en');
        $response->assertJsonPath('message', 'Unauthenticated. A Bearer token is required in the Authorization header.');
    }

    public function test_api_returns_portuguese_error_message_when_accept_language_pt_br_is_sent(): void
    {
        Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a', 'is_active' => true]);

        $response = $this->getJson('/v1/tenant-a/pages/home', [
            'Accept-Language' => 'pt-BR,pt;q=0.9',
        ]);

        $response->assertStatus(401);
        $response->assertHeader('Content-Language', 'pt-br');
        $response->assertJsonPath('message', 'Não autenticado. É necessário um token Bearer no cabeçalho Authorization.');
    }

    public function test_api_honors_x_locale_custom_header(): void
    {
        Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a', 'is_active' => true]);

        $response = $this->getJson('/v1/tenant-a/pages/home', [
            'X-Locale' => 'en',
        ]);

        $response->assertStatus(401);
        $response->assertHeader('Content-Language', 'en');
        $response->assertJsonPath('message', 'Unauthenticated. A Bearer token is required in the Authorization header.');
    }
}
