<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ApiTokens;
use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regenerar API Tokens (Sanctum) desde Console — ver ADR-018 y el ticket
 * "Regenerar API Tokens (Sanctum) en Console".
 */
class ApiTokensRegenerateTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantWithUser(string $slug = 'tenant-a'): array
    {
        $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'is_active' => true]);

        $user = User::create([
            'name' => 'Owner',
            'email' => "owner@{$slug}.test",
            'password' => 'password',
            'tenant_id' => $tenant->id,
        ]);

        return [$tenant, $user];
    }

    /**
     * Simula estar logueado en Console (panel `cms`) con el tenant activo,
     * como lo haría un admin real navegando `studio.stamless.host`.
     */
    private function bootPanel(Tenant $tenant, User $user): void
    {
        $this->actingAs($user);

        Filament::setCurrentPanel(Filament::getPanel('cms'));
        Filament::setTenant($tenant);
    }

    public function test_regenerating_a_token_invalidates_the_old_one_and_issues_a_new_one(): void
    {
        [$tenant, $user] = $this->makeTenantWithUser();
        $this->bootPanel($tenant, $user);

        $old = $user->createToken('frontend-produccion', ['content:read']);
        $oldPlainText = $old->plainTextToken;
        $record = $old->accessToken;

        $response = Livewire::test(ApiTokens::class)
            ->callTableAction('regenerate', $record)
            ->assertHasNoTableActionErrors();

        // El banner de "guardá este token ahora" se llena igual que al crear.
        $newPlainText = $response->get('plainTextToken');
        $this->assertNotNull($newPlainText);
        $this->assertNotSame($oldPlainText, $newPlainText);

        // El token viejo (Bearer) ya no autentica: Sanctum no lo encuentra.
        $this->assertNull(PersonalAccessToken::findToken($oldPlainText));
        $this->assertNull(PersonalAccessToken::find($record->id));

        // El nuevo sí resuelve, apunta al mismo user, y arranca "limpio".
        $newRecord = PersonalAccessToken::findToken($newPlainText);
        $this->assertNotNull($newRecord);
        $this->assertSame($user->id, $newRecord->tokenable_id);
        $this->assertSame('frontend-produccion', $newRecord->name);
        $this->assertNull($newRecord->last_used_at);
        $this->assertNotNull($newRecord->last_four);
    }

    public function test_regenerating_does_not_change_abilities(): void
    {
        [$tenant, $user] = $this->makeTenantWithUser();
        $this->bootPanel($tenant, $user);

        $old = $user->createToken('solo-forms', ['forms:submit']);
        $record = $old->accessToken;

        Livewire::test(ApiTokens::class)
            ->callTableAction('regenerate', $record)
            ->assertHasNoTableActionErrors();

        $newRecord = PersonalAccessToken::where('tokenable_id', $user->id)
            ->where('name', 'solo-forms')
            ->first();

        $this->assertNotNull($newRecord);
        $this->assertSame(['forms:submit'], $newRecord->abilities);
    }

    public function test_regenerating_a_non_expired_token_clones_its_expiration(): void
    {
        [$tenant, $user] = $this->makeTenantWithUser();
        $this->bootPanel($tenant, $user);

        $expiresAt = now()->addDays(90);
        $old = $user->createToken('con-expiracion', ['content:read'], $expiresAt);
        $record = $old->accessToken;

        Livewire::test(ApiTokens::class)
            ->callTableAction('regenerate', $record)
            ->assertHasNoTableActionErrors();

        $newRecord = PersonalAccessToken::where('tokenable_id', $user->id)
            ->where('name', 'con-expiracion')
            ->first();

        $this->assertNotNull($newRecord->expires_at);
        $this->assertEqualsWithDelta($expiresAt->timestamp, $newRecord->expires_at->timestamp, 2);
    }

    public function test_regenerating_an_expired_token_requires_a_new_expiration_choice(): void
    {
        [$tenant, $user] = $this->makeTenantWithUser();
        $this->bootPanel($tenant, $user);

        $old = $user->createToken('vencido', ['content:read'], now()->subDay());
        $record = $old->accessToken;

        // Sin elegir nueva expiración: el form exige el campo.
        Livewire::test(ApiTokens::class)
            ->callTableAction('regenerate', $record, data: ['expiration' => null])
            ->assertHasTableActionErrors(['expiration' => 'required']);

        // Token viejo intacto (nunca se llegó a borrar/regenerar).
        $this->assertNotNull(PersonalAccessToken::find($record->id));

        // Con una expiración elegida, regenera normalmente.
        Livewire::test(ApiTokens::class)
            ->callTableAction('regenerate', $record, data: ['expiration' => '30'])
            ->assertHasNoTableActionErrors();

        $newRecord = PersonalAccessToken::where('tokenable_id', $user->id)
            ->where('name', 'vencido')
            ->first();

        $this->assertNotNull($newRecord);
        $this->assertTrue($newRecord->expires_at->isFuture());
        $this->assertTrue($newRecord->expires_at->lessThanOrEqualTo(now()->addDays(31)));
    }

    public function test_a_user_cannot_regenerate_another_users_token_in_the_same_tenant(): void
    {
        [$tenant, $userA] = $this->makeTenantWithUser('tenant-a');

        $userB = User::create([
            'name' => 'User B',
            'email' => 'userb@tenant-a.test',
            'password' => 'password',
            'tenant_id' => $tenant->id,
        ]);

        $tokenB = $userB->createToken('token-de-b', ['content:read']);

        $this->bootPanel($tenant, $userA);

        Livewire::test(ApiTokens::class)
            ->callTableAction('regenerate', $tokenB->accessToken)
            ->assertForbidden();

        // El token de B queda intacto.
        $this->assertNotNull(PersonalAccessToken::find($tokenB->accessToken->id));
    }

    public function test_a_user_cannot_regenerate_a_token_from_a_different_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser('tenant-a');
        [, $userB] = $this->makeTenantWithUser('tenant-b');

        $tokenB = $userB->createToken('token-de-otro-tenant', ['content:read']);

        $this->bootPanel($tenantA, $userA);

        // El token de tenant-b ni siquiera está en el query de la tabla
        // (getTableQuery() scopea por tenant): Filament no puede resolver
        // el record y tira `ActionNotResolvableException` (excepción PHP
        // interna, no HTTP) ANTES de llegar al `action()` closure donde
        // vive el check de ownership de esta feature — confirmado
        // corriendo la suite real (no es un 404 HTTP limpio como en la
        // API pública, es un comportamiento distinto y ya existente de
        // Filament al pedir un record fuera del query de la tabla).
        try {
            Livewire::test(ApiTokens::class)
                ->callTableAction('regenerate', $tokenB->accessToken);

            $this->fail('Se esperaba ActionNotResolvableException.');
        } catch (ActionNotResolvableException) {
            // Esperado.
        }

        $this->assertNotNull(PersonalAccessToken::find($tokenB->accessToken->id));
    }
}
