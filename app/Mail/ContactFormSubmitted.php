<?php

namespace App\Mail;

use App\Mail\Concerns\UsesTenantSmtp;
use App\Models\Contact;
use App\Models\Form;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notificación al `notification_email` del `Form` cuando llega un nuevo
 * `Contact`. No contiene lógica de negocio ni de cifrado: recibe el
 * payload dinámico ya descifrado por `ContactSubmissionService` para que
 * este Mailable se mantenga "limpio" y trivialmente testeable/serializable.
 *
 * Plantilla compartida por todos los tenants (`emails.contacts.form-submitted`
 * + `emails.layouts.branded`, ver ADR de 2026-09-18) — `$logoUrl`/`$siteName`
 * son lo único que varía por tenant, resuelto en
 * `ContactSubmissionService::resolveBrandLogoUrl()`, nunca hardcodeado acá.
 *
 * 2026-09-18 (pedido del Tech Lead: "el envio a correo tiene que ser
 * usando queue tambien") — `ShouldQueue`: `Mail::to()->send()` lo detecta
 * solo y despacha a la cola en vez de mandar sincrónico (comportamiento
 * nativo de `Illuminate\Mail\Mailer::send()`), sin que
 * `ContactSubmissionService` necesite cambiar a `->queue()` explícito.
 */
class ContactFormSubmitted extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesTenantSmtp;

    /**
     * @param  array<string, mixed>  $decryptedFields  Payload dinámico del formulario, ya descifrado, listo para mostrar en el email.
     */
    public function __construct(
        public readonly Form $form,
        public readonly Contact $contact,
        public readonly array $decryptedFields = [],
        public readonly ?string $logoUrl = null,
        public readonly string $siteName = '',
    ) {}

    /**
     * SMTP propio del tenant (ver `App\Mail\Concerns\UsesTenantSmtp`) —
     * `$this->form->tenant` se resuelve fresco acá, aunque este Mailable
     * venga de deserializar un job de cola (las relaciones no sobreviven
     * `SerializesModels`, se vuelven a cargar solas al accederlas).
     */
    public function tenantForSmtp(): ?Tenant
    {
        return $this->form->tenant;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->form->notification_subject ?: "Nuevo contacto: {$this->form->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contacts.form-submitted',
            with: [
                'formName' => $this->form->name,
                'notificationIntro' => $this->form->notification_intro,
                'contactName' => $this->contact->name,
                'contactEmail' => $this->contact->email,
                'contactPhone' => $this->contact->phone,
                'contactCompany' => $this->contact->company,
                'fields' => $this->decryptedFields,
                'logoUrl' => $this->logoUrl,
                'siteName' => $this->siteName ?: config('app.name'),
            ],
        );
    }

    /**
     * Falla de un job YA en cola — distinto del `try/catch` sincrónico en
     * `ContactSubmissionService::notifyAdmin()`, que solo cubre el
     * despacho a la cola, no el envío real (que ahora ocurre después, en
     * el worker). Mismo criterio de siempre: nunca debe perderse en el
     * aire, solo quedar en logs.
     */
    public function failed(Throwable $exception): void
    {
        Log::warning('Envío en cola de ContactFormSubmitted falló.', [
            'form_id' => $this->form->id,
            'contact_id' => $this->contact->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
