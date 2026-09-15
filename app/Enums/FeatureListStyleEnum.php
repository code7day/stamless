<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * `properties.list_style` del bloque `features` (2026-09-07 — reemplaza
 * `FeatureContentFormatEnum`, que vivía como selector POR ITEM bajo el
 * nombre confuso "Contenido adicional". El Tech Lead pidió 2 cambios en el
 * mismo pedido: mejor nombre ("Estilo, Formato, presentacion, algo asi") y
 * mover el campo a `properties` ("es mejor pasar a properties como parte
 * del estilo").
 *
 * Es un ÚNICO valor por BLOQUE (no por item): controla cómo se muestra
 * `content.items[].items` (los puntos sueltos, vía `TagsInput`) en
 * CUALQUIER item del grid que los tenga cargados — no hace falta un
 * selector por item porque el propio contenido ya decide implícitamente:
 * un item sin `items[]` cargado (p. ej. "Misión"/"Visión", que solo traen
 * `description`) simplemente no tiene nada que mostrar en ese formato, sin
 * importar el valor de esta property; un item CON `items[]` (p. ej.
 * "Valores") los muestra en el formato que indique acá.
 */
enum FeatureListStyleEnum: string implements HasLabel
{
    case None = 'none';
    case List = 'list';
    case Grid = 'grid';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => 'Ninguna (solo descripción)',
            self::List => 'Lista (viñetas)',
            self::Grid => 'Cuadrícula (grid)',
        };
    }
}
