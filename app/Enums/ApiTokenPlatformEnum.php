<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * 2026-09-12, pedido del Tech Lead: "un select si es una web o es app
 * desde donde se usará el api, si es app mobile ya no se valida porque es
 * diferente la comunicacion, pero desde desktop via web creo que si porque
 * el cliente estara alojado en un server identificado por un dominio".
 *
 * `Web` — el cliente (navegador o el server que lo aloja, ej. un proxy
 * PHP/Node server-side) está identificado por un dominio propio;
 * `App\Http\Middleware\ValidateTokenOrigin` exige que ese dominio matchee
 * `PersonalAccessToken::allowed_origin`.
 * `App` — apps mobile u otro consumidor sin un dominio propio al que
 * atarse (la comunicación es directa dispositivo↔API, sin concepto de
 * `Origin`/`Referer` significativo) — el middleware nunca valida nada
 * para tokens de esta plataforma.
 */
enum ApiTokenPlatformEnum: string implements HasLabel
{
    case Web = 'web';
    case App = 'app';

    public function getLabel(): string
    {
        return match ($this) {
            self::Web => 'Web (sitio o server con dominio propio)',
            self::App => 'App (mobile u otro cliente sin dominio)',
        };
    }
}
