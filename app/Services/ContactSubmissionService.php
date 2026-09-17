<?php

namespace App\Services;

use App\Enums\ContactActivityTypeEnum;
use App\Enums\ContactStatusEnum;
use App\Enums\FormFieldTypeEnum;
use App\Exceptions\Api\InvalidFieldFormatException;
use App\Exceptions\Api\MissingRequiredFieldsException;
use App\Mail\ContactFormSubmitted;
use App\Models\Contact;
use App\Models\ContactActivity;
use App\Models\Form;
use App\Models\FormField;
use App\Rules\NoHtmlTags;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Procesa el envío de un `Form` y persiste el `Contact` resultante.
 *
 * Reglas de cifrado:
 * - `name`/`email`/`phone`/`company`, si el `Form` los define como campos,
 *   se mapean a las columnas dedicadas de `Contact`. `email`/`phone`/
 *   `company` ya cifran solas vía el cast `encrypted` de Eloquent.
 * - Cualquier otro campo del formulario va al payload `Contact::data`
 *   (jsonb). Si el `FormField::is_encrypted` correspondiente es `true`,
 *   el valor se cifra individualmente (`Crypt::encryptString`) antes de
 *   guardarse ahí; si es `false`, se guarda en texto plano.
 *
 * No hace validación HTTP (Form Request) porque todavía no existe el
 * endpoint público — sí valida presencia de campos requeridos a nivel de
 * dominio, para que cualquier entry point futuro (API, Filament action)
 * reciba el mismo comportamiento.
 *
 * 2026-09-12 (pedido del Tech Lead: "añadimos validaciones y seguridad en
 * el post del api para el submit del formulario ... quiero evitar XSS,
 * injection, uploads y cualquier forma de acceder al stamless"): además de
 * presencia (`assertRequiredFieldsPresent()`), ahora también se valida
 * FORMATO (`assertFieldsAreValid()`) — hasta acá `FormField::validation_rules`
 * existía en el esquema desde el inicio del proyecto pero nunca se leía en
 * ningún lado (columna muerta, mismo patrón de "declarado pero no
 * aplicado" ya visto con `plans.max_pages`, ver ADR-054). Reglas base por
 * `FormFieldTypeEnum` (email real, teléfono con caracteres esperados,
 * opciones de un `select` limitadas a su propio catálogo, campo tipo
 * Archivo explícitamente PROHIBIDO — ver `rulesForField()`) + reglas
 * adicionales específicas por campo/tenant vía `FormField::validation_rules`
 * (así completa CICA360 sus reglas de "nombre"/"ciudad": solo letras +
 * un espacio entre palabras, 3–40 caracteres — ver
 * `Cliente0ContentSeeder::upsertContactForm()`, NO hardcodeado acá por
 * nombre de campo, para no romper el criterio "formularios 100% dinámicos"
 * del resto de este servicio). Todo campo de texto además pasa por
 * `NoHtmlTags` (rechaza cualquier valor con markup — defensa en
 * profundidad contra XSS almacenado, ver ese Rule para el detalle).
 */
class ContactSubmissionService
{
    /**
     * Nombres de campo que se mapean a columnas propias de `Contact` en
     * vez de ir al payload `data`.
     *
     * @var list<string>
     */
    private const array CORE_CONTACT_FIELDS = ['name', 'email', 'phone', 'company'];

    /**
     * @param  array<string, mixed>  $payload  Datos crudos enviados por el visitante, indexados por `FormField::name`.
     * @param  array<string, mixed>  $meta  Metadata de la request: source, page_url, ip_address, user_agent, geo_country_code.
     */
    public function submit(Form $form, array $payload, array $meta = []): Contact
    {
        $fields = $form->fields()->where('is_active', true)->get();

        $this->assertRequiredFieldsPresent($fields, $payload);
        $this->assertFieldsAreValid($fields, $payload);

        [$coreAttributes, $dynamicData] = $this->splitPayload($fields, $payload);

        // 2026-09-12: "siempre el api debe recibir el IP y country_code de
        // origen" — geolocalización DERIVADA de la IP (ver
        // `FormSubmissionController::resolveOriginCountry()`), nada que ver
        // con un eventual campo `country` de negocio que el `Form` defina
        // (el que el visitante puede cambiar libremente a mano). Va bajo
        // una clave FIJA en el jsonb `data`, nunca como `FormField`
        // dinámico — no es un dato que el visitante ingresa, así que
        // forzarlo por el pipeline de formularios (`FormFieldDefinition`/
        // `validation_rules`) sería forzar ese modelo para algo que no es
        // un campo del formulario. Completamente OPCIONAL: si el tenant no
        // manda este meta (su proxy no lo implementa, o pega directo al
        // API sin pasar por uno), simplemente no se agrega la clave — no
        // hay ninguna validación ni requisito asociado a esto.
        if (! empty($meta['geo_country_code'])) {
            $dynamicData['geo_country_code'] = $meta['geo_country_code'];
        }

        // Extra fallback para core fields si vinieron en el payload directamente
        $coreAttributes['name'] ??= $payload['name'] ?? $payload['nombre'] ?? null;
        $coreAttributes['email'] ??= $payload['email'] ?? $payload['correo'] ?? $payload['correo_electronico'] ?? null;
        $coreAttributes['phone'] ??= $payload['phone'] ?? $payload['telefono'] ?? $payload['whatsapp'] ?? null;
        $coreAttributes['company'] ??= $payload['company'] ?? $payload['empresa'] ?? null;

        $source = $meta['source'] ?? $payload['source'] ?? $payload['origen'] ?? $meta['page_url'] ?? null;

        $contact = Contact::create(array_merge($coreAttributes, [
            'tenant_id' => $form->tenant_id,
            'form_id' => $form->id,
            'data' => $dynamicData,
            'status' => ContactStatusEnum::New,
            'source' => $source,
            'page_url' => $meta['page_url'] ?? null,
            'ip_address' => $meta['ip_address'] ?? null,
            'user_agent' => $meta['user_agent'] ?? null,
        ]));

        ContactActivity::create([
            'contact_id' => $contact->id,
            'type' => ContactActivityTypeEnum::FormSubmitted,
            'description' => sprintf('Formulario "%s" enviado.', $form->name),
        ]);

        $this->notify($form, $contact);

        return $contact;
    }

    /**
     * Descifra selectivamente el payload dinámico de un `Contact`, usando
     * `FormField::is_encrypted` (por nombre) como fuente de verdad de qué
     * claves están cifradas. Los campos que ya no existen en la definición
     * actual del form se devuelven tal cual (no se asume cifrado).
     *
     * Para listados, cargar `form.fields` con eager loading antes de
     * iterar varios contactos, para evitar N+1.
     *
     * @return array<string, mixed>
     */
    public function decryptData(Contact $contact): array
    {
        $data = $contact->data ?? [];

        if (! $contact->form_id || $data === []) {
            return $data;
        }

        $encryptedFieldNames = $contact->form
            ?->fields()
            ->where('is_encrypted', true)
            ->pluck('name')
            ->all() ?? [];

        $decrypted = [];

        foreach ($data as $key => $value) {
            if (! in_array($key, $encryptedFieldNames, true) || ! is_string($value)) {
                $decrypted[$key] = $value;

                continue;
            }

            try {
                $decrypted[$key] = Crypt::decryptString($value);
            } catch (DecryptException) {
                $decrypted[$key] = null;
            }
        }

        return $decrypted;
    }

    /**
     * @param  Collection<int, FormField>  $fields
     * @param  array<string, mixed>  $payload
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function splitPayload(Collection $fields, array $payload): array
    {
        $coreAttributes = [];
        $dynamicData = [];

        foreach ($fields as $field) {
            if (! array_key_exists($field->name, $payload)) {
                continue;
            }

            $value = $payload[$field->name];

            $coreKey = match ($field->name) {
                'name', 'nombre', 'full_name' => 'name',
                'email', 'correo', 'correo_electronico' => 'email',
                'phone', 'telefono', 'whatsapp', 'celular', 'movil' => 'phone',
                'company', 'empresa', 'organizacion', 'negocio' => 'company',
                default => in_array($field->name, self::CORE_CONTACT_FIELDS, true) ? $field->name : null,
            };

            if ($coreKey !== null) {
                $coreAttributes[$coreKey] = $value;

                continue;
            }

            $dynamicData[$field->name] = $this->prepareDynamicValue($field, $value);
        }

        return [$coreAttributes, $dynamicData];
    }

    /**
     * Cifra el valor si `FormField::is_encrypted` es `true`. Valores no
     * escalares (p. ej. checkboxes múltiples) se serializan a JSON antes
     * de cifrarse; el llamador debe hacer `json_decode` si lo necesita
     * estructurado tras `decryptData()`.
     */
    private function prepareDynamicValue(FormField $field, mixed $value): mixed
    {
        if (! $field->is_encrypted || $value === null || $value === '') {
            return $value;
        }

        $serialized = is_scalar($value) ? (string) $value : json_encode($value);

        return Crypt::encryptString($serialized);
    }

    /**
     * @param  Collection<int, FormField>  $fields
     * @param  array<string, mixed>  $payload
     */
    private function assertRequiredFieldsPresent(Collection $fields, array $payload): void
    {
        $missing = $fields
            ->where('is_required', true)
            ->reject(fn (FormField $field) => filled($payload[$field->name] ?? null))
            ->pluck('name');

        if ($missing->isNotEmpty()) {
            throw new MissingRequiredFieldsException($missing->all());
        }
    }

    /**
     * Valida FORMATO (no presencia, ya cubierta por
     * `assertRequiredFieldsPresent()`) de cada campo presente en el
     * payload, usando `Illuminate\Support\Facades\Validator` con reglas
     * armadas dinámicamente por `rulesForField()`. Un solo `Validator` para
     * TODOS los campos del form (no uno por campo) — así el envelope de
     * error trae el desglose completo de una sola pasada, no solo el
     * primer campo inválido.
     *
     * @param  Collection<int, FormField>  $fields
     * @param  array<string, mixed>  $payload
     */
    private function assertFieldsAreValid(Collection $fields, array $payload): void
    {
        $rules = [];
        $attributes = [];

        foreach ($fields as $field) {
            $rules[$field->name] = $this->rulesForField($field);
            $attributes[$field->name] = $field->label;
        }

        if ($rules === []) {
            return;
        }

        $validator = Validator::make($payload, $rules, [], $attributes);

        if ($validator->fails()) {
            throw new InvalidFieldFormatException($validator->errors()->toArray());
        }
    }

    /**
     * Reglas de formato para UN `FormField`, en 3 capas (de más genérica a
     * más específica, todas se combinan):
     *   1. `nullable` — la presencia/obligatoriedad ya se resolvió en
     *      `assertRequiredFieldsPresent()`, acá solo importa el formato SI
     *      hay un valor.
     *   2. Reglas base según `FormFieldTypeEnum` — comunes a cualquier
     *      tenant/form que use ese tipo de campo (email real, teléfono con
     *      caracteres esperados, opciones de un `select` limitadas a su
     *      propio `options`, tipo Archivo explícitamente prohibido).
     *   3. `FormField::validation_rules` (columna ya existente en el
     *      esquema, sin uso hasta esta fecha) — reglas ADICIONALES
     *      específicas de este campo puntual en este form puntual (ej. el
     *      "solo letras + un espacio entre palabras, 3–40 caracteres" que
     *      pidió el Tech Lead para "nombre"/"ciudad" de CICA360, sembrado
     *      en `Cliente0ContentSeeder::upsertContactForm()` — a propósito
     *      NO hardcodeado acá por nombre de campo).
     *
     * @return array<int, mixed>
     */
    private function rulesForField(FormField $field): array
    {
        if ($field->type === FormFieldTypeEnum::File) {
            // Este endpoint solo acepta JSON (sin multipart/form-data) —
            // "uploads" vía formulario de contacto NO está implementado
            // (ver hallazgo de seguridad 2026-09-12): cualquier valor en un
            // campo tipo Archivo se rechaza explícito, sin importar qué
            // intente mandar el cliente (evita, por ejemplo, que alguien
            // intente colar un payload gigante o un path/URL sospechoso
            // disfrazado de "archivo").
            return ['prohibited'];
        }

        $rules = ['nullable'];

        $rules = array_merge($rules, match ($field->type) {
            FormFieldTypeEnum::Email => ['email:rfc,filter', 'max:255'],
            // 2026-09-12 (2da vuelta, pedido del Tech Lead sobre el campo
            // WhatsApp): el frontend arma el valor final concatenando
            // "+{código de país}{número local}" (ej. "+51987654321") ANTES
            // de enviarlo — nunca llegan espacios/guiones/paréntesis acá
            // (eso era solo formato visual del lado cliente, ver
            // `cica360/src/components/islands/ContactForm.tsx`). El
            // pattern se endurece para calzar: "+" opcional seguido SOLO
            // de dígitos.
            FormFieldTypeEnum::Tel => ['regex:/^\+?[0-9]{6,20}$/'],
            FormFieldTypeEnum::Number => ['numeric'],
            FormFieldTypeEnum::Date => ['date'],
            FormFieldTypeEnum::Select, FormFieldTypeEnum::Radio => $this->inRuleForOptions($field),
            FormFieldTypeEnum::Checkbox => [],
            FormFieldTypeEnum::Textarea => ['max:2000'],
            default => ['max:255'], // Text/Hidden genérico
        });

        if (in_array($field->type, [FormFieldTypeEnum::Text, FormFieldTypeEnum::Textarea, FormFieldTypeEnum::Email, FormFieldTypeEnum::Tel, FormFieldTypeEnum::Hidden], true)) {
            $rules[] = new NoHtmlTags;
        }

        if (! empty($field->validation_rules)) {
            $rules = array_merge($rules, $field->validation_rules);
        }

        return $rules;
    }

    /**
     * Restringe un `select`/`radio` a los valores REALES de su propio
     * `FormField::options` — sin esto, cualquier string pasaba como
     * "país"/"área de interés" válidos, sin relación con el catálogo que
     * el propio `<select>` ofrece. Tolera 2 shapes de `options` (lista de
     * `{value,label}` — el shape real sembrado hoy — o lista de escalares
     * sueltos) para no atarse a un único formato.
     *
     * @return array<int, string>
     */
    private function inRuleForOptions(FormField $field): array
    {
        if (empty($field->options)) {
            return [];
        }

        $values = collect($field->options)
            ->map(fn (mixed $option): mixed => is_array($option) ? ($option['value'] ?? null) : $option)
            ->filter(fn (mixed $value): bool => $value !== null)
            ->map(fn (mixed $value): string => (string) $value);

        if ($values->isEmpty()) {
            return [];
        }

        return ['in:'.$values->implode(',')];
    }

    /**
     * Best-effort: un fallo de correo nunca debe perder el contacto ya
     * guardado, solo se registra en logs.
     */
    private function notify(Form $form, Contact $contact): void
    {
        if (! $form->notification_email) {
            return;
        }

        try {
            Mail::to($form->notification_email)->send(
                new ContactFormSubmitted($form, $contact, $this->decryptData($contact))
            );
        } catch (Throwable $e) {
            Log::warning('No se pudo enviar la notificación de nuevo contacto.', [
                'form_id' => $form->id,
                'contact_id' => $contact->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
