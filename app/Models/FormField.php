<?php

namespace App\Models;

use App\Enums\FormFieldTypeEnum;
use App\Observers\DeployTriggerObserver;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
     * Fase 6 (post-MVP) adelantada, 2026-09-18 — mismo caso que `Block`:
     * editar un campo desde el Repeater de `Form` no necesariamente deja
     * "dirty" nada del `Form` padre, así que el trigger tiene que vivir acá
     * también. `DeployTriggerObserver::queueDeploy()` lee `tenant_id` con
     * `getAttribute()`, que SÍ pasa por el accessor `tenantId()` de abajo
     * aunque no exista una columna `tenant_id` en esta tabla — por eso
     * alcanza con el accessor, sin tocar el observer genérico.
     */
    protected static function booted(): void
    {
        static::observe(DeployTriggerObserver::class);
    }

    /**
     * Accessor virtual (no hay columna `tenant_id` en `form_fields`) para
     * que `DeployTriggerObserver` pueda resolver el tenant sin necesitar
     * lógica especial — ver docblock de `booted()`.
     */
    protected function tenantId(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->form?->tenant_id,
        );
    }

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
