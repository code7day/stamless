<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ApiTokens;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 2026-09-12, pedido del Tech Lead: "para plan free / auspiciador deberia
 * permitir un limite de tokens, para free 5 y para asupicio 10" — ver
 * `Tenant::maxApiTokens()` / `App\Filament\Pages\ApiTokens::
 * isTokenLimitReached()`.
 */
class ApiTokensLimitTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantWithUser(string $plan, string $slug = 'tenant-a'): array
    {
        $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'is_active' => true, 'plan' => $plan]);

        $user = User::create([
            'name' => 'Owner',
            'email' => "owner@{$slug}.test",
            'password' => 'password',
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

    private function createTokenAction(array $overrides = []): array
    {
        return array_merge([
            'name' => 'token-de-prueba',
            'abilities' => ['content:read'],
            'expiration' => 'never',
        ], $overrides);
    }

    public function test_free_plan_allows_creating_up_to_5_tokens(): void
    {
        [$tenant, $user] = $this->makeTenantWithUser('free');
        $this->bootPanel($tenant, $user);

        for ($i = 1; $i <= 5; $i++) {
            Livewire::test(ApiTokens::class)
                ->callAction('createToken', $this->createTokenAction(['name' => "token-{$i}"]))
                ->assertHasNoActionErrors();
        }

        $this->assertSame(5, PersonalAccessToken::where('tokenable_id', $user->id)->count());
    }

    public function test_free_plan_blocks_the_6th_token(): void
    {
        [$tenant, $user] = $this->makeTenantWithUser('free');

        for ($i = 1; $i <= 5; $i++) {
            $user->createToken("existente-{$i}", ['content:read']);
        }

        $this->bootPanel($tenant, $user);

        // `->before()` + `$action->halt()` es el enforcement real (server
        // side) — `->disabled()` en el botón es solo UX, no lo que impide
        // de verdad la creación: por eso el test llama a la action igual,
        // simulando que alguien la disparó de todas formas (ej. un tab
        // viejo abierto antes de llegar al límite).
        Livewire::test(ApiTokens::class)
            ->callAction('createToken', $this->createTokenAction(['name' => 'sexto-token']));

        $this->assertSame(5, PersonalAccessToken::where('tokenable_id', $user->id)->count());
        $this->assertFalse(
            PersonalAccessToken::where('tokenable_id', $user->id)->where('name', 'sexto-token')->exists()
        );
    }

    public function test_sponsorship_plan_allows_creating_up_to_10_tokens(): void
    {
        [$tenant, $user] = $this->makeTenantWithUser('sponsorship');

        for ($i = 1; $i <= 9; $i++) {
            $user->createToken("existente-{$i}", ['content:read']);
        }

        $this->bootPanel($tenant, $user);

        Livewire::test(ApiTokens::class)
            ->callAction('createToken', $this->createTokenAction(['name' => 'decimo-token']))
            ->assertHasNoActionErrors();

        $this->assertSame(10, PersonalAccessToken::where('tokenable_id', $user->id)->count());
    }

    public function test_sponsorship_plan_blocks_the_11th_token(): void
    {
        [$tenant, $user] = $this->makeTenantWithUser('sponsorship');

        for ($i = 1; $i <= 10; $i++) {
            $user->createToken("existente-{$i}", ['content:read']);
        }

        $this->bootPanel($tenant, $user);

        Livewire::test(ApiTokens::class)
            ->callAction('createToken', $this->createTokenAction(['name' => 'onceavo-token']));

        $this->assertSame(10, PersonalAccessToken::where('tokenable_id', $user->id)->count());
    }

    /**
     * Un token ya EXPIRADO no ocupa un cupo — el conteo del límite solo
     * mira tokens activos (`expires_at IS NULL OR expires_at > now()`).
     */
    public function test_expired_tokens_do_not_count_against_the_limit(): void
    {
        [$tenant, $user] = $this->makeTenantWithUser('free');

        for ($i = 1; $i <= 4; $i++) {
            $user->createToken("activo-{$i}", ['content:read']);
        }

        // Un 5to token, pero ya vencido — no debería contar.
        $user->createToken('vencido', ['content:read'], now()->subDay());

        $this->bootPanel($tenant, $user);

        Livewire::test(ApiTokens::class)
            ->callAction('createToken', $this->createTokenAction(['name' => 'quinto-activo']))
            ->assertHasNoActionErrors();

        $this->assertTrue(
            PersonalAccessToken::where('tokenable_id', $user->id)->where('name', 'quinto-activo')->exists()
        );
    }

    /**
     * Un plan sin tope explícito definido (ni `free`/`freemium` ni
     * `sponsorship`) queda sin límite — `Tenant::maxApiTokens()` devuelve
     * `null` por defecto, mismo criterio que el resto de topes del MVP.
     */
    public function test_a_plan_without_an_explicit_limit_is_unlimited(): void
    {
        [$tenant, $user] = $this->makeTenantWithUser('enterprise-a-medida');

        for ($i = 1; $i <= 12; $i++) {
            $user->createToken("token-{$i}", ['content:read']);
        }

        $this->bootPanel($tenant, $user);

        Livewire::test(ApiTokens::class)
            ->callAction('createToken', $this->createTokenAction(['name' => 'token-13']))
            ->assertHasNoActionErrors();

        $this->assertSame(13, PersonalAccessToken::where('tokenable_id', $user->id)->count());
    }

    /**
     * El límite es POR TENANT (suma de tokens de todos sus users), no por
     * user individual — igual que el resto de los topes de contenido del
     * MVP (`Tenant::maxPosts()`, etc.), ya que `getTableQuery()` de
     * `ApiTokens` agrupa todos los users del tenant.
     */
    public function test_the_limit_is_shared_across_all_users_of_the_same_tenant(): void
    {
        [$tenant, $userA] = $this->makeTenantWithUser('free');

        $userB = User::create([
            'name' => 'User B',
            'email' => 'userb@tenant-a.test',
            'password' => 'password',
            'tenant_id' => $tenant->id,
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $userA->createToken("de-a-{$i}", ['content:read']);
        }

        for ($i = 1; $i <= 2; $i++) {
            $userB->createToken("de-b-{$i}", ['content:read']);
        }

        $this->bootPanel($tenant, $userA);

        // Ya hay 5 tokens en el tenant (3 + 2, de dos users distintos) —
        // el límite free (5) ya está alcanzado, sin importar de quién es
        // cada uno.
        Livewire::test(ApiTokens::class)
            ->callAction('createToken', $this->createTokenAction(['name' => 'sexto-del-tenant']));

        $this->assertFalse(
            PersonalAccessToken::where('name', 'sexto-del-tenant')->exists()
        );
    }
}
