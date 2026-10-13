<?php

namespace Database\Seeders;

use App\Enums\BillingCycleEnum;
use App\Enums\LanguageEnum;
use App\Enums\PlanEnum;
use App\Enums\SubscriptionStatusEnum;
use App\Enums\UserRoleEnum;
use App\Models\Domain;
use App\Models\Module;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Slider;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class Cliente0Seeder extends Seeder
{
    private const string TENANT_SLUG = 'cica360';

    private const string LEGACY_TENANT_SLUG = 'cliente-0';

    private const string TENANT_NAME = 'CICA360';

    private const string OWNER_EMAIL = 'goncalvez.isaac@gmail.com';

    private const string OWNER_NAME = 'Isaac Goncalvez';

    private const string OWNER_PASSWORD = 'c1c4@2026.';

    private static function consoleDomain(): string
    {
        return parse_url(config('stamless.urls.studio', config('app.url')), PHP_URL_HOST);
    }

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $tenant = $this->upsertTenant();
        $this->upsertDomain($tenant);
        $this->upsertOwner($tenant);
        $this->upsertSubscription($tenant);
        $this->activateCoreModules($tenant);
        $this->upsertSettings($tenant);
        $this->upsertPlaceholderSlider($tenant);
    }

    /**
     * Crea el tenant o, si ya existía con el slug placeholder original, lo renombra in-place.
     */
    private function upsertTenant(): Tenant
    {
        $tenant = Tenant::where('slug', self::TENANT_SLUG)->first()
            ?? Tenant::where('slug', self::LEGACY_TENANT_SLUG)->first();

        $attributes = [
            'name' => self::TENANT_NAME,
            'slug' => self::TENANT_SLUG,
            'plan' => PlanEnum::Sponsorship->value,
            'is_active' => true,
        ];

        if ($tenant) {
            $tenant->fill($attributes)->save();

            return $tenant;
        }

        return Tenant::create($attributes);
    }

    private function upsertDomain(Tenant $tenant): void
    {
        Domain::updateOrCreate(
            ['domain' => self::consoleDomain()],
            ['tenant_id' => $tenant->id, 'is_primary' => true]
        );
    }

    /**
     * Usuario OWNER del tenant (Isaac Goncalvez).
     */
    private function upsertOwner(Tenant $tenant): void
    {
        $owner = User::where('email', self::OWNER_EMAIL)->first();

        if ($owner) {
            $owner->fill([
                'name' => self::OWNER_NAME,
                'email' => self::OWNER_EMAIL,
                'password' => self::OWNER_PASSWORD,
                'tenant_id' => $tenant->id,
                'must_change_password' => false,
            ])->save();
        } else {
            $owner = User::create([
                'name' => self::OWNER_NAME,
                'email' => self::OWNER_EMAIL,
                'password' => self::OWNER_PASSWORD,
                'must_change_password' => false,
                'tenant_id' => $tenant->id,
                'email_verified_at' => now(),
            ]);
        }

        setPermissionsTeamId($tenant->id);
        $adminRole = Role::firstOrCreate([
            'name' => UserRoleEnum::Admin->value,
            'guard_name' => 'web',
            'tenant_id' => $tenant->id,
        ]);
        $owner->assignRole($adminRole);
    }

    private function upsertSubscription(Tenant $tenant): void
    {
        $plan = Plan::where('slug', $tenant->plan)->first()
            ?? Plan::where('slug', PlanEnum::Sponsorship->value)->first()
            ?? Plan::where('slug', PlanEnum::Trial->value)->first();

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
            'site_name' => self::TENANT_NAME,
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

    /**
     * Slider placeholder para el home.
     */
    private function upsertPlaceholderSlider(Tenant $tenant): void
    {
        Slider::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'lang_iso' => LanguageEnum::Spanish->value,
                'slug' => 'home',
            ],
            [
                'title' => __('Main Slider'),
                'is_active' => true,
                'properties' => ['show_scroll_indicator' => true],
            ]
        );
    }
}
