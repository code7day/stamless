<?php

namespace Database\Seeders;

use App\Enums\BillingCycleEnum;
use App\Enums\LanguageEnum;
use App\Enums\SubscriptionStatusEnum;
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

class Cliente0Seeder extends Seeder
{
    private const string TENANT_SLUG = 'cica360';

    /**
     * Slug placeholder usado en el primer scaffold, antes de conocer el
     * nombre real del Cliente 0. Se busca por acá primero para renombrar
     * el tenant existente en vez de crear uno duplicado.
     */
    private const string LEGACY_TENANT_SLUG = 'cliente-0';

    private const string TENANT_NAME = 'CICA360';

    private const string OWNER_EMAIL = 'owner@cica360.com';

    private const string LEGACY_OWNER_EMAIL = 'admin@cliente0.com';

    private static function consoleDomain(): string
    {
        return parse_url(config('genesis.urls.console', config('app.url')), PHP_URL_HOST);
    }

    /**
     * Seed the application's database.
     *
     * Requiere que `PlanSeeder` y `ModuleSeeder` hayan corrido antes
     * (necesita el plan Free y los módulos core ya creados).
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
     * Crea el tenant o, si ya existía con el slug placeholder original,
     * lo renombra in-place (mismo id, sin duplicar).
     */
    private function upsertTenant(): Tenant
    {
        $tenant = Tenant::where('slug', self::TENANT_SLUG)->first()
            ?? Tenant::where('slug', self::LEGACY_TENANT_SLUG)->first();

        $attributes = [
            'name' => self::TENANT_NAME,
            'slug' => self::TENANT_SLUG,
            // 2026-09-02, ADR-043: CICA360 pasa de `'free'` a `'sponsorship'`
            // (plan "Auspicio/Convenio", pedido en vivo del Tech Lead: "este
            // cliente 0 de cica360 será uno de ellos Plan
            // Auspicio/Convención"). Sigue siendo free-tier para todo lo
            // demás (`Tenant::isFreeTier()` ya incluye `'sponsorship'`) —
            // la única diferencia real es que ahora puede personalizar el
            // copyright del footer de forma acotada (año + nombre, dentro
            // de la plantilla fija con "Powered by Stamless"). No reemplaza
            // ADR-006 (Free Forever + Headless) como concepto general para
            // futuros tenants — solo reasigna el plan de ESTE tenant.
            'plan' => 'sponsorship',
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
     * Usuario OWNER del tenant. El plan Free permite `max_users = 1`, así
     * que se reutiliza/renombra el usuario admin legado en vez de crear
     * uno nuevo. La asignación formal de rol OWNER queda pendiente hasta
     * que exista el módulo de roles/permissions (ver ADR-013 / TASK.md #12);
     * por ahora es, funcionalmente, el único usuario del tenant.
     *
     * La contraseña de desarrollo solo se setea al crear el usuario por
     * primera vez, nunca al renombrar uno existente.
     */
    private function upsertOwner(Tenant $tenant): void
    {
        $owner = User::where('email', self::OWNER_EMAIL)->first()
            ?? User::where('email', self::LEGACY_OWNER_EMAIL)->first();

        if ($owner) {
            $owner->fill([
                'name' => 'CICA360 Owner',
                'email' => self::OWNER_EMAIL,
                'tenant_id' => $tenant->id,
            ])->save();

            return;
        }

        User::create([
            'name' => 'CICA360 Owner',
            'email' => self::OWNER_EMAIL,
            'password' => 'password123',
            'tenant_id' => $tenant->id,
            'email_verified_at' => now(),
        ]);
    }

    private function upsertSubscription(Tenant $tenant): void
    {
        $plan = Plan::where('slug', 'free')->first();

        if (! $plan) {
            return;
        }

        Subscription::firstOrCreate(
            ['tenant_id' => $tenant->id, 'plan_id' => $plan->id],
            [
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
            'site_name' => 'CICA360',
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
     * Slider placeholder para el home, sin slides todavía (contenido demo
     * queda fuera de alcance de esta sesión).
     *
     * `properties.show_scroll_indicator` (2026-08-31): una sola vez a nivel
     * Slider, aplica a todas las slides detrás (sobrepuesta al decorador
     * inferior de cada una) — corrección del Tech Lead sobre la primera
     * pasada del mismo día, que la había sembrado por slide en
     * `Cliente0HomeSlidesSeeder`.
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
                'title' => 'Slider principal',
                'is_active' => true,
                'properties' => ['show_scroll_indicator' => true],
            ]
        );
    }
}
