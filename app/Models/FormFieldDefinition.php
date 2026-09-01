<?php

namespace App\Models;

use App\Enums\FormFieldTypeEnum;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catálogo global (no tenant-aware) de campos reutilizables entre
 * formularios de todos los tenants (email, teléfono, empresa, ...).
 */
#[Fillable([
    'uuid', 'key', 'label', 'type', 'is_system', 'default_required',
    'default_encrypted', 'options', 'validation_rules', 'sort_order',
])]
class FormFieldDefinition extends Model
{
    use HasUuid;

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'type' => FormFieldTypeEnum::class,
            'is_system' => 'boolean',
            'default_required' => 'boolean',
            'default_encrypted' => 'boolean',
            'options' => 'array',
            'validation_rules' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function formFields(): HasMany
    {
        return $this->hasMany(FormField::class, 'field_definition_id');
    }
}
