<?php

namespace Database\Seeders;

use App\Enums\LanguageEnum;
use App\Models\Plan;
use App\Models\PlanFeature;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Límites y características del plan Free, reutilizados tanto en las columnas propias de
     * `Plan` (chequeos rápidos sin join) como en `plan_features` (listado
     * de features para UI/comparativas, con label y `lang_iso`).
     */
    private const array FREE_FEATURES = [
        'max_users' => ['value' => '1', 'label' => 'Usuarios'],
        'max_pages' => ['value' => '20', 'label' => 'Páginas y contenidos'],
        'max_posts' => ['value' => '10', 'label' => 'Entradas de blog'],
        'max_services' => ['value' => '10', 'label' => 'Servicios'],
        'max_testimonials' => ['value' => '6', 'label' => 'Testimonios / Casos de éxito'],
        'max_sliders' => ['value' => '2', 'label' => 'Sliders'],
        'max_media' => ['value' => '40', 'label' => 'Archivos multimedia'],
        'max_storage_mb' => ['value' => '500', 'label' => 'Almacenamiento (MB)'],
        'max_menus' => ['value' => '5', 'label' => 'Menús de navegación'],
        'max_menu_items' => ['value' => '7', 'label' => 'Items por menú'],
        'max_api_tokens' => ['value' => '5', 'label' => 'API Tokens'],
        'modules_vertical' => ['value' => 'false', 'label' => 'Módulos verticales'],
        'custom_copyright' => ['value' => 'false', 'label' => 'Copyright editable'],
        'brand_personalization' => ['value' => 'false', 'label' => 'Nombre del proyecto en Studio'],
    ];

    /**
     * Límites y características del plan Auspicio / Convenio (slug: sponsorship).
     * Mismas bases que Free pero con recursos ampliados para proyectos con convenio institucional o patrocinio.
     */
    private const array SPONSORSHIP_FEATURES = [
        'max_users' => ['value' => '3', 'label' => 'Usuarios'],
        'max_pages' => ['value' => '30', 'label' => 'Páginas y contenidos'],
        'max_posts' => ['value' => '20', 'label' => 'Entradas de blog'],
        'max_services' => ['value' => '20', 'label' => 'Servicios'],
        'max_testimonials' => ['value' => '20', 'label' => 'Testimonios / Casos de éxito'],
        'max_sliders' => ['value' => '5', 'label' => 'Sliders'],
        'max_media' => ['value' => '60', 'label' => 'Archivos multimedia'],
        'max_storage_mb' => ['value' => '1000', 'label' => 'Almacenamiento (MB)'],
        'max_menus' => ['value' => '5', 'label' => 'Menús de navegación'],
        'max_menu_items' => ['value' => '12', 'label' => 'Items por menú'],
        'max_api_tokens' => ['value' => '10', 'label' => 'API Tokens'],
        'modules_vertical' => ['value' => 'false', 'label' => 'Módulos verticales'],
        'custom_copyright' => ['value' => 'acotado', 'label' => 'Copyright personalizado (Powered by Stamless)'],
        'brand_personalization' => ['value' => 'true', 'label' => 'Nombre del proyecto en Studio'],
    ];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->seedPlan(
            slug: 'free',
            attributes: [
                'name' => 'Free',
                'description' => 'Plan Free Forever: core del CMS sin costo, con límites básicos.',
                'price_monthly' => 0,
                'price_yearly' => 0,
                'currency' => 'USD',
                'is_active' => true,
                'is_free' => true,
                'sort_order' => 0,
                'max_users' => 1,
                'max_pages' => 20,
                'max_posts' => 10,
                'max_storage_mb' => 500,
            ],
            features: self::FREE_FEATURES
        );

        $this->seedPlan(
            slug: 'sponsorship',
            attributes: [
                'name' => 'Auspicio',
                'description' => 'Plan Auspicio / Convenio: core del CMS para convenios B2B y proyectos patrocinados, con límites ampliados.',
                'price_monthly' => 0,
                'price_yearly' => 0,
                'currency' => 'USD',
                'is_active' => true,
                'is_free' => true,
                'sort_order' => 1,
                'max_users' => 3,
                'max_pages' => 30,
                'max_posts' => 20,
                'max_storage_mb' => 1000,
            ],
            features: self::SPONSORSHIP_FEATURES
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
