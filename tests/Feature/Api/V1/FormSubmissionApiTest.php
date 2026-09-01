<?php

namespace Tests\Feature\Api\V1;

use App\Enums\FormFieldTypeEnum;
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
}
