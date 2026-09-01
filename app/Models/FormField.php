<?php

namespace App\Models;

use App\Enums\FormFieldTypeEnum;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuración concreta de un campo dentro de un `Form` (sin `tenant_id`
 * propio: se resuelve vía `form.tenant_id`). No confundir con los valores
 * enviados por el visitante, que se guardan en `Contact::data`.
 */
#[Fillable([
    'uuid', 'form_id', 'field_definition_id', 'label', 'type', 'name',
    'placeholder', 'help_text', 'is_required', 'is_encrypted', 'options',
    'validation_rules', 'sort_order', 'is_active',
])]
class FormField extends Model
{
    use HasUuid;

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'type' => FormFieldTypeEnum::class,
            'is_required' => 'boolean',
            'is_encrypted' => 'boolean',
            'options' => 'array',
            'validation_rules' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function fieldDefinition(): BelongsTo
    {
        return $this->belongsTo(FormFieldDefinition::class, 'field_definition_id');
    }
}
