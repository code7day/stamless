<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Plataformas soportadas por el sub-bloque `social_links` del bloque
 * `colophon` (2026-09-02, pedido del Tech Lead: "links de redes sociales
 * con ícono predeterminado"). El `value` de cada caso es también la clave
 * que usa el frontend (cica360) para elegir el SVG de marca correspondiente
 * — Heroicons no trae logos de marca, así que el ícono real vive del lado
 * del frontend, no acá; este enum solo fija el catálogo cerrado de
 * plataformas válidas y su label en Studio.
 */
enum SocialPlatformEnum: string implements HasLabel
{
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case Linkedin = 'linkedin';
    case X = 'x';
    case Youtube = 'youtube';
    case Tiktok = 'tiktok';
    case Whatsapp = 'whatsapp';

    public function getLabel(): string
    {
        return match ($this) {
            self::Facebook => 'Facebook',
            self::Instagram => 'Instagram',
            self::Linkedin => 'LinkedIn',
            self::X => 'X (Twitter)',
            self::Youtube => 'YouTube',
            self::Tiktok => 'TikTok',
            self::Whatsapp => 'WhatsApp',
        };
    }
}
