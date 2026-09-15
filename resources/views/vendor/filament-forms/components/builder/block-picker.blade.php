{{--
    Override de `vendor/filament/forms/resources/views/components/builder/block-picker.blade.php`
    (Filament 5, paquete `filament-forms`, namespace de vistas `filament-forms`).

    2026-09-05, pedido del Tech Lead: con ~9-11 tipos de bloque en el picker
    de "Añadir bloque" (`PageResource.php`), cuando el botón está cerca del
    borde inferior del modal, el dropdown (`x-filament::dropdown`) flipea
    hacia arriba (`x-float` con `flip`, ya viene activado por default en el
    componente compartido) pero el panel NO tenía ningún `max-height` — el
    vendor original no le pasa `maxHeight` ni `size` a `<x-filament::dropdown>`
    acá. Sin eso, el panel completo se posiciona arriba del trigger sin
    límite de alto, y si es más alto que el espacio disponible, las
    primeras opciones de la lista (arriba del todo) quedan clippeadas por
    el borde del modal — invisibles y sin scroll para llegar a ellas.

    Fix: se agrega `:max-height="'400px'"` al `<x-filament::dropdown>` de
    abajo. Esa prop es de primera clase en el componente compartido
    (`filament-support::components.dropdown.index`, ver `@props`) — al
    setearla, el propio Filament agrega la clase `fi-scrollable` (CSS core
    ya compilado en el vendor, NO una clase Tailwind arbitraria de este
    proyecto — no requiere tocar el `@source` del theme custom del panel,
    a diferencia del fix de `MenuTreeBuilder`) + un `style="max-height:
    400px"` inline sobre el panel. Con eso el panel se autolimita a 400px
    y gana scroll interno propio, sin importar hacia qué lado haya
    flipeado — se puede llegar tanto a la primera como a la última opción
    siempre.

    Se eligió esta opción (override de un único Blade, reusando la
    infraestructura `x-float`/`fi-scrollable` que Filament YA trae) en vez
    de duplicar el botón "Añadir bloque" arriba y abajo de la lista de
    secciones: `Builder` (Filament\Forms\Components\Builder) no tiene un
    método fluido para esto (`blockPickerColumns()`/`blockPickerWidth()`
    solo tocan columnas/ancho, no alto/scroll — confirmado leyendo la clase
    completa), así que cualquier fix requiere tocar Blade de una forma u
    otra; duplicar el trigger hubiera significado forkear más superficie
    (el layout completo del picker) para terminar sin resolver la causa
    raíz (el panel seguiría sin alto máximo), mientras que este cambio es
    una sola prop nueva sobre el componente compartido que Filament ya
    expone para este propósito exacto.

    El resto del archivo es IDÉNTICO al original del vendor — si Filament
    cambia `block-picker.blade.php` en una actualización futura, hay que
    re-diffear este override contra la nueva versión del vendor y
    reaplicar solo el `:max-height` de abajo.
--}}
@php
    use Filament\Support\Enums\Alignment;
    use Filament\Support\Enums\GridDirection;
    use Filament\Support\View\ComponentAttributeBag as FilamentComponentAttributeBag;
@endphp

@props([
    'action',
    'actionAlignment' => null,
    'afterItem' => null,
    'blocks',
    'columns' => null,
    'key',
    'trigger',
    'width' => null,
])

<x-filament::dropdown
    :placement="
        match ($actionAlignment) {
            Alignment::Start, Alignment::Left => 'bottom-start',
            Alignment::End, Alignment::Right => 'bottom-end',
            default => null,
        }
    "
    shift
    :width="$width"
    :max-height="'400px'"
    :attributes="
        \Filament\Support\prepare_inherited_attributes(
            $attributes->class([
                'fi-fo-builder-block-picker',
                ($actionAlignment instanceof Alignment) ? ('fi-align-' . $actionAlignment->value) : $actionAlignment,
            ]),
        )
    "
>
    <x-slot name="trigger">
        {{ $trigger }}
    </x-slot>

    <x-filament::dropdown.list>
        <div
            {{ (new FilamentComponentAttributeBag)->grid($columns, GridDirection::Column) }}
        >
            @foreach ($blocks as $block)
                @php
                    $blockIcon = $block->getIcon();

                    $wireClickActionArguments = ['block' => $block->getName()];

                    if (filled($afterItem)) {
                        $wireClickActionArguments['afterItem'] = $afterItem;
                    }

                    $wireClickActionArguments = \Illuminate\Support\Js::from($wireClickActionArguments);

                    $wireClickAction = "mountAction('{$action->getName()}', {$wireClickActionArguments}, { schemaComponent: '{$key}' })";
                @endphp

                <x-filament::dropdown.list.item
                    :icon="$blockIcon"
                    x-on:click="close"
                    :wire:click="$wireClickAction"
                >
                    {{ $block->getLabel() }}
                </x-filament::dropdown.list.item>
            @endforeach
        </div>
    </x-filament::dropdown.list>
</x-filament::dropdown>
