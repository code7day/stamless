<?php

namespace Database\Seeders;

use App\Enums\ModuleEnum;
use App\Enums\ModuleTypeEnum;
use App\Models\Module;
use App\Models\Plan;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    /**
     * Módulos core del CMS gestionados mediante ModuleEnum.
     */
    private const array CORE_MODULES = [
        ModuleEnum::Pages,
        ModuleEnum::Posts,
        ModuleEnum::Media,
        ModuleEnum::Menus,
        ModuleEnum::Settings,
        ModuleEnum::Sliders,
        ModuleEnum::Contacts,
    ];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $moduleIds = [];

        foreach (self::CORE_MODULES as $moduleEnum) {
            $module = Module::updateOrCreate(
                ['slug' => $moduleEnum->value],
                [
                    'name' => $moduleEnum->getLabel(),
                    'type' => ModuleTypeEnum::Utility->value,
                    'is_core' => true,
                    'is_active' => true,
                    'version' => '1.0.0',
                ]
            );

            $moduleIds[] = $module->id;
        }

        $plans = Plan::all();

        foreach ($plans as $plan) {
            $plan->modules()->syncWithoutDetaching($moduleIds);
        }
    }
}
