<?php

namespace Database\Seeders;

use App\Enums\ModuleTypeEnum;
use App\Models\Module;
use App\Models\Plan;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    /**
     * Módulos core del CMS. `contacts` se marca `is_core` igual que el
     * resto: el plan Free necesita poder recibir envíos de formulario
     * (`contact_form` block) sin depender de un módulo vertical de pago.
     */
    private const array CORE_MODULES = [
        'pages' => 'Páginas',
        'posts' => 'Blog / Entradas',
        'media' => 'Media Library',
        'menus' => 'Menús de navegación',
        'settings' => 'Configuración del sitio',
        'sliders' => 'Sliders',
        'contacts' => 'Contactos y formularios',
    ];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $plan = Plan::where('slug', 'free')->first();

        $moduleIds = [];

        foreach (self::CORE_MODULES as $slug => $name) {
            $module = Module::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'type' => ModuleTypeEnum::Utility->value,
                    'is_core' => true,
                    'is_active' => true,
                    'version' => '1.0.0',
                ]
            );

            $moduleIds[] = $module->id;
        }

        if ($plan) {
            $plan->modules()->syncWithoutDetaching($moduleIds);
        }
    }
}
