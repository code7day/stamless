<?php

namespace Database\Seeders;

use App\Enums\LanguageEnum;
use App\Enums\PlanEnum;
use App\Models\Plan;
use App\Models\PlanFeature;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Límites y características del plan Trial (15 días de prueba).
     */
    private const array TRIAL_FEATURES = [
        'max_users' => ['value' => '1', 'label' => 'Usuarios'],
        'max_pages' => ['value' => '10', 'label' => 'Páginas y contenidos'],
        'max_posts' => ['value' => '10', 'label' => 'Entradas de blog'],
        'max_services' => ['value' => '5', 'label' => 'Servicios'],
        'max_testimonials' => ['value' => '3', 'label' => 'Testimonios / Casos de éxito'],
        'max_sliders' => ['value' => '1', 'label' => 'Sliders'],
        'max_media' => ['value' => '20', 'label' => 'Archivos multimedia'],
        'max_storage_mb' => ['value' => '250', 'label' => 'Almacenamiento (MB)'],
        'max_menus' => ['value' => '3', 'label' => 'Menús de navegación'],
        'max_menu_items' => ['value' => '5', 'label' => 'Items por menú'],
        'max_api_tokens' => ['value' => '5', 'label' => 'API Tokens'],
        'trial_days' => ['value' => '15', 'label' => 'Días de prueba'],
        'modules_vertical' => ['value' => 'false', 'label' => 'Módulos verticales'],
        'custom_copyright' => ['value' => 'false', 'label' => 'Copyright estándar fijo'],
        'brand_personalization' => ['value' => 'false', 'label' => 'Nombre del proyecto en Studio'],
    ];

    /**
     * Límites y características del plan Starter (USD 7/mes, USD 70/año).
     * Ideal para estudiantes, practicantes, portafolios y proyectos iniciales (50 posts activos).
     */
    private const array STARTER_FEATURES = [
        'max_users' => ['value' => '2', 'label' => 'Usuarios'],
        'max_pages' => ['value' => '30', 'label' => 'Páginas y contenidos'],
        'max_posts' => ['value' => '50', 'label' => 'Entradas de blog'],
        'max_services' => ['value' => '15', 'label' => 'Servicios'],
        'max_testimonials' => ['value' => '15', 'label' => 'Testimonios / Casos de éxito'],
        'max_sliders' => ['value' => '3', 'label' => 'Sliders'],
        'max_media' => ['value' => '100', 'label' => 'Archivos multimedia'],
        'max_storage_mb' => ['value' => '1024', 'label' => 'Almacenamiento (MB)'],
        'max_menus' => ['value' => '5', 'label' => 'Menús de navegación'],
        'max_menu_items' => ['value' => '10', 'label' => 'Items por menú'],
        'max_api_tokens' => ['value' => '5', 'label' => 'API Tokens'],
        'modules_vertical' => ['value' => 'false', 'label' => 'Módulos verticales'],
        'custom_copyright' => ['value' => 'true', 'label' => 'Copyright editable sin marca'],
        'brand_personalization' => ['value' => 'true', 'label' => 'Nombre del proyecto en Studio'],
    ];

    /**
     * Límites y características del plan Auspicio / Convenio (slug: sponsorship, B2B).
     * Mismas bases que Starter pero con recursos ampliados para proyectos con convenio institucional o patrocinio.
     */
    private const array SPONSORSHIP_FEATURES = [
        'max_users' => ['value' => '3', 'label' => 'Usuarios'],
        'max_pages' => ['value' => '50', 'label' => 'Páginas y contenidos'],
        'max_posts' => ['value' => '100', 'label' => 'Entradas de blog'],
        'max_services' => ['value' => '25', 'label' => 'Servicios'],
        'max_testimonials' => ['value' => '25', 'label' => 'Testimonios / Casos de éxito'],
        'max_sliders' => ['value' => '5', 'label' => 'Sliders'],
        'max_media' => ['value' => '150', 'label' => 'Archivos multimedia'],
        'max_storage_mb' => ['value' => '2048', 'label' => 'Almacenamiento (MB)'],
        'max_menus' => ['value' => '5', 'label' => 'Menús de navegación'],
        'max_menu_items' => ['value' => '15', 'label' => 'Items por menú'],
        'max_api_tokens' => ['value' => '10', 'label' => 'API Tokens'],
        'modules_vertical' => ['value' => 'false', 'label' => 'Módulos verticales'],
        'custom_copyright' => ['value' => 'acotado', 'label' => 'Copyright personalizado (Powered by Stamless)'],
        'brand_personalization' => ['value' => 'true', 'label' => 'Nombre del proyecto en Studio'],
    ];

    /**
     * Límites y características del plan Pro (slug: pro, USD 20/mes, USD 200/año).
     */
    private const array PRO_FEATURES = [
        'max_users' => ['value' => '10', 'label' => 'Usuarios'],
        'max_pages' => ['value' => '150', 'label' => 'Páginas y contenidos'],
        'max_posts' => ['value' => '250', 'label' => 'Entradas de blog'],
        'max_services' => ['value' => '50', 'label' => 'Servicios'],
        'max_testimonials' => ['value' => '50', 'label' => 'Testimonios / Casos de éxito'],
        'max_sliders' => ['value' => '15', 'label' => 'Sliders'],
        'max_media' => ['value' => '500', 'label' => 'Archivos multimedia'],
        'max_storage_mb' => ['value' => '5120', 'label' => 'Almacenamiento (MB)'],
        'max_menus' => ['value' => '10', 'label' => 'Menús de navegación'],
        'max_menu_items' => ['value' => '30', 'label' => 'Items por menú'],
        'max_api_tokens' => ['value' => '25', 'label' => 'API Tokens'],
        'modules_vertical' => ['value' => 'true', 'label' => 'Módulos verticales'],
        'custom_copyright' => ['value' => 'true', 'label' => 'Copyright editable sin marca'],
        'brand_personalization' => ['value' => 'true', 'label' => 'Nombre y personalización del proyecto en Studio'],
    ];

    /**
     * Límites y características del plan Premium (slug: premium, Desde USD 99/mes, USD 990/año).
     */
    private const array PREMIUM_FEATURES = [
        'max_users' => ['value' => '50', 'label' => 'Usuarios'],
        'max_pages' => ['value' => '1000', 'label' => 'Páginas y contenidos'],
        'max_posts' => ['value' => '1000', 'label' => 'Entradas de blog'],
        'max_services' => ['value' => '150', 'label' => 'Servicios'],
        'max_testimonials' => ['value' => '150', 'label' => 'Testimonios / Casos de éxito'],
        'max_sliders' => ['value' => '50', 'label' => 'Sliders'],
        'max_media' => ['value' => '2000', 'label' => 'Archivos multimedia'],
        'max_storage_mb' => ['value' => '20480', 'label' => 'Almacenamiento (MB)'],
        'max_menus' => ['value' => '30', 'label' => 'Menús de navegación'],
        'max_menu_items' => ['value' => '50', 'label' => 'Items por menú'],
        'max_api_tokens' => ['value' => '100', 'label' => 'API Tokens'],
        'modules_vertical' => ['value' => 'true', 'label' => 'Módulos verticales'],
        'custom_copyright' => ['value' => 'true', 'label' => 'Copyright 100% personalizable'],
        'brand_personalization' => ['value' => 'true', 'label' => 'Personalización total de marca y Studio'],
    ];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Plan Trial (Principal)
        $this->seedPlan(
            slug: PlanEnum::Trial->value,
            attributes: [
                'name' => PlanEnum::Trial->getLabel(),
                'description' => PlanEnum::Trial->getDescription(),
                'price_monthly' => 0,
                'price_yearly' => 0,
                'currency' => 'USD',
                'is_active' => true,
                'is_free' => true,
                'sort_order' => 0,
                'max_users' => 1,
                'max_pages' => 10,
                'max_posts' => 10,
                'max_storage_mb' => 250,
            ],
            features: self::TRIAL_FEATURES
        );

        // Alias legado 'free'
        $this->seedPlan(
            slug: 'free',
            attributes: [
                'name' => 'Free (Legacy Trial)',
                'description' => PlanEnum::Trial->getDescription(),
                'price_monthly' => 0,
                'price_yearly' => 0,
                'currency' => 'USD',
                'is_active' => true,
                'is_free' => true,
                'sort_order' => 0,
                'max_users' => 1,
                'max_pages' => 10,
                'max_posts' => 10,
                'max_storage_mb' => 250,
            ],
            features: self::TRIAL_FEATURES
        );

        // 2. Plan Starter (Principal)
        $this->seedPlan(
            slug: PlanEnum::Starter->value,
            attributes: [
                'name' => PlanEnum::Starter->getLabel(),
                'description' => PlanEnum::Starter->getDescription(),
                'price_monthly' => 7,
                'price_yearly' => 70,
                'currency' => 'USD',
                'is_active' => true,
                'is_free' => false,
                'sort_order' => 1,
                'max_users' => 2,
                'max_pages' => 30,
                'max_posts' => 50,
                'max_storage_mb' => 1024,
            ],
            features: self::STARTER_FEATURES
        );

        // Alias legado 'freelance'
        $this->seedPlan(
            slug: 'freelance',
            attributes: [
                'name' => 'Freelance (Legacy)',
                'description' => PlanEnum::Starter->getDescription(),
                'price_monthly' => 7,
                'price_yearly' => 70,
                'currency' => 'USD',
                'is_active' => true,
                'is_free' => false,
                'sort_order' => 1,
                'max_users' => 2,
                'max_pages' => 30,
                'max_posts' => 50,
                'max_storage_mb' => 1024,
            ],
            features: self::STARTER_FEATURES
        );

        // 3. Plan Sponsorship / Convenio (Principal)
        $this->seedPlan(
            slug: PlanEnum::Sponsorship->value,
            attributes: [
                'name' => PlanEnum::Sponsorship->getLabel(),
                'description' => PlanEnum::Sponsorship->getDescription(),
                'price_monthly' => 0,
                'price_yearly' => 0,
                'currency' => 'USD',
                'is_active' => true,
                'is_free' => true,
                'sort_order' => 2,
                'max_users' => 3,
                'max_pages' => 50,
                'max_posts' => 100,
                'max_storage_mb' => 2048,
            ],
            features: self::SPONSORSHIP_FEATURES
        );

        // 4. Plan Pro (Principal)
        $this->seedPlan(
            slug: PlanEnum::Pro->value,
            attributes: [
                'name' => PlanEnum::Pro->getLabel(),
                'description' => PlanEnum::Pro->getDescription(),
                'price_monthly' => 20,
                'price_yearly' => 200,
                'currency' => 'USD',
                'is_active' => true,
                'is_free' => false,
                'sort_order' => 3,
                'max_users' => 10,
                'max_pages' => 150,
                'max_posts' => 250,
                'max_storage_mb' => 5120,
            ],
            features: self::PRO_FEATURES
        );

        // 5. Plan Premium (Principal)
        $this->seedPlan(
            slug: PlanEnum::Premium->value,
            attributes: [
                'name' => PlanEnum::Premium->getLabel(),
                'description' => PlanEnum::Premium->getDescription(),
                'price_monthly' => 99,
                'price_yearly' => 990,
                'currency' => 'USD',
                'is_active' => true,
                'is_free' => false,
                'sort_order' => 4,
                'max_users' => 50,
                'max_pages' => 1000,
                'max_posts' => 1000,
                'max_storage_mb' => 20480,
            ],
            features: self::PREMIUM_FEATURES
        );

        // Alias legado 'corporate'
        $this->seedPlan(
            slug: 'corporate',
            attributes: [
                'name' => 'Corporate (Legacy)',
                'description' => PlanEnum::Premium->getDescription(),
                'price_monthly' => 99,
                'price_yearly' => 990,
                'currency' => 'USD',
                'is_active' => true,
                'is_free' => false,
                'sort_order' => 5,
                'max_users' => 50,
                'max_pages' => 1000,
                'max_posts' => 1000,
                'max_storage_mb' => 20480,
            ],
            features: self::PREMIUM_FEATURES
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, array{value: string, label: string}>  $features
     */
    private function seedPlan(string $slug, array $attributes, array $features): void
    {
        $plan = Plan::updateOrCreate(['slug' => $slug], $attributes);

        foreach ($features as $key => $feature) {
            PlanFeature::updateOrCreate(
                [
                    'plan_id' => $plan->id,
                    'key' => $key,
                ],
                [
                    'lang_iso' => LanguageEnum::Spanish->value,
                    'value' => $feature['value'],
                    'label' => $feature['label'],
                ]
            );
        }
    }
}
