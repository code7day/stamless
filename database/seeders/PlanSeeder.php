<?php

namespace Database\Seeders;

use App\Enums\LanguageEnum;
use App\Models\Plan;
use App\Models\PlanFeature;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Límites del plan Free, reutilizados tanto en las columnas propias de
     * `Plan` (chequeos rápidos sin join) como en `plan_features` (listado
     * de features para UI/comparativas, con label y `lang_iso`).
     */
    private const array FREE_FEATURES = [
        'max_users' => ['value' => '1', 'label' => 'Usuarios'],
        'max_pages' => ['value' => '20', 'label' => 'Páginas'],
        'max_posts' => ['value' => '50', 'label' => 'Entradas de blog'],
        'max_storage_mb' => ['value' => '500', 'label' => 'Almacenamiento (MB)'],
        'modules_vertical' => ['value' => 'false', 'label' => 'Módulos verticales'],
    ];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $plan = Plan::updateOrCreate(
            ['slug' => 'free'],
            [
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
                'max_posts' => 50,
                'max_storage_mb' => 500,
            ]
        );

        foreach (self::FREE_FEATURES as $key => $feature) {
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
