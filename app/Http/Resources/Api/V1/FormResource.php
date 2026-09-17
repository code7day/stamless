<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\Concerns\NormalizesJsonFields;
use App\Models\Form;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Form
 */
class FormResource extends JsonResource
{
    use NormalizesJsonFields;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $rawDescription = setting(
            'thank_you.description',
            'Hemos recibido tu consulta correctamente. Un asesor especializado de <strong>CICA360</strong> revisará tu información y se pondrá en contacto contigo a la brevedad.'
        );

        $renderedDescription = is_array($rawDescription)
            ? RichContentRenderer::make($rawDescription)->toHtml()
            : (string) $rawDescription;

        return [
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'success_message' => $this->success_message,
            'redirect_url' => $this->redirect_url,
            'fields' => $this->fields->map(fn ($field) => [
                'uuid' => $field->uuid,
                'name' => $field->name,
                'label' => $field->label,
                'type' => $field->type?->value ?? (string) $field->type,
                'placeholder' => $field->placeholder,
                'help_text' => $field->help_text,
                'is_required' => (bool) $field->is_required,
                'validation_rules' => $field->validation_rules,
                'options' => $field->options,
                'sort_order' => $field->sort_order,
            ])->values(),
            'thank_you' => [
                'title' => setting('thank_you.title', '¡Muchas gracias, {name}!'),
                'description' => $renderedDescription,
                'alert_title' => setting('thank_you.alert_title', 'Tiempo de respuesta estimado:'),
                'alert_description' => setting('thank_you.alert_description', 'Menos de 24 horas hábiles (Lunes a Viernes de 9:00 a 18:00).'),
                'button_label' => setting('thank_you.button_label', 'Enviar otra consulta'),
            ],
        ];
    }
}
