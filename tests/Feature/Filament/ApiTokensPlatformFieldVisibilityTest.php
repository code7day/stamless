<?php

namespace Tests\Feature\Filament;

use App\Enums\ApiTokenPlatformEnum;
use App\Filament\Pages\ApiTokens;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 2026-09-13, bug real reportado por el Tech Lead: "pero solo me pide
 * seleccionar si es WEB o api pero si eso ya lo lleno en 'Editar
 * plataforma/origen'" / "al crear uno nuevo solo tengo esto tambien no hay
 * donde registrar dominios" — el campo "Dominio permitido" nunca aparecía,
 * ni al crear un token ni al editar uno existente, sin importar qué se
 * eligiera en "Plataforma".
 *
 * Causa raíz: `Select::options(ApiTokenPlatformEnum::class)` registra
 * internamente un `EnumStateCast` (`Select::getDefaultStateCasts()`), así
 * que `$get('platform')` devuelve la INSTANCIA del enum
 * (`ApiTokenPlatformEnum::Web`), no su `->value` (string `'web'`). El
 * código original comparaba `$get('platform') === ApiTokenPlatformEnum::
 * Web->value` — objeto contra string, siempre `false` vía `===`. Fix:
 * comparar contra el CASE del enum directamente.
 *
 * Este test cubre específicamente la VISIBILIDAD del campo (lo que el
 * Tech Lead vio en pantalla) — `ApiTokensEditOriginTest` ya cubre que el
 * VALOR guardado sea correcto una vez que el campo es visible y se llena.
 */
class ApiTokensPlatformFieldVisibilityTest extends TestCase
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

    public function test_allowed_origin_is_hidden_on_create_token_until_web_platform_is_selected(): void
    {
        [$tenant, $user] = $this->makeTenantWithUser();
        $this->bootPanel($tenant, $user);

        Livewire::test(ApiTokens::class)
            ->mountAction('createToken')
            ->assertFormFieldIsHidden('allowed_origin')
            ->fillForm(['platform' => ApiTokenPlatformEnum::Web->value])
            ->assertFormFieldIsVisible('allowed_origin');
    }

    public function test_allowed_origin_stays_hidden_on_create_token_when_app_platform_is_selected(): void
    {
        [$tenant, $user] = $this->makeTenantWithUser();
        $this->bootPanel($tenant, $user);

        Livewire::test(ApiTokens::class)
            ->mountAction('createToken')
            ->fillForm(['platform' => ApiTokenPlatformEnum::App->value])
            ->assertFormFieldIsHidden('allowed_origin');
    }

    public function test_allowed_origin_is_visible_on_edit_origin_when_the_token_is_already_web(): void
    {
        [$tenant, $user] = $this->makeTenantWithUser();
        $this->bootPanel($tenant, $user);

        $token = $user->createToken('frontend-produccion', ['forms:submit']);
        $token->accessToken->forceFill([
            'platform' => ApiTokenPlatformEnum::Web->value,
            'allowed_origin' => 'cica360.com',
        ])->save();

        Livewire::test(ApiTokens::class)
            ->mountTableAction('editOrigin', $token->accessToken)
            ->assertFormFieldIsVisible('allowed_origin');
    }

    public function test_allowed_origin_is_hidden_on_edit_origin_for_a_token_without_a_platform(): void
    {
        [$tenant, $user] = $this->makeTenantWithUser();
        $this->bootPanel($tenant, $user);

        $token = $user->createToken('app-movil', ['content:read']);

        Livewire::test(ApiTokens::class)
            ->mountTableAction('editOrigin', $token->accessToken)
            ->assertFormFieldIsHidden('allowed_origin')
            ->fillForm(['platform' => ApiTokenPlatformEnum::Web->value])
            ->assertFormFieldIsVisible('allowed_origin');
    }
}
