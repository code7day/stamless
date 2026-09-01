<?php

namespace Tests\Feature;

use App\Enums\FormFieldTypeEnum;
use App\Mail\ContactFormSubmitted;
use App\Models\Contact;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Tenant;
use App\Services\ContactSubmissionService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\TestCase;

class ContactSubmissionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeForm(Tenant $tenant, string $slug = 'contacto'): Form
    {
        $form = Form::create([
            'tenant_id' => $tenant->id,
            'name' => 'Contacto',
            'slug' => $slug,
            'notification_email' => 'admin@example.test',
        ]);

        $fields = [
            ['name' => 'name', 'label' => 'Nombre', 'type' => FormFieldTypeEnum::Text, 'is_required' => true, 'is_encrypted' => false],
            ['name' => 'email', 'label' => 'Email', 'type' => FormFieldTypeEnum::Email, 'is_required' => true, 'is_encrypted' => true],
            ['name' => 'phone', 'label' => 'Teléfono', 'type' => FormFieldTypeEnum::Tel, 'is_required' => false, 'is_encrypted' => true],
            ['name' => 'message', 'label' => 'Mensaje', 'type' => FormFieldTypeEnum::Textarea, 'is_required' => true, 'is_encrypted' => false],
            ['name' => 'secret_note', 'label' => 'Nota interna', 'type' => FormFieldTypeEnum::Text, 'is_required' => false, 'is_encrypted' => true],
        ];

        foreach ($fields as $sortOrder => $field) {
            FormField::create(array_merge($field, [
                'form_id' => $form->id,
                'sort_order' => $sortOrder,
            ]));
        }

        return $form;
    }

    public function test_it_encrypts_core_and_marked_fields_and_builds_data_payload(): void
    {
        Mail::fake();

        $tenant = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $form = $this->makeForm($tenant);

        $contact = app(ContactSubmissionService::class)->submit($form, [
            'name' => 'Juan Pérez',
            'email' => 'juan@example.com',
            'phone' => '099123456',
            'message' => 'Quiero más info',
            'secret_note' => 'dato sensible',
        ]);

        // Legible vía Eloquent: el cast `encrypted` descifra automáticamente.
        $this->assertEquals('juan@example.com', $contact->email);
        $this->assertEquals('099123456', $contact->phone);
        $this->assertEquals('Juan Pérez', $contact->name);

        // Cifrado a nivel de fila cruda (bypass del cast de Eloquent).
        $rawEmail = DB::table('contacts')->where('id', $contact->id)->value('email');
        $this->assertNotEquals('juan@example.com', $rawEmail);

        // El payload dinámico guarda "message" en claro y "secret_note" cifrado.
        $rawData = json_decode(DB::table('contacts')->where('id', $contact->id)->value('data'), true);
        $this->assertEquals('Quiero más info', $rawData['message']);
        $this->assertNotEquals('dato sensible', $rawData['secret_note']);

        // decryptData() descifra selectivamente solo lo marcado is_encrypted.
        $decrypted = app(ContactSubmissionService::class)->decryptData($contact);
        $this->assertEquals('dato sensible', $decrypted['secret_note']);
        $this->assertEquals('Quiero más info', $decrypted['message']);

        Mail::assertSent(ContactFormSubmitted::class, fn (ContactFormSubmitted $mail) => $mail->contact->is($contact)
            && $mail->decryptedFields['secret_note'] === 'dato sensible'
        );
    }

    public function test_it_throws_when_a_required_field_is_missing(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $form = $this->makeForm($tenant);

        $this->expectException(InvalidArgumentException::class);

        app(ContactSubmissionService::class)->submit($form, [
            'name' => 'Juan Pérez',
            // faltan 'email' y 'message', ambos requeridos
        ]);
    }

    public function test_contacts_are_isolated_by_tenant(): void
    {
        Mail::fake();

        /** @var TenantManager $tenantManager */
        $tenantManager = app(TenantManager::class);

        $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b']);

        $formA = $this->makeForm($tenantA, 'contacto-a');
        $formB = $this->makeForm($tenantB, 'contacto-b');

        $service = app(ContactSubmissionService::class);

        $service->submit($formA, [
            'name' => 'Contacto A', 'email' => 'a@example.com', 'message' => 'hola',
        ]);
        $service->submit($formB, [
            'name' => 'Contacto B', 'email' => 'b@example.com', 'message' => 'hola',
        ]);

        $tenantManager->setTenant(null);
        $this->assertCount(2, Contact::all());

        $tenantManager->setTenant($tenantA);
        $this->assertCount(1, Contact::all());
        $this->assertEquals('Contacto A', Contact::first()->name);

        $tenantManager->setTenant($tenantB);
        $this->assertCount(1, Contact::all());
        $this->assertEquals('Contacto B', Contact::first()->name);
    }
}
