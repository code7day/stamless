<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * `properties.feature_style` del bloque `features` (2026-09-07, pedido
 * explícito del Tech Lead — "en las propiedades especificas para este tipo
 * de bloque adicionar tambien que estilo de feature quiere"): cómo se
 * presenta CADA item del grid — desde texto plano sin ningún tratamiento de
 * tarjeta hasta la tarjeta blanca con sombra que trae el diseño de
 * referencia (Misión/Visión/Valores, captura del Tech Lead 2026-09-06/07).
 *
 * `Simple`/`Shapes` no llevan fondo de tarjeta propio (heredan el fondo de
 * la SECCIÓN, `properties.background_*`) — pensados para blocks donde el
 * fondo de sección ya aporta el contraste necesario. `BoxedNoShadow`/
 * `BoxedShadow` sí envuelven cada item en su propia tarjeta blanca (color
 * fijo, no configurable todavía — mismo criterio que otras superficies de
 * tarjeta del sitio, p. ej. testimonials) con el borde redondeado que
 * controla la property hermana `properties.card_rounded`.
 */
enum FeatureCardStyleEnum: string implements HasLabel
{
    case Simple = 'simple';
    case Shapes = 'shapes';
    case BoxedNoShadow = 'boxed_no_shadow';
    case BoxedShadow = 'boxed_shadow';

    public function getLabel(): string
    {
        return match ($this) {
            self::Simple => 'Simple (sin tarjeta)',
            self::Shapes => 'Con formas decorativas',
            self::BoxedNoShadow => 'Tarjeta sin sombra',
            self::BoxedShadow => 'Tarjeta con sombra',
        };
    }
}
