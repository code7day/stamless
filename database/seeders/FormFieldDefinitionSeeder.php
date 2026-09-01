<?php

namespace Database\Seeders;

use App\Enums\FormFieldTypeEnum;
use App\Models\FormFieldDefinition;
use Illuminate\Database\Seeder;

class FormFieldDefinitionSeeder extends Seeder
{
    /**
     * Catálogo global de campos reutilizables por cualquier `Form` de
     * cualquier tenant (ver `App\Models\FormFieldDefinition`).
     */
    private const array DEFINITIONS = [
        [
            'key' => 'name',
            'label' => 'Nombre',
            'type' => FormFieldTypeEnum::Text,
            'required' => true,
            'encrypted' => false,
        ],
        [
            'key' => 'email',
            'label' => 'Email',
            'type' => FormFieldTypeEnum::Email,
            'required' => true,
            'encrypted' => true,
        ],
        [
            'key' => 'phone',
            'label' => 'Teléfono',
            'type' => FormFieldTypeEnum::Tel,
            'required' => false,
            'encrypted' => true,
        ],
        [
            'key' => 'company',
            'label' => 'Empresa',
            'type' => FormFieldTypeEnum::Text,
            'required' => false,
            'encrypted' => false,
        ],
        [
            'key' => 'subject',
            'label' => 'Asunto',
            'type' => FormFieldTypeEnum::Text,
            'required' => false,
            'encrypted' => false,
        ],
        [
            'key' => 'message',
            'label' => 'Mensaje',
            'type' => FormFieldTypeEnum::Textarea,
            'required' => true,
            'encrypted' => false,
        ],
    ];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        foreach (self::DEFINITIONS as $sortOrder => $definition) {
            FormFieldDefinition::updateOrCreate(
                ['key' => $definition['key']],
                [
                    'label' => $definition['label'],
                    'type' => $definition['type']->value,
                    'is_system' => true,
                    'default_required' => $definition['required'],
                    'default_encrypted' => $definition['encrypted'],
                    'sort_order' => $sortOrder,
                ]
            );
        }
    }
}
