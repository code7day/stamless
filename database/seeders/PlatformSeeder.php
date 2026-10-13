<?php

namespace Database\Seeders;

use App\Enums\BillingCycleEnum;
use App\Enums\LanguageEnum;
use App\Enums\PlanEnum;
use App\Enums\SubscriptionStatusEnum;
use App\Enums\UserRoleEnum;
use App\Models\Module;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class PlatformSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Super-admin Master (Nivel GOD / General de generales): `zedu77@gmail.com`
     * con `is_super_admin = true`, acceso irrestricto a Platform y Studio,
     * tenant propio inicial con plan Pro,
     * habilitado tanto para autenticación directa por contraseña como
     * por Social Login (Google / Gmail).
     *
     * Contraseña de desarrollo únicamente: cambiar/rotar antes de cualquier
     * despliegue real. El cast `hashed` de `User` la hashea al guardar.
     */
    public function run(): void
    {
        // Tenant inicial propio para el Super Admin Master (Plan Pro)
        $masterTenant = Tenant::updateOrCreate(
            ['slug' => 'edukt'],
            [
                'name' => 'Eduardo Flores',
                'slug' => 'edukt',
                'plan' => PlanEnum::Pro->value,
                'is_active' => true,
            ]
        );

        $this->upsertSubscription($masterTenant);
        $this->activateAllModules($masterTenant);
        $this->upsertSettings($masterTenant, 'Eduardo Flores');

        // Super Admin Master Principal (Nivel GOD)
        $masterUser = User::updateOrCreate(
            ['email' => 'zedu77@gmail.com'],
            [
                'name' => 'Eduardo Flores',
                'password' => 'password123',
                'tenant_id' => $masterTenant->id,
                'is_super_admin' => true,
                'email_verified_at' => now(),
            ]
        );

        setPermissionsTeamId($masterTenant->id);
        $adminRole = Role::firstOrCreate([
            'name' => UserRoleEnum::Admin->value,
            'guard_name' => 'web',
            'tenant_id' => $masterTenant->id,
        ]);
        $masterUser->assignRole($adminRole);

        // Administrador de soporte / manager de plataforma (GOD user con tenant Premium)
        $adminTenant = Tenant::updateOrCreate(
            ['slug' => 'stamless'],
            [
                'name' => 'Stamless HQ',
                'slug' => 'stamless',
                'plan' => PlanEnum::Premium->value,
                'is_active' => true,
            ]
        );

        $this->upsertSubscription($adminTenant);
        $this->activateAllModules($adminTenant);
        $this->upsertSettings($adminTenant, 'Stamless HQ');

        $adminUser = User::updateOrCreate(
            ['email' => 'admin@stamless.com'],
            [
                'name' => 'Platform Manager Admin',
                'password' => 'password123',
                'tenant_id' => $adminTenant->id,
                'is_super_admin' => true,
                'email_verified_at' => now(),
            ]
        );

        setPermissionsTeamId($adminTenant->id);
        $adminCorpRole = Role::firstOrCreate([
            'name' => UserRoleEnum::Admin->value,
            'guard_name' => 'web',
            'tenant_id' => $adminTenant->id,
        ]);
        $adminUser->assignRole($adminCorpRole);
    }

    private function upsertSubscription(Tenant $tenant): void
    {
        $plan = Plan::where('slug', $tenant->plan)->first()
            ?? Plan::where('slug', PlanEnum::Premium->value)->first()
            ?? Plan::where('slug', PlanEnum::Pro->value)->first();

        if (! $plan) {
            return;
        }

        Subscription::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'plan_id' => $plan->id,
                'status' => SubscriptionStatusEnum::Active->value,
                'billing_cycle' => BillingCycleEnum::Monthly->value,
                'current_period_start' => now(),
            ]
        );
    }

    private function activateCoreModules(Tenant $tenant): void
    {
        Module::where('is_core', true)->get()->each(
            fn (Module $module) => TenantModule::updateOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $module->id],
                ['is_active' => true, 'activated_at' => now()]
            )
        );
    }

    private function activateAllModules(Tenant $tenant): void
    {
        Module::all()->each(
            fn (Module $module) => TenantModule::updateOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $module->id],
                ['is_active' => true, 'activated_at' => now()]
            )
        );
    }

    private function upsertSettings(Tenant $tenant, string $siteName = 'Eduardo Flores'): void
    {
        $settings = [
            'site_name' => $siteName,
            'default_locale' => LanguageEnum::Spanish->value,
            'available_locales' => LanguageEnum::Spanish->value,
        ];

        foreach ($settings as $key => $value) {
            Setting::updateOrCreate(
                ['tenant_id' => $tenant->id, 'key' => $key],
                ['value' => $value, 'type' => 'string']
            );
        }
    }
}
