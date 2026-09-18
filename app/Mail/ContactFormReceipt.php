<?php

namespace App\Mail;

use App\Mail\Concerns\UsesTenantSmtp;
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
 * "Copia al usuario"/constancia — se manda a quien completó el formulario
 * cuando `Form::send_copy_to_submitter` está activo (ver
 * `ContactSubmissionService::notifySubmitter()`). Columna/toggle existían
 * en Filament desde el MVP pero nunca se leían en ningún lado (hallazgo
 * 2026-09-18) — este Mailable es la implementación real.
 *
 * Reusa el mismo contenido de la "Página de Agradecimiento" que el
 * visitante ya ve en pantalla tras enviar (`$thankYou`, resuelto por
 * `ContactSubmissionService::resolveThankYouData()`) en vez de tener
 * textos propios duplicados — un solo lugar por `Form` para personalizar
 * "qué le decimos a quien nos escribió", ya sea en pantalla o por email.
 *
 * Misma plantilla compartida por todos los tenants
 * (`emails.contacts.form-receipt` + `emails.layouts.branded`) y mismo
 * criterio de `ShouldQueue` que `ContactFormSubmitted` — ver su docblock.
 */
class ContactFormReceipt extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesTenantSmtp;

    /**
     * @param  array{title: string, description: ?string, alert_title: ?string, alert_description: ?string, button_label: ?string}  $thankYou
     */
    public function __construct(
        public readonly Form $form,
        public readonly array $thankYou,
        public readonly ?string $logoUrl = null,
        public readonly string $siteName = '',
    ) {}

    /**
     * SMTP propio del tenant (ver `App\Mail\Concerns\UsesTenantSmtp`).
     */
    public function tenantForSmtp(): ?Tenant
    {
        return $this->form->tenant;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->thankYou['title'] ?: "Recibimos tu consulta — {$this->form->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contacts.form-receipt',
            with: [
                'form' => $this->form,
                'thankYou' => $this->thankYou,
                'logoUrl' => $this->logoUrl,
                'siteName' => $this->siteName ?: config('app.name'),
            ],
        );
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('Envío en cola de ContactFormReceipt falló.', [
            'form_id' => $this->form->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
