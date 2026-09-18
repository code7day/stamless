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
     * 2026-09-18 (ADR-074): "Página de Agradecimiento" TRASLADADA de
     * `Setting` tenant-wide (`thank_you.*`) a columnas de `Form`
     * (`thank_you_*`) — un valor tenant-wide no puede dar una página de
     * gracias distinta por formulario, y a partir de la Fase 1 (ADR-073) un
     * tenant puede tener varios `Form`. Los defaults hardcodeados de acá
     * SON genéricos a propósito (sin mencionar CICA360 ni ningún tenant en
     * particular) — el default anterior sí lo hacía, un problema latente
     * preexistente (cualquier tenant nuevo sin configurar aún vería copy de
     * CICA360) que no se reintroduce acá.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $rawDescription = $this->thank_you_description
            ?? 'Hemos recibido tu consulta correctamente. Nuestro equipo revisará tu información y se pondrá en contacto contigo a la brevedad.';

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
                'title' => $this->thank_you_title ?? '¡Muchas gracias, {name}!',
                'description' => $renderedDescription,
                'alert_title' => $this->thank_you_alert_title ?? 'Tiempo de respuesta estimado:',
                'alert_description' => $this->thank_you_alert_description ?? 'Menos de 24 horas hábiles.',
                'button_label' => $this->thank_you_button_label ?? 'Enviar otra consulta',
            ],
        ];
    }
}
