<?php

namespace Tests\Feature\Filament;

use App\Enums\ApiTokenPlatformEnum;
use App\Filament\Pages\ApiTokens;
use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 2026-09-12 (ADR-059, addendum): "dominios desde donde se usara el
 * cliente o detectara como refer o algo asi" — el Tech Lead no encontraba
 * dónde declarar/editar el dominio de un token YA existente (el form de
 * `createToken` solo lo permite setear al crear). Nueva acción de tabla
 * `editOrigin`, que edita `platform`/`allowed_origin` sin tocar el secreto
 * del token (no requiere revocar/regenerar).
 */
class ApiTokensEditOriginTest extends TestCase
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

    private function bootPanel(Tenant $tenant, User $user): void
    {
        $this->actingAs($user);

        Filament::setCurrentPanel(Filament::getPanel('cms'));
        Filament::setTenant($tenant);
    }

    public function test_setting_platform_and_origin_on_a_token_created_without_them(): void
    {
        [$tenant, $user] = $this->makeTenantWithUser();
        $this->bootPanel($tenant, $user);

        $token = $user->createToken('frontend-produccion', ['forms:submit']);
        $record = $token->accessToken;
        $originalPlainText = $token->plainTextToken;

        Livewire::test(ApiTokens::class)
            ->callTableAction('editOrigin', $record, data: [
                'platform' => ApiTokenPlatformEnum::Web->value,
                'allowed_origin' => 'cica360.com',
            ])
            ->assertHasNoTableActionErrors();

        $record->refresh();
        $this->assertSame('web', $record->platform);
        $this->assertSame('cica360.com', $record->allowed_origin);

        // El secreto del token no cambió: sigue autenticando igual.
        $this->assertNotNull(PersonalAccessToken::findToken($originalPlainText));
    }

    public function test_switching_back_to_app_clears_the_allowed_origin(): void
    {
        [$tenant, $user] = $this->makeTenantWithUser();
        $this->bootPanel($tenant, $user);

        $token = $user->createToken('app-movil', ['content:read']);
        $token->accessToken->forceFill([
            'platform' => ApiTokenPlatformEnum::Web->value,
            'allowed_origin' => 'viejo-dominio.com',
        ])->save();

        $record = $token->accessToken;

        Livewire::test(ApiTokens::class)
            ->callTableAction('editOrigin', $record, data: [
                'platform' => ApiTokenPlatformEnum::App->value,
            ])
            ->assertHasNoTableActionErrors();

        $record->refresh();
        $this->assertSame('app', $record->platform);
        $this->assertNull($record->allowed_origin);
    }

    public function test_editing_requires_allowed_origin_when_platform_is_web(): void
    {
        [$tenant, $user] = $this->makeTenantWithUser();
        $this->bootPanel($tenant, $user);

        $token = $user->createToken('frontend-produccion', ['forms:submit']);
        $record = $token->accessToken;

        Livewire::test(ApiTokens::class)
            ->callTableAction('editOrigin', $record, data: [
                'platform' => ApiTokenPlatformEnum::Web->value,
                'allowed_origin' => '',
            ])
            ->assertHasTableActionErrors(['allowed_origin' => 'required']);

        $record->refresh();
        $this->assertNull($record->allowed_origin);
    }

    /**
     * 2026-09-13 (13va vuelta, addendum ADR-059): las columnas `platform`/
     * `allowed_origin` (y su acceso clickeable vía
     * `triggerEditOriginAction()`) se sacaron de la tabla — quedaban
     * duplicadas con la descripción de "Nombre", que ya muestra Plataforma
     * (badge) y Dominio. Este test ya no puede verificar un click de
     * columna (esa columna no existe más); en su lugar confirma que la
     * acción de fila "Editar plataforma/origen" sigue montándose
     * correctamente para un token existente, que es ahora la única forma
     * de editar esos campos sin revocar el token.
     */
    public function test_the_edit_origin_row_action_mounts_for_an_existing_token(): void
    {
        [$tenant, $user] = $this->makeTenantWithUser();
        $this->bootPanel($tenant, $user);

        $token = $user->createToken('frontend-produccion', ['forms:submit']);
        $record = $token->accessToken;

        Livewire::test(ApiTokens::class)
            ->mountTableAction('editOrigin', $record)
            ->assertActionMounted(TestAction::make('editOrigin')->table($record));
    }

    public function test_a_user_cannot_edit_another_users_token_in_the_same_tenant(): void
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
            ->callTableAction('editOrigin', $tokenB->accessToken, data: [
                'platform' => ApiTokenPlatformEnum::Web->value,
                'allowed_origin' => 'intento-ajeno.com',
            ])
            ->assertForbidden();

        $tokenB->accessToken->refresh();
        $this->assertNull($tokenB->accessToken->platform);
    }
}
