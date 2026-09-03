<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Ícono opcional por ítem de enlace — 2026-09-02, pedido del Tech Lead
 * ("faltan iconos" en el sub-bloque `link_list` del bloque `colophon`,
 * columna "Contacto": correo con ícono de enviar, teléfono/WhatsApp con su
 * logo). Mismo criterio que `SocialPlatformEnum`: el `value` es la clave que
 * usa el frontend (cica360) para resolver el SVG real (Phosphor) — este enum
 * solo fija el catálogo cerrado de íconos válidos y su label en Studio.
 *
 * Deliberadamente NO se agrega a `LinkSchema::make()` por default (afectaría
 * TODOS los consumidores existentes de enlaces/botones CTA en la app, donde
 * un ícono no tiene sentido) — solo el `link_list` de `colophon` lo pide
 * (`LinkSchema::make(..., withIcon: true)`), ver `PageResource.php`.
 */
enum LinkIconEnum: string implements HasLabel
{
    case Email = 'email';
    case Phone = 'phone';
    case Whatsapp = 'whatsapp';
    case Location = 'location';
    case Link = 'link';

    public function getLabel(): string
    {
        return match ($this) {
            self::Email => 'Correo (enviar)',
            self::Phone => 'Teléfono',
            self::Whatsapp => 'WhatsApp',
            self::Location => 'Ubicación',
            self::Link => 'Enlace genérico',
        };
    }
}
