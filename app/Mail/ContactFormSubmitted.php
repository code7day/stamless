<?php

namespace App\Mail;

use App\Models\Contact;
use App\Models\Form;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Notificación al `notification_email` del `Form` cuando llega un nuevo
 * `Contact`. No contiene lógica de negocio ni de cifrado: recibe el
 * payload dinámico ya descifrado por `ContactSubmissionService` para que
 * este Mailable se mantenga "limpio" y trivialmente testeable/serializable
 * si más adelante se despacha por queue (`use Illuminate\Contracts\Queue\ShouldQueue`).
 */
class ContactFormSubmitted extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $decryptedFields  Payload dinámico del formulario, ya descifrado, listo para mostrar en el email.
     */
    public function __construct(
        public readonly Form $form,
        public readonly Contact $contact,
        public readonly array $decryptedFields = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->form->notification_subject ?: "Nuevo contacto: {$this->form->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.contacts.form-submitted',
            with: [
                'formName' => $this->form->name,
                'contactName' => $this->contact->name,
                'contactEmail' => $this->contact->email,
                'contactPhone' => $this->contact->phone,
                'contactCompany' => $this->contact->company,
                'fields' => $this->decryptedFields,
            ],
        );
    }
}
