<?php

namespace Database\Seeders;

use App\Enums\BillingCycleEnum;
use App\Enums\LanguageEnum;
use App\Enums\SubscriptionStatusEnum;
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
     * tenant propio inicial con plan Auspicio/Convenio (`sponsorship`),
     * habilitado tanto para autenticación directa por contraseña como
     * por Social Login (Google / Gmail).
     *
     * Contraseña de desarrollo únicamente: cambiar/rotar antes de cualquier
     * despliegue real. El cast `hashed` de `User` la hashea al guardar.
     */
    public function run(): void
    {
        // Tenant inicial propio para el Super Admin Master (Plan Auspicio)
        $masterTenant = Tenant::updateOrCreate(
            ['slug' => 'stamless'],
            [
                'name' => 'Eduardo Flores',
                'slug' => 'stamless',
                'plan' => 'sponsorship',
                'is_active' => true,
            ]
        );

        $this->upsertSubscription($masterTenant);
        $this->activateCoreModules($masterTenant);
        $this->upsertSettings($masterTenant);

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
            'name' => 'Admin',
            'guard_name' => 'web',
            'tenant_id' => $masterTenant->id,
        ]);
        $masterUser->assignRole($adminRole);

        // Administrador de soporte / manager de plataforma (sin tenant específico)
        User::updateOrCreate(
            ['email' => 'admin@stamless.com'],
            [
                'name' => 'Platform Manager Admin',
                'password' => 'password123',
                'tenant_id' => null,
                'is_super_admin' => true,
                'email_verified_at' => now(),
            ]
        );
    }

    private function upsertSubscription(Tenant $tenant): void
    {
        $plan = Plan::where('slug', $tenant->plan)->first()
            ?? Plan::where('slug', 'sponsorship')->first()
            ?? Plan::where('slug', 'free')->first();

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

    private function upsertSettings(Tenant $tenant): void
    {
        $settings = [
            'site_name' => 'Eduardo Flores',
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
