<?php

namespace App\Mail\Concerns;

use App\Models\Tenant;

/**
 * SMTP propio por tenant, opcional (2026-09-18, pedido del Tech Lead: "falta
 * la sección de configuración para ingresar los datos para configurar su
 * SMTP favorito y será mejor para evitar usar mi smtp general para todo").
 *
 * Cualquier Mailable que `use` este trait DEBE exponer un método público
 * `tenantForSmtp(): ?Tenant` (ver `ContactFormSubmitted`/`ContactFormReceipt`)
 * — así el trait no asume un nombre de propiedad fijo (`form`/`contact`/etc.)
 * y sirve para cualquier Mailable futuro sin acoplarse a este dominio.
 *
 * El "cómo" de esto es la parte no obvia: `prepareMailableForDelivery()` es
 * el único punto donde Laravel llama de vuelta al Mailable DESPUÉS de que
 * la cola lo deserializó y ANTES de resolver qué mailer usar (`Mailable::send()`
 * llama `prepareMailableForDelivery()` y RECIÉN DESPUÉS `$factory->mailer($this->mailer)`
 * — ver `vendor/laravel/framework/src/Illuminate/Mail/Mailable.php`). Esto
 * corre en cada intento real de envío, tanto síncrono como dentro del worker
 * de cola (`SendQueuedMailable::handle()` → `$this->mailable->send($factory)`)
 * — así que leer `$tenant->smtp_*` acá siempre trae el valor MÁS RECIENTE de
 * la base de datos al momento real del envío, nunca un valor congelado desde
 * que se encoló el job (las relaciones Eloquent no sobreviven la
 * serialización de `SerializesModels` — se recargan solas al accederlas).
 *
 * Registrar `config(['mail.mailers.<nombre>' => [...]])` en runtime, en vez
 * de un mailer fijo declarado en `config/mail.php`, es necesario porque no
 * hay forma de conocer de antemano las credenciales de CADA tenant — se arma
 * un mailer efímero, con nombre único por tenant, en el mismo proceso/tick
 * que lo va a usar.
 */
trait UsesTenantSmtp
{
    /**
     * @return \App\Models\Tenant|null Debe implementarse en la clase que usa este trait.
     */
    abstract public function tenantForSmtp(): ?Tenant;

    protected function prepareMailableForDelivery(): void
    {
        $this->applyTenantSmtpIfConfigured();

        parent::prepareMailableForDelivery();
    }

    private function applyTenantSmtpIfConfigured(): void
    {
        $tenant = $this->tenantForSmtp();

        if (! $tenant?->hasCustomSmtpConfigured()) {
            return;
        }

        $mailerName = 'tenant_smtp_'.$tenant->id;

        config(["mail.mailers.{$mailerName}" => $tenant->smtpMailerConfig()]);

        $this->mailer($mailerName);

        $from = $tenant->smtpFromAddress();

        if (filled($from['address'])) {
            $this->from($from['address'], $from['name']);
        }
    }
}
