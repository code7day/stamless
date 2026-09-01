<?php

namespace App\Services;

use App\Enums\ContactActivityTypeEnum;
use App\Enums\ContactStatusEnum;
use App\Exceptions\Api\MissingRequiredFieldsException;
use App\Mail\ContactFormSubmitted;
use App\Models\Contact;
use App\Models\ContactActivity;
use App\Models\Form;
use App\Models\FormField;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
     * @param  array<string, mixed>  $meta  Metadata de la request: source, page_url, ip_address, user_agent.
     */
    public function submit(Form $form, array $payload, array $meta = []): Contact
    {
        $fields = $form->fields()->where('is_active', true)->get();

        $this->assertRequiredFieldsPresent($fields, $payload);

        [$coreAttributes, $dynamicData] = $this->splitPayload($fields, $payload);

        $contact = Contact::create(array_merge($coreAttributes, [
            'tenant_id' => $form->tenant_id,
            'form_id' => $form->id,
            'data' => $dynamicData,
            'status' => ContactStatusEnum::New,
            'source' => $meta['source'] ?? null,
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

            if (in_array($field->name, self::CORE_CONTACT_FIELDS, true)) {
                $coreAttributes[$field->name] = $value;

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
