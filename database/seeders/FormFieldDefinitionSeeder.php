<?php

namespace Database\Seeders;

use App\Enums\FormFieldTypeEnum;
use App\Models\FormFieldDefinition;
use Illuminate\Database\Seeder;

class FormFieldDefinitionSeeder extends Seeder
{
    /**
     * 2026-09-18 (3ra vuelta del builder de formularios, pedido del Tech
     * Lead: "considerar las validaciones predeterminadas por ser un
     * componente campo del catálogo, entonces que ya tenga internamente
     * sus validates... para ahorrar pasos al usuario") — antes esta
     * columna existía en el schema pero NUNCA se poblaba (ver docblock de
     * `DEFINITIONS` más abajo, "el catálogo global no tiene opinión sobre
     * esos valores"): las regex de `name`/`city`/`email`/`message` vivían
     * ÚNICAMENTE como overrides puntuales de CICA360 en
     * `Cliente0ContentSeeder::upsertContactForm()`. Son genéricas de
     * verdad (nombre = solo letras, email = TLD válido, mensaje = sin
     * HTML/markup) — no específicas de CICA360 — así que se promueven acá
     * a default del catálogo GLOBAL; cualquier tenant que elija estos
     * campos del picker de `FormResource` las trae precargadas (y
     * editables/borrables por instancia, `FormField::validation_rules`
     * sigue pisando esto si el form real lo necesita distinto).
     */
    private const string LETTER_PATTERN = 'regex:/^\p{L}+(?: \p{L}+)*$/u';

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
            'validation_rules' => ['min:3', 'max:40', self::LETTER_PATTERN],
        ],
        [
            'key' => 'email',
            'label' => 'Email',
            'type' => FormFieldTypeEnum::Email,
            'required' => true,
            'encrypted' => true,
            'validation_rules' => ['regex:/^[\w.+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,24}$/'],
        ],
        // 2026-09-18 (ADR forms por tenant) — antes `Tel` plano: el selector
        // de país + detección por IP de CICA360 (`ContactForm.tsx`) era un
        // caso hardcodeado en un solo frontend, no algo que otro tenant
        // pudiera elegir. Pasa a `TelCountry` para que CUALQUIER tenant
        // pueda optar por esa UX desde el builder de formularios (Fase 1,
        // `FormResource`) sin tocar código — ver docblock del case en
        // `FormFieldTypeEnum`. Re-sembrar este seeder actualiza el `type`
        // de la `FormFieldDefinition` global; los `FormField` YA creados
        // (ej. el "WhatsApp" de CICA360) toman el nuevo tipo recién cuando
        // `Cliente0ContentSeeder::upsertContactForm()` se vuelve a correr
        // (copia `$definition->type->value` en cada `updateOrCreate`).
        [
            'key' => 'phone',
            'label' => 'Teléfono',
            'type' => FormFieldTypeEnum::TelCountry,
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
        // 2026-09-11 (pedido del Tech Lead, con captura de mockup real del
        // form de "Contactame": Nombre y Apellido/Correo/Ciudad/WhatsApp/
        // País/Área de interés/Consulta): 3 campos nuevos, reusables por
        // cualquier form de cualquier tenant (mismo criterio que el resto
        // de este catálogo global). `city` es texto libre; `country`/
        // `area_of_interest` son `select` — este catálogo GLOBAL solo fija
        // el tipo de campo, sin `options` propias (`FormFieldDefinition`
        // no las persiste hoy, ver `run()`: nunca se le pasa `options` al
        // `updateOrCreate`); las opciones concretas (países de CICA360, el
        // catálogo real de servicios) viven por-`FormField`, específicas de
        // CADA form — ver `Cliente0ContentSeeder::upsertContactForm()`.
        [
            'key' => 'city',
            'label' => 'Ciudad',
            'type' => FormFieldTypeEnum::Text,
            'required' => true,
            'encrypted' => false,
            'validation_rules' => ['min:3', 'max:40', self::LETTER_PATTERN],
        ],
        [
            'key' => 'country',
            'label' => 'País',
            'type' => FormFieldTypeEnum::Select,
            'required' => true,
            'encrypted' => false,
        ],
        [
            'key' => 'area_of_interest',
            'label' => 'Área de interés',
            'type' => FormFieldTypeEnum::Select,
            'required' => true,
            'encrypted' => false,
        ],
        [
            'key' => 'message',
            'label' => 'Mensaje',
            'type' => FormFieldTypeEnum::Textarea,
            'required' => true,
            'encrypted' => false,
            // Allow-list de caracteres (letras/dígitos/espacios/puntuación
            // normal en español), sin `< > { } [ ] \ \` ~ ^ |` — mismo
            // criterio "sin HTML/markup" que ya probó CICA360 en producción.
            'validation_rules' => ['regex:/^[\p{L}\p{N}\s.,;:!?\'"()\-_¿¡%\/@#&*+=$°]*$/u'],
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
                    'validation_rules' => $definition['validation_rules'] ?? null,
                    'sort_order' => $sortOrder,
                ]
            );
        }
    }
}
