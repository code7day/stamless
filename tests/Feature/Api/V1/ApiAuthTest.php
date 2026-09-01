<?php

namespace Tests\Feature\Api\V1;

use App\Enums\LanguageEnum;
use App\Enums\PageTypeEnum;
use App\Enums\PublishStatusEnum;
use App\Models\Page;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Seguridad de la API v1 por token Bearer (Sanctum) — ver ADR-018.
 */
class ApiAuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantWithHomePage(string $slug): Tenant
    {
        $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'is_active' => true]);

        Page::create([
            'tenant_id' => $tenant->id,
            'lang_iso' => LanguageEnum::Spanish,
            'slug' => 'home',
            'title' => 'Home',
            'type' => PageTypeEnum::Page,
            'status' => PublishStatusEnum::Published,
        ]);

        return $tenant;
    }

    public function test_request_without_a_token_returns_401(): void
    {
        $this->makeTenantWithHomePage('tenant-a');

        $response = $this->getJson('/v1/tenant-a/pages/home');

        $response->assertStatus(401);
        $response->assertJson(['success' => false, 'status_code' => 401]);
        $response->assertJsonPath('errors.code', 'unauthenticated');
        $response->assertJsonPath('message', 'No autenticado. Enviá un token Bearer en Authorization.');
    }

    /**
     * Bug real (no de entorno): sin header `Accept: application/json` —
     * `getJson()` lo manda solo, así que hay que usar `get()` a propósito
     * — `Authenticate::unauthenticated()` evalúa `expectsJson() === false`
     * e intentaba resolver `route('login')` (`redirectGuestsTo` por
     * defecto de Laravel), ruta que no existe en esta app headless.
     * Terminaba en un `422` con `"Route [login] not defined."` en vez de
     * un `401` limpio. Ver `bootstrap/app.php` (`redirectGuestsTo`).
     */
    public function test_request_without_json_accept_header_still_returns_a_clean_401(): void
    {
        $this->makeTenantWithHomePage('tenant-a');

        $response = $this->get('/v1/tenant-a/pages/home');

        $response->assertStatus(401);
        $response->assertJson(['success' => false, 'status_code' => 401]);
        $this->assertStringNotContainsString('login', $response->getContent());
        $this->assertStringNotContainsString('Route [login]', $response->getContent());
    }

    public function test_request_with_an_invalid_token_returns_a_distinct_401_message(): void
    {
        $this->makeTenantWithHomePage('tenant-a');

        $response = $this->withHeader('Authorization', 'Bearer token-que-no-existe')
            ->getJson('/v1/tenant-a/pages/home');

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Token inválido o expirado.');
        $response->assertJsonPath('errors.code', 'token_invalid');
    }

    public function test_request_with_a_valid_token_of_the_tenant_returns_200(): void
    {
        $tenant = $this->makeTenantWithHomePage('tenant-a');

        $user = User::create([
            'name' => 'Owner',
            'email' => 'owner@tenant-a.test',
            'password' => 'password',
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($user, ['content:read']);

        $response = $this->getJson('/v1/tenant-a/pages/home');

        $response->assertOk();
        $response->assertJsonPath('data.slug', 'home');
    }

    public function test_token_from_a_different_tenant_is_forbidden(): void
    {
        $tenantA = $this->makeTenantWithHomePage('tenant-a');
        $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b', 'is_active' => true]);

        $userB = User::create([
            'name' => 'Owner B',
            'email' => 'owner@tenant-b.test',
            'password' => 'password',
            'tenant_id' => $tenantB->id,
        ]);

        Sanctum::actingAs($userB, ['content:read']);

        // El token es válido (pertenece a tenant-b) pero pide contenido de tenant-a.
        $response = $this->getJson('/v1/tenant-a/pages/home');

        $response->assertStatus(403);
        $response->assertJsonPath('errors.code', 'forbidden');
    }

    public function test_token_without_the_required_ability_is_forbidden(): void
    {
        $tenant = $this->makeTenantWithHomePage('tenant-a');

        $user = User::create([
            'name' => 'Owner',
            'email' => 'owner@tenant-a.test',
            'password' => 'password',
            'tenant_id' => $tenant->id,
        ]);

        // Solo `forms:submit`, no `content:read`.
        Sanctum::actingAs($user, ['forms:submit']);

        $response = $this->getJson('/v1/tenant-a/pages/home');

        $response->assertStatus(403);
        $response->assertJsonPath('errors.code', 'forbidden');
        // El mensaje default de Sanctum ("Invalid ability provided.") nunca
        // debe llegar al cliente tal cual — siempre el genérico en español.
        $response->assertJsonPath('message', 'No tenés permiso para este recurso.');
    }

    public function test_revoked_token_returns_401_on_subsequent_requests(): void
    {
        $tenant = $this->makeTenantWithHomePage('tenant-a');

        $user = User::create([
            'name' => 'Owner',
            'email' => 'owner@tenant-a.test',
            'password' => 'password',
            'tenant_id' => $tenant->id,
        ]);

        $newToken = $user->createToken('test-token', ['content:read']);
        $plainTextToken = $newToken->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$plainTextToken)
            ->getJson('/v1/tenant-a/pages/home')
            ->assertOk();

        // Revocar el token (lo que hace el botón "Revocar" en Console).
        $newToken->accessToken->delete();

        // El RequestGuard de Sanctum cachea el usuario resuelto en la
        // instancia de guard; como este test simula dos requests HTTP dentro
        // del mismo método sin reiniciar la Application, hay que olvidar los
        // guards para que la segunda request vuelva a resolver el token
        // contra la base de datos (donde ya no existe). En un request real
        // esto no aplica: cada request HTTP real arranca una Application
        // nueva.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$plainTextToken)
            ->getJson('/v1/tenant-a/pages/home')
            ->assertStatus(401);
    }
}
