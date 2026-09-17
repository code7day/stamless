<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiTokenPlatformEnum;
use App\Enums\FormFieldTypeEnum;
use App\Models\Contact;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `POST /forms/{slug}/submit` — envelope de error (ADR-009/ADR-024) para
 * el caso de validación de dominio (campos requeridos del `Form`, que son
 * dinámicos por tenant, no un rule set estático de Laravel).
 */
class FormSubmissionApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'is_active' => true]);
    }

    private function actingAsTenant(Tenant $tenant): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test-'.uniqid().'@example.com',
            'password' => 'password',
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($user, ['forms:submit']);
    }

    /**
     * 2026-09-12 (ADR-059): a diferencia de `actingAsTenant()` de arriba
     * (`Sanctum::actingAs()`, que crea un `Laravel\Sanctum\TransientToken`
     * SIN fila real en `personal_access_tokens`), estos tests necesitan un
     * `PersonalAccessToken` persistido de verdad — `ValidateTokenOrigin`
     * explícitamente ignora cualquier cosa que no sea
     * `instanceof PersonalAccessToken` (ver esa clase), así que un
     * `TransientToken` nunca ejercitaría la validación de origen. Devuelve
     * el plain-text token para mandarlo en `Authorization: Bearer`.
     */
    private function createRealToken(
        Tenant $tenant,
        array $abilities = ['forms:submit'],
        ?ApiTokenPlatformEnum $platform = null,
        ?string $allowedOrigin = null,
    ): string {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test-'.uniqid().'@example.com',
            'password' => 'password',
            'tenant_id' => $tenant->id,
        ]);

        $newToken = $user->createToken('test-token', $abilities);

        $newToken->accessToken->forceFill([
            'platform' => $platform?->value,
            'allowed_origin' => $allowedOrigin,
        ])->save();

        return $newToken->plainTextToken;
    }

    private function makeForm(Tenant $tenant): Form
    {
        $form = Form::create([
            'tenant_id' => $tenant->id,
            'name' => 'Contacto',
            'slug' => 'contacto',
        ]);

        foreach ([
            ['name' => 'name', 'label' => 'Nombre', 'type' => FormFieldTypeEnum::Text, 'is_required' => true],
            ['name' => 'email', 'label' => 'Email', 'type' => FormFieldTypeEnum::Email, 'is_required' => true],
            ['name' => 'message', 'label' => 'Mensaje', 'type' => FormFieldTypeEnum::Textarea, 'is_required' => true],
        ] as $sortOrder => $field) {
            FormField::create(array_merge($field, [
                'form_id' => $form->id,
                'is_encrypted' => false,
                'sort_order' => $sortOrder,
            ]));
        }

        return $form;
    }

    public function test_submitting_an_empty_body_returns_422_with_field_errors(): void
    {
        $tenant = $this->makeTenant('tenant-a');
        $this->makeForm($tenant);
        $this->actingAsTenant($tenant);

        $response = $this->postJson('/v1/tenant-a/forms/contacto/submit', []);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'status_code' => 422]);
        $response->assertJsonPath('errors.code', 'validation');
        $response->assertJsonPath('errors.fields.name.0', 'El campo name es obligatorio.');
        $response->assertJsonPath('errors.fields.email.0', 'El campo email es obligatorio.');
        $response->assertJsonPath('errors.fields.message.0', 'El campo message es obligatorio.');
    }

    public function test_submitting_with_all_required_fields_succeeds(): void
    {
        $tenant = $this->makeTenant('tenant-a');
        $this->makeForm($tenant);
        $this->actingAsTenant($tenant);

        $response = $this->postJson('/v1/tenant-a/forms/contacto/submit', [
            'name' => 'Juan Pérez',
            'email' => 'juan@example.com',
            'message' => 'Hola, quiero más info.',
        ]);

        $response->assertStatus(201);
        $response->assertJson(['success' => true, 'status_code' => 201]);
    }

    /**
     * 2026-09-12 (pedido del Tech Lead: "añadimos validaciones y seguridad
     * en el post del api para el submit del formulario ... quiero evitar
     * XSS, injection") — ver `ContactSubmissionService::assertFieldsAreValid()`.
     */
    public function test_submitting_an_invalid_email_returns_422_with_field_errors(): void
    {
        $tenant = $this->makeTenant('tenant-a');
        $this->makeForm($tenant);
        $this->actingAsTenant($tenant);

        $response = $this->postJson('/v1/tenant-a/forms/contacto/submit', [
            'name' => 'Juan Perez',
            'email' => 'no-es-un-correo',
            'message' => 'Hola, quiero más info.',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.code', 'validation');
        $response->assertJsonStructure(['errors' => ['fields' => ['email']]]);
    }

    public function test_submitting_a_name_with_html_markup_is_rejected(): void
    {
        $tenant = $this->makeTenant('tenant-a');
        $this->makeForm($tenant);
        $this->actingAsTenant($tenant);

        $response = $this->postJson('/v1/tenant-a/forms/contacto/submit', [
            'name' => '<script>alert(1)</script>',
            'email' => 'juan@example.com',
            'message' => 'Hola',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.code', 'validation');
        $response->assertJsonStructure(['errors' => ['fields' => ['name']]]);
    }

    public function test_a_file_type_field_rejects_any_submitted_value(): void
    {
        $tenant = $this->makeTenant('tenant-a');
        $form = $this->makeForm($tenant);

        FormField::create([
            'form_id' => $form->id,
            'name' => 'attachment',
            'label' => 'Adjunto',
            'type' => FormFieldTypeEnum::File,
            'is_required' => false,
            'is_encrypted' => false,
            'sort_order' => 99,
        ]);

        $this->actingAsTenant($tenant);

        $response = $this->postJson('/v1/tenant-a/forms/contacto/submit', [
            'name' => 'Juan Perez',
            'email' => 'juan@example.com',
            'message' => 'Hola',
            'attachment' => 'data:text/plain;base64,SGVsbG8=',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.code', 'validation');
        $response->assertJsonStructure(['errors' => ['fields' => ['attachment']]]);
    }

    public function test_submitting_a_select_value_outside_its_own_options_is_rejected(): void
    {
        $tenant = $this->makeTenant('tenant-a');
        $form = $this->makeForm($tenant);

        FormField::create([
            'form_id' => $form->id,
            'name' => 'country',
            'label' => 'País',
            'type' => FormFieldTypeEnum::Select,
            'is_required' => true,
            'is_encrypted' => false,
            'options' => [['value' => 'AR', 'label' => 'Argentina']],
            'sort_order' => 98,
        ]);

        $this->actingAsTenant($tenant);

        $response = $this->postJson('/v1/tenant-a/forms/contacto/submit', [
            'name' => 'Juan Perez',
            'email' => 'juan@example.com',
            'message' => 'Hola',
            'country' => 'ZZ',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.code', 'validation');
        $response->assertJsonStructure(['errors' => ['fields' => ['country']]]);
    }

    /**
     * 2026-09-12 (2da vuelta, pedido del Tech Lead sobre el campo
     * WhatsApp): el frontend arma `phone` concatenando código de país +
     * número local, ej. "+51987654321" — sin espacios/guiones/paréntesis.
     * Ver `ContactSubmissionService::rulesForField()`.
     */
    public function test_a_phone_value_must_be_an_optional_plus_followed_only_by_digits(): void
    {
        $tenant = $this->makeTenant('tenant-a');
        $form = $this->makeForm($tenant);

        FormField::create([
            'form_id' => $form->id,
            'name' => 'phone',
            'label' => 'WhatsApp',
            'type' => FormFieldTypeEnum::Tel,
            'is_required' => false,
            'is_encrypted' => true,
            'sort_order' => 97,
        ]);

        $this->actingAsTenant($tenant);

        $invalid = $this->postJson('/v1/tenant-a/forms/contacto/submit', [
            'name' => 'Juan Perez',
            'email' => 'juan@example.com',
            'message' => 'Hola',
            'phone' => '+51 987-654 321',
        ]);

        $invalid->assertStatus(422);
        $invalid->assertJsonPath('errors.code', 'validation');
        $invalid->assertJsonStructure(['errors' => ['fields' => ['phone']]]);

        $valid = $this->postJson('/v1/tenant-a/forms/contacto/submit', [
            'name' => 'Juan Perez',
            'email' => 'juan@example.com',
            'message' => 'Hola',
            'phone' => '+51987654321',
        ]);

        $valid->assertStatus(201);
    }

    /**
     * 2026-09-12 (3ra vuelta, pedido del Tech Lead sobre "Consulta"): "nada
     * de html, solo texto, signos de puntuacion o cualquier otro pero solo
     * texto plano". `NoHtmlTags` (aplicada a TODO campo Textarea desde la
     * 1ra vuelta) solo bloquea tags bien formados (`strip_tags`) — no
     * caracteres sueltos de código como `{ } \` ~ ^`. Por eso el campo
     * `message` de este test lleva su propia `validation_rules` (mismo
     * allow-list que siembra `Cliente0ContentSeeder::upsertContactForm()`),
     * que sí los rechaza.
     */
    public function test_a_message_field_with_its_own_plain_text_validation_rules_rejects_code_like_characters(): void
    {
        $tenant = $this->makeTenant('tenant-a');
        $form = $this->makeForm($tenant);

        FormField::where('form_id', $form->id)->where('name', 'message')->update([
            'validation_rules' => ['regex:/^[\p{L}\p{N}\s.,;:!?\'"()\-_¿¡%\/@#&*+=$°]*$/u'],
        ]);

        $this->actingAsTenant($tenant);

        $invalid = $this->postJson('/v1/tenant-a/forms/contacto/submit', [
            'name' => 'Juan Perez',
            'email' => 'juan@example.com',
            'message' => 'Hola {payload} `rm -rf` ~ ^',
        ]);

        $invalid->assertStatus(422);
        $invalid->assertJsonPath('errors.code', 'validation');
        $invalid->assertJsonStructure(['errors' => ['fields' => ['message']]]);

        $valid = $this->postJson('/v1/tenant-a/forms/contacto/submit', [
            'name' => 'Juan Perez',
            'email' => 'juan@example.com',
            'message' => '¡Hola! Quisiera más info, por favor: precios y horarios (9-18h).',
        ]);

        $valid->assertStatus(201);
    }

    /**
     * 2026-09-12 (ADR-004): CICA360 pega contra este endpoint vía un proxy
     * PHP server-to-server (`contacto.php`), que ahora reenvía la IP real
     * del visitante en `X-Forwarded-For` (ver ese archivo, mismo día) — acá
     * se confirma que `FormSubmissionController::resolveClientIp()` la
     * prioriza sobre `$request->ip()` (que en este test sería `127.0.0.1`,
     * la IP del propio test client).
     */
    public function test_ip_address_is_taken_from_x_forwarded_for_when_present(): void
    {
        $tenant = $this->makeTenant('tenant-a');
        $this->makeForm($tenant);
        $this->actingAsTenant($tenant);

        $this->postJson('/v1/tenant-a/forms/contacto/submit', [
            'name' => 'Juan Perez',
            'email' => 'juan@example.com',
            'message' => 'Hola',
        ], [
            'X-Forwarded-For' => '203.0.113.7, 10.0.0.1',
        ])->assertStatus(201);

        $this->assertDatabaseHas('contacts', [
            'tenant_id' => $tenant->id,
            'ip_address' => '203.0.113.7',
        ]);
    }

    /**
     * 2026-09-12: "cabe la posibilidad abierta de que el cliente cambie de
     * pais en el formulario pero siempre el api debe recibir el IP y
     * country_code de origen, por favor asegurarse eso". Este test declara
     * un campo `country` de NEGOCIO en el form (lo que el visitante elige a
     * mano) con un valor, y verifica que `geo_country_code` (derivado del
     * header `X-Origin-Country`, resuelto por IP del lado del proxy) se
     * guarda aparte, sin relación con lo que el visitante haya elegido.
     */
    public function test_geo_country_code_from_header_is_stored_independently_of_the_declared_country_field(): void
    {
        $tenant = $this->makeTenant('tenant-a');
        $form = $this->makeForm($tenant);

        FormField::create([
            'form_id' => $form->id,
            'name' => 'country',
            'label' => 'País',
            'type' => FormFieldTypeEnum::Select,
            'is_required' => false,
            'is_encrypted' => false,
            'options' => [['value' => 'AR', 'label' => 'Argentina'], ['value' => 'PE', 'label' => 'Perú']],
            'sort_order' => 96,
        ]);

        $this->actingAsTenant($tenant);

        // El visitante declara "Argentina" a mano, pero la IP de origen
        // (según el header que resuelve el proxy) es de Perú.
        $this->postJson('/v1/tenant-a/forms/contacto/submit', [
            'name' => 'Juan Perez',
            'email' => 'juan@example.com',
            'message' => 'Hola',
            'country' => 'AR',
        ], [
            'X-Origin-Country' => 'pe',
        ])->assertStatus(201);

        $this->assertDatabaseHas('contacts', [
            'tenant_id' => $tenant->id,
            'data->country' => 'AR',
            'data->geo_country_code' => 'PE',
        ]);
    }

    /**
     * Sin el header (tenant que no implementa el proxy de geolocalización,
     * o pega directo al API) el submit sigue funcionando normal — este
     * dato es enteramente opcional, nunca requerido.
     */
    public function test_submission_succeeds_without_x_origin_country_header(): void
    {
        $tenant = $this->makeTenant('tenant-a');
        $this->makeForm($tenant);
        $this->actingAsTenant($tenant);

        $response = $this->postJson('/v1/tenant-a/forms/contacto/submit', [
            'name' => 'Juan Perez',
            'email' => 'juan@example.com',
            'message' => 'Hola',
        ]);

        $response->assertStatus(201);

        $contact = Contact::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertArrayNotHasKey('geo_country_code', $contact->data ?? []);
    }

    /**
     * 2026-09-12, pedido del Tech Lead: "validar que el formulario solo
     * reciba de un dominio de la app (website cliente) que fue configurada
     * al crear un token, por seguridad" — corregido a un diseño POR TOKEN
     * (`platform`/`allowed_origin`), no por tenant: "me refiero a stamless,
     * la proteccion es para stamless, no para el sitio web... por eso
     * tambien un select si es una web o es app". Ver ADR-059 y
     * `App\Http\Middleware\ValidateTokenOrigin`.
     *
     * Un token sin `platform` seteado (el default, y el de TODOS los
     * tokens creados antes de esta feature) no tiene ninguna restricción —
     * comportamiento idéntico a antes de que existiera esta feature
     * ("no requeridos de lado de stamless, sera opcion de cada cliente si
     * desea usar").
     */
    public function test_submission_succeeds_when_token_has_no_platform_set(): void
    {
        $tenant = $this->makeTenant('tenant-a');
        $this->makeForm($tenant);
        $token = $this->createRealToken($tenant);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/tenant-a/forms/contacto/submit', [
                'name' => 'Juan Perez',
                'email' => 'juan@example.com',
                'message' => 'Hola',
            ], [
                'Origin' => 'https://un-sitio-cualquiera.com',
            ]);

        $response->assertStatus(201);
    }

    /**
     * `platform = 'app'` — "si es app mobile ya no se valida porque es
     * diferente la comunicacion" — NUNCA se valida el origen, ni siquiera
     * en modo estricto, sin importar qué `allowed_origin` tenga seteado
     * (no debería tener ninguno, pero si lo tuviera igual da lo mismo).
     */
    public function test_app_platform_token_is_never_validated_regardless_of_origin(): void
    {
        config(['stamless.security.strict_origin_check' => true]);

        $tenant = $this->makeTenant('tenant-a');
        $this->makeForm($tenant);
        $token = $this->createRealToken($tenant, platform: ApiTokenPlatformEnum::App);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/tenant-a/forms/contacto/submit', [
                'name' => 'Juan Perez',
                'email' => 'juan@example.com',
                'message' => 'Hola',
            ], [
                'Origin' => 'https://cualquier-cosa.com',
            ]);

        $response->assertStatus(201);
    }

    /**
     * Con `strict_origin_check` activo (equivalente a producción), un
     * token `platform = 'web'` con `allowed_origin` seteado rechaza con
     * 403 un `Origin` que no matchea.
     */
    public function test_submission_is_rejected_when_web_token_origin_mismatches_in_strict_mode(): void
    {
        config(['stamless.security.strict_origin_check' => true]);

        $tenant = $this->makeTenant('tenant-a');
        $this->makeForm($tenant);
        $token = $this->createRealToken($tenant, platform: ApiTokenPlatformEnum::Web, allowedOrigin: 'cica360.com');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/tenant-a/forms/contacto/submit', [
                'name' => 'Juan Perez',
                'email' => 'juan@example.com',
                'message' => 'Hola',
            ], [
                'Origin' => 'https://un-sitio-distinto.com',
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('errors.code', 'origin_not_allowed');
    }

    /**
     * Mismo modo estricto, pero el `Origin` SÍ matchea el `allowed_origin`
     * del token — el submit funciona normal.
     */
    public function test_submission_succeeds_when_web_token_origin_matches_in_strict_mode(): void
    {
        config(['stamless.security.strict_origin_check' => true]);

        $tenant = $this->makeTenant('tenant-a');
        $this->makeForm($tenant);
        $token = $this->createRealToken($tenant, platform: ApiTokenPlatformEnum::Web, allowedOrigin: 'cica360.com');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/tenant-a/forms/contacto/submit', [
                'name' => 'Juan Perez',
                'email' => 'juan@example.com',
                'message' => 'Hola',
            ], [
                'Origin' => 'https://cica360.com',
            ]);

        $response->assertStatus(201);
    }

    /**
     * Modo estricto + token `web` con `allowed_origin`, pero SIN ningún
     * header de origen (`Origin`/`Referer`/`X-Forwarded-Host`) — se
     * rechaza: en producción no hay excepción para "sin header", a
     * diferencia del modo no estricto (ver siguiente test).
     */
    public function test_submission_is_rejected_without_any_origin_header_for_web_token_in_strict_mode(): void
    {
        config(['stamless.security.strict_origin_check' => true]);

        $tenant = $this->makeTenant('tenant-a');
        $this->makeForm($tenant);
        $token = $this->createRealToken($tenant, platform: ApiTokenPlatformEnum::Web, allowedOrigin: 'cica360.com');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/tenant-a/forms/contacto/submit', [
                'name' => 'Juan Perez',
                'email' => 'juan@example.com',
                'message' => 'Hola',
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('errors.code', 'origin_not_allowed');
    }

    /**
     * Fuera de modo estricto ("permitir al desarrollador que use el api
     * desde cualquier lado" — pedido explícito), un token `web` con
     * `allowed_origin` seteado NO bloquea la ausencia de header de origen
     * ni `localhost`.
     */
    public function test_submission_succeeds_from_localhost_or_without_origin_for_web_token_when_not_strict(): void
    {
        config(['stamless.security.strict_origin_check' => false]);

        $tenant = $this->makeTenant('tenant-a');
        $this->makeForm($tenant);
        $token = $this->createRealToken($tenant, platform: ApiTokenPlatformEnum::Web, allowedOrigin: 'cica360.com');

        $withoutHeader = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/tenant-a/forms/contacto/submit', [
                'name' => 'Juan Perez',
                'email' => 'juan@example.com',
                'message' => 'Hola',
            ]);
        $withoutHeader->assertStatus(201);

        $fromLocalhost = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/tenant-a/forms/contacto/submit', [
                'name' => 'Juan Perez',
                'email' => 'juan@example.com',
                'message' => 'Hola',
            ], [
                'Origin' => 'http://localhost:4321',
            ]);
        $fromLocalhost->assertStatus(201);
    }

    /**
     * Aun fuera de modo estricto, un `Origin` que SÍ declara un host
     * concreto (no localhost) pero que no matchea el `allowed_origin` del
     * token se sigue rechazando — el bypass es específico para
     * "herramientas de desarrollo local", no una desactivación total del
     * chequeo.
     */
    public function test_submission_is_rejected_from_a_mismatched_real_domain_for_web_token_even_when_not_strict(): void
    {
        config(['stamless.security.strict_origin_check' => false]);

        $tenant = $this->makeTenant('tenant-a');
        $this->makeForm($tenant);
        $token = $this->createRealToken($tenant, platform: ApiTokenPlatformEnum::Web, allowedOrigin: 'cica360.com');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/tenant-a/forms/contacto/submit', [
                'name' => 'Juan Perez',
                'email' => 'juan@example.com',
                'message' => 'Hola',
            ], [
                'Origin' => 'https://un-sitio-distinto.com',
            ]);

        $response->assertStatus(403);
    }

    /**
     * El proxy server-to-server (`contacto.php`) no tiene un `Origin` de
     * navegador real que reenviar — declara el suyo propio en
     * `X-Forwarded-Host` (ver ese archivo). Se confirma que
     * `ValidateTokenOrigin::resolveClaimedOriginHost()` también acepta esa
     * fuente.
     */
    public function test_submission_succeeds_via_x_forwarded_host_matching_web_token_allowed_origin(): void
    {
        config(['stamless.security.strict_origin_check' => true]);

        $tenant = $this->makeTenant('tenant-a');
        $this->makeForm($tenant);
        $token = $this->createRealToken($tenant, platform: ApiTokenPlatformEnum::Web, allowedOrigin: 'cica360.com');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/tenant-a/forms/contacto/submit', [
                'name' => 'Juan Perez',
                'email' => 'juan@example.com',
                'message' => 'Hola',
            ], [
                'X-Forwarded-Host' => 'cica360.com',
            ]);

        $response->assertStatus(201);
    }

    public function test_get_form_returns_form_definition_with_fields_and_thank_you_template(): void
    {
        $tenant = $this->makeTenant('tenant-a');
        $this->makeForm($tenant);

        $user = User::create([
            'name' => 'Test User',
            'email' => 'test-'.uniqid().'@example.com',
            'password' => 'password',
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($user, ['content:read']);

        $response = $this->getJson('/v1/tenant-a/forms/contacto');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'uuid',
                'slug',
                'name',
                'fields' => [
                    '*' => ['uuid', 'name', 'label', 'type', 'is_required', 'sort_order'],
                ],
                'thank_you' => [
                    'title',
                    'description',
                    'alert_title',
                    'alert_description',
                    'button_label',
                ],
            ],
        ]);
        $response->assertJsonPath('data.slug', 'contacto');
        $response->assertJsonPath('data.thank_you.title', '¡Muchas gracias, {name}!');
    }

    public function test_submission_returns_personalized_thank_you_template(): void
    {
        $tenant = $this->makeTenant('tenant-a');
        $this->makeForm($tenant);
        $this->actingAsTenant($tenant);

        $response = $this->postJson('/v1/tenant-a/forms/contacto/submit', [
            'name' => 'Eduardo Perez Silva',
            'email' => 'eduardo@example.com',
            'message' => 'Hola, consulta.',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'uuid',
                'thank_you' => [
                    'title',
                    'description',
                    'alert_title',
                    'alert_description',
                    'button_label',
                ],
            ],
        ]);
        $response->assertJsonPath('data.thank_you.title', '¡Muchas gracias, Eduardo!');
    }
}
