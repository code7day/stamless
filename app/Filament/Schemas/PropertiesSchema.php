<?php

namespace App\Filament\Schemas;

use App\Enums\AlignContentEnum;
use App\Enums\BlendModeEnum;
use App\Enums\DecoratorShapeEnum;
use App\Enums\PositionContainerEnum;
use Filament\Forms;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;

class PropertiesSchema
{
    public static function make(array $fields = []): Group
    {
        return Group::make(self::makeComponents($fields));
    }

    public static function makeComponents(array $fields = []): array
    {
        $allFields = [
            'content_position' => Forms\Components\Select::make('properties.content_position')
                ->label('Posición del contenido')
                ->options([
                    'left-top' => 'Izquierda-Arriba',
                    'left-middle' => 'Izquierda-Medio',
                    'left-bottom' => 'Izquierda-Abajo',
                    'center-top' => 'Centro-Arriba',
                    'center-middle' => 'Centro-Medio',
                    'center-bottom' => 'Centro-Abajo',
                    'right-top' => 'Derecho-Arriba',
                    'right-middle' => 'Derecho-Medio',
                    'right-bottom' => 'Derecho-Abajo',
                ])
                ->default('left-middle'),
            'background_color' => Forms\Components\ColorPicker::make('properties.background_color')
                ->label('Color de fondo')
                ->helperText(fn (Get $get) => $get('properties.background_type') === 'gradient' ? 'Primer color del degradado.' : null)
                // 2026-09-02, pedido del Tech Lead: donde el bloque también
                // tiene una imagen de fondo propia (`background_type_image`,
                // ver más abajo), color e imagen pasan a ser EXCLUYENTES —
                // se oculta el color en cuanto se elige "Imagen" como tipo
                // de fondo. En bloques que usan la variante `background_type`
                // de 2 opciones (sin "Imagen" en la lista) esta condición
                // nunca se cumple, así que no cambia nada ahí.
                ->visible(fn (Get $get) => $get('properties.background_type') !== 'image'),
            // Fondo sólido vs. degradado (2026-09-02, pedido del Tech Lead:
            // "que cualquier sección donde se requiera tenga opción a
            // gradiente" — genérico, no específico de un bloque, igual que
            // el resto de esta clase). `background_color` (de arriba) sigue
            // siendo el color base/primer color en los dos modos; estos 3
            // campos son adicionales y solo aplican cuando el bloque los
            // pide explícitamente en su propio `PropertiesSchema::make([...])`.
            // Valores de `gradient_direction` 1:1 con las clases reales de
            // Tailwind (`bg-gradient-to-r`, etc.) — mismo criterio que
            // `link_radius`/`link_size`, sin tabla de conversión del lado
            // del frontend.
            //
            // Actualización (2026-09-02, pedido del Tech Lead): pasa a ser
            // `->required()` en todo bloque que lo use — antes quedaba en
            // `null` si el editor nunca tocaba el campo (con `null` el
            // frontend caía al fallback CSS en vez de mostrar explícitamente
            // "Sólido", que es la intención real). Se agrega una SEGUNDA
            // variante `background_type_image` con una 3ª opción "Imagen"
            // — solo para los bloques que además tienen su propio campo de
            // imagen de fondo (`cta`, `heading`; ver `PageResource.php`).
            // Las dos variantes escriben al MISMO campo (`properties.
            // background_type`) — nunca conviven en el mismo bloque, así
            // que no hay conflicto; `background_color`/`_secondary`/
            // `gradient_direction` leen ese único valor sin importar cuál
            // de las 2 variantes lo puso ahí.
            // `afterStateHydrated` (2026-09-02, fix real reportado en vivo:
            // "en el tab de SEO/Enlaces sigue siendo requerido y nada por
            // default", captura de la Sección "Propiedades de la página" —
            // un campo bindeado DIRECTO al modelo, sin Builder de por medio,
            // así que el backfill que ya existe en `PageResource.php`
            // (`backfillSliderDefaults()`, para los bloques dentro del
            // Builder de `blocks`) no lo alcanza a cubrir): el `->default()`
            // de Filament solo aplica al crear un registro nuevo, nunca al
            // hidratar un valor ausente en datos ya guardados — cualquier
            // Página/bloque guardado antes de que este campo existiera (o
            // sin tocar nunca esa sección) llega con `background_type` en
            // `null`, el Select se ve vacío y `->required()` bloquea el
            // guardado. Poniéndolo acá, en la definición COMPARTIDA del
            // campo, se corrige de una sola vez en TODOS los lugares que lo
            // usan (Página, cualquier bloque, con o sin Builder de por
            // medio) — complementa (no reemplaza) el backfill de
            // `PageResource.php`, que sigue siendo la red de seguridad del
            // lado del GUARDADO de bloques.
            'background_type' => Forms\Components\Select::make('properties.background_type')
                ->label('Tipo de fondo')
                ->options([
                    'solid' => 'Sólido',
                    'gradient' => 'Degradado (gradiente)',
                ])
                ->default('solid')
                ->required()
                ->live()
                ->afterStateHydrated(function (Forms\Components\Select $component, ?string $state): void {
                    if (blank($state)) {
                        $component->state('solid');
                    }
                }),
            'background_type_image' => Forms\Components\Select::make('properties.background_type')
                ->label('Tipo de fondo')
                ->options([
                    'solid' => 'Sólido',
                    'gradient' => 'Degradado (gradiente)',
                    'image' => 'Imagen',
                ])
                ->default('solid')
                ->required()
                ->live()
                ->afterStateHydrated(function (Forms\Components\Select $component, ?string $state): void {
                    if (blank($state)) {
                        $component->state('solid');
                    }
                }),
            'background_color_secondary' => Forms\Components\ColorPicker::make('properties.background_color_secondary')
                ->label('Color de fondo secundario')
                ->helperText('Segundo color del degradado.')
                ->visible(fn (Get $get) => $get('properties.background_type') === 'gradient'),
            'gradient_direction' => Forms\Components\Select::make('properties.gradient_direction')
                ->label('Dirección del degradado')
                ->options([
                    'to-r' => 'Izquierda → Derecha',
                    'to-l' => 'Derecha → Izquierda',
                    'to-b' => 'Arriba → Abajo',
                    'to-t' => 'Abajo → Arriba',
                    'to-br' => 'Diagonal ↘',
                    'to-bl' => 'Diagonal ↙',
                    'to-tr' => 'Diagonal ↗',
                    'to-tl' => 'Diagonal ↖',
                ])
                ->default('to-r')
                ->visible(fn (Get $get) => $get('properties.background_type') === 'gradient'),
            // 2026-08-31, pedido del Tech Lead para `testimonials`: color de
            // fondo de CADA tarjeta/item, independiente del fondo de la
            // sección (`background_color`) — mismo mecanismo (ColorPicker,
            // hex crudo consumido vía inline `style` en el frontend, ver
            // `slide_background_color`/`background_color`), reusable por
            // cualquier bloque futuro que agrupe items en tarjetas propias.
            'item_background_color' => Forms\Components\ColorPicker::make('properties.item_background_color')
                ->label('Color de fondo de cada tarjeta')
                ->helperText('Transparente si se deja vacío.'),
            // 2026-09-01, pedido del Tech Lead para `split`: la columna de
            // TEXTO puede llevar su propio color de fondo, independiente
            // del fondo de la sección (`background_color`, que en `split`
            // tapa TODO el ancho incluida la imagen) — mismo mecanismo que
            // `item_background_color`. Cuando está seteado, `Split.astro`
            // además estira la columna de texto a la altura completa de la
            // imagen (en vez de centrarla por su propio alto) y le agrega
            // padding en las 4 direcciones, para que el color respire igual
            // que en la captura de referencia.
            'text_background_color' => Forms\Components\ColorPicker::make('properties.text_background_color')
                ->label('Color de fondo del texto')
                ->helperText('Transparente si se deja vacío. Independiente del color de fondo de la sección/imagen.'),
            // 2026-08-31, pedido del Tech Lead: el color de arriba nunca se
            // pinta a máxima opacidad — se mezcla con transparente. Este
            // slider controla el % en reposo; el frontend sube +20 puntos
            // automáticamente al hacer hover/foco (ver `Testimonials.astro`,
            // `color-mix()`), sin un campo separado para el hover.
            'item_background_opacity' => Forms\Components\Slider::make('properties.item_background_opacity')
                ->label('Opacidad del fondo de cada tarjeta (%)')
                ->helperText('Sube +20 puntos automáticamente al hacer hover.')
                ->minValue(0)
                ->maxValue(100)
                ->step(5)
                ->default(30)
                ->decimalPlaces(0)
                ->fillTrack()
                ->tooltips(),
            'text_color' => Forms\Components\ColorPicker::make('properties.text_color')
                ->label('Color de texto'),
            'overlay_opacity' => Forms\Components\Slider::make('properties.overlay_opacity')
                ->label('Opacidad del overlay')
                ->minValue(0)
                ->maxValue(100)
                ->step(5)
                ->default(0)
                ->decimalPlaces(0)
                ->fillTrack()
                ->tooltips(),
            'text_align' => Forms\Components\Select::make('properties.text_align')
                ->label('Alineación del texto')
                ->options([
                    'left' => 'Izquierda',
                    'center' => 'Centro',
                    'right' => 'Derecha',
                ]),
            'content_width' => Forms\Components\Select::make('properties.content_width')
                ->label('Ancho del contenido')
                ->options([
                    'full' => 'Ancho completo (Full)',
                    'boxed' => 'Caja (Boxed)',
                    'narrow' => 'Estrecho (Narrow)',
                ]),
            'padding_y' => Forms\Components\Select::make('properties.padding_y')
                ->label('Espaciado vertical (Padding)')
                ->options([
                    'sm' => 'Pequeño (sm)',
                    'md' => 'Medio (md)',
                    'lg' => 'Grande (lg)',
                    'xl' => 'Extra Grande (xl)',
                ]),
            // Ícono centrado apuntando hacia abajo, invita a seguir bajando en la
            // página (2026-08-31, pedido del Tech Lead para Texto Enriquecido —
            // se deja como campo reusable, no específico de ese bloque, por si
            // otro bloque de sección completa lo necesita más adelante).
            'show_scroll_indicator' => Forms\Components\Toggle::make('properties.show_scroll_indicator')
                ->label('Mostrar flecha indicadora de scroll')
                ->inline(false)
                ->default(false),
            // Gate de visibilidad para un enlace único opcional (ver
            // `LinkSchema::makeSingle()`) — separado de si el enlace tiene datos
            // cargados o no, para poder ocultar el botón sin perder lo ya
            // configurado (2026-08-31, pedido del Tech Lead para Texto
            // Enriquecido: "si queremos mostrar o no el link a ver más").
            'show_link' => Forms\Components\Toggle::make('properties.show_link')
                ->label('Mostrar enlace')
                ->helperText('Activa o desactiva el botón sin perder lo ya configurado.')
                ->live()
                ->inline(false)
                ->default(false),
            // Antes el botón del enlace único tenía `rounded-full` fijo en el
            // frontend (2026-08-31, feedback visual del Tech Lead con captura:
            // el mockup real usa esquinas redondeadas, no un pill completo) —
            // se generaliza a una escala configurable en vez de hardcodearlo,
            // valores 1:1 con la escala real de `border-radius` de Tailwind v4
            // (`rounded-xs`…`rounded-full`), así el mapeo del lado del front es
            // directo sin tabla de conversión.
            'link_radius' => Forms\Components\Select::make('properties.link_radius')
                ->label('Bordes del botón')
                ->options([
                    'xs' => 'Extra chico (xs)',
                    'sm' => 'Chico (sm)',
                    'md' => 'Medio (md)',
                    'lg' => 'Grande (lg)',
                    'xl' => 'Extra grande (xl)',
                    'full' => 'Redondeado completo (pill)',
                ])
                ->default('lg'),
            // Tamaño del botón (2026-08-31, agregado junto con `link_radius` —
            // el Tech Lead pidió lo mismo para el tamaño tras ver que el botón
            // quedaba fijo en "grande"). `default('lg')` mantiene el tamaño
            // actual sin cambios para todo el contenido ya sembrado.
            'link_size' => Forms\Components\Select::make('properties.link_size')
                ->label('Tamaño del botón')
                ->options([
                    'sm' => 'Chico',
                    'md' => 'Normal',
                    'lg' => 'Grande',
                ])
                ->default('lg'),
            'media_position' => Forms\Components\Select::make('properties.media_position')
                ->label('Posición multimedia')
                ->options([
                    'left' => 'Izquierda',
                    'right' => 'Derecha',
                ]),
            // Filtros/efectos genéricos de imagen (2026-08-31, pedido del Tech
            // Lead para "Split Imagen y Texto": blend mode/brillo/opacidad/
            // bordes + los 6 filtros CSS clásicos, mismo set que ya existía
            // para el fondo del Slide). Prefijo `media_*` (no
            // `slide_background_*`): a diferencia de esos, no son exclusivos
            // del Hero — cualquier bloque futuro con `MediaUpload` los puede
            // reusar sin arrastrar el nombre "slide".
            'media_blend_mode' => Forms\Components\Select::make('properties.media_blend_mode')
                ->label('Modo de fusión (blend mode)')
                ->options(BlendModeEnum::class)
                ->default(BlendModeEnum::Normal->value),
            'media_brightness' => Forms\Components\Slider::make('properties.media_brightness')
                ->label('Brillo de la imagen (%)')
                ->helperText('100 = brillo normal.')
                ->minValue(0)
                ->maxValue(200)
                ->step(5)
                ->default(100)
                ->decimalPlaces(0)
                ->fillTrack()
                ->tooltips(),
            'media_opacity' => Forms\Components\Slider::make('properties.media_opacity')
                ->label('Opacidad de la imagen')
                ->minValue(0)
                ->maxValue(100)
                ->step(5)
                ->default(100)
                ->decimalPlaces(0)
                ->fillTrack()
                ->tooltips(),
            'media_radius' => Forms\Components\Select::make('properties.media_radius')
                ->label('Bordes de la imagen')
                ->options([
                    'none' => 'Sin redondear',
                    'sm' => 'Chico (sm)',
                    'md' => 'Medio (md)',
                    'lg' => 'Grande (lg)',
                    'xl' => 'Extra grande (xl)',
                    'full' => 'Redondeado completo',
                ])
                ->default('none'),
            'media_filter_saturate' => Forms\Components\Slider::make('properties.media_filter_saturate')
                ->label('Saturación (%)')
                ->minValue(0)
                ->maxValue(200)
                ->step(5)
                ->default(100)
                ->decimalPlaces(0)
                ->fillTrack()
                ->tooltips(),
            'media_filter_grayscale' => Forms\Components\Slider::make('properties.media_filter_grayscale')
                ->label('Escala de grises (%)')
                ->minValue(0)
                ->maxValue(100)
                ->step(5)
                ->default(0)
                ->decimalPlaces(0)
                ->fillTrack()
                ->tooltips(),
            'media_filter_sepia' => Forms\Components\Slider::make('properties.media_filter_sepia')
                ->label('Sepia (%)')
                ->minValue(0)
                ->maxValue(100)
                ->step(5)
                ->default(0)
                ->decimalPlaces(0)
                ->fillTrack()
                ->tooltips(),
            'media_filter_contrast' => Forms\Components\Slider::make('properties.media_filter_contrast')
                ->label('Contraste (%)')
                ->minValue(0)
                ->maxValue(200)
                ->step(5)
                ->default(100)
                ->decimalPlaces(0)
                ->fillTrack()
                ->tooltips(),
            'media_filter_hue_rotate' => Forms\Components\Slider::make('properties.media_filter_hue_rotate')
                ->label('Rotación de matiz (grados)')
                ->minValue(0)
                ->maxValue(360)
                ->step(5)
                ->default(0)
                ->decimalPlaces(0)
                ->fillTrack()
                ->tooltips(),
            'media_filter_blur' => Forms\Components\Slider::make('properties.media_filter_blur')
                ->label('Desenfoque (px)')
                ->minValue(0)
                ->maxValue(20)
                ->step(1)
                ->default(0)
                ->decimalPlaces(0)
                ->fillTrack()
                ->tooltips(),
            'animation' => Forms\Components\Select::make('properties.animation')
                ->label('Animación de entrada')
                ->options([
                    'none' => 'Ninguna',
                    'fade' => 'Desvanecimiento (Fade)',
                    'slide-up' => 'Deslizar arriba (Slide Up)',
                ]),
            'decorator_top' => Forms\Components\Select::make('properties.decorator_top')
                ->label('Decorador Superior')
                ->options(DecoratorShapeEnum::class)
                ->default(DecoratorShapeEnum::None->value)
                ->live(),
            'decorator_top_color' => Forms\Components\ColorPicker::make('properties.decorator_top_color')
                ->label('Color de decorador superior')
                ->visible(fn (Get $get) => filled($get('properties.decorator_top')) && $get('properties.decorator_top') !== DecoratorShapeEnum::None->value),
            // Simetría con `decorator_bottom_opacity` (2026-08-31, agregado junto
            // con el resto de las propiedades nuevas del bloque Texto Enriquecido
            // — sin esto el decorador superior quedaría con menos capacidad que
            // el inferior, que ya soportaba gradiente).
            'decorator_top_opacity' => Forms\Components\Slider::make('properties.decorator_top_opacity')
                ->label('Opacidad del decorador superior')
                ->helperText('100 = color sólido. Por debajo de 100 se aplica como gradiente (color elegido arriba, transparente abajo).')
                ->minValue(0)
                ->maxValue(100)
                ->step(5)
                ->default(100)
                ->decimalPlaces(0)
                ->fillTrack()
                ->tooltips()
                ->visible(fn (Get $get) => filled($get('properties.decorator_top')) && $get('properties.decorator_top') !== DecoratorShapeEnum::None->value),
            'decorator_bottom' => Forms\Components\Select::make('properties.decorator_bottom')
                ->label('Decorador Inferior')
                ->options(DecoratorShapeEnum::class)
                ->default(DecoratorShapeEnum::None->value)
                ->live(),
            'decorator_bottom_color' => Forms\Components\ColorPicker::make('properties.decorator_bottom_color')
                ->label('Color de decorador inferior')
                ->visible(fn (Get $get) => filled($get('properties.decorator_bottom')) && $get('properties.decorator_bottom') !== DecoratorShapeEnum::None->value),
            'decorator_bottom_opacity' => Forms\Components\Slider::make('properties.decorator_bottom_opacity')
                ->label('Opacidad del decorador inferior')
                ->helperText('100 = color sólido. Por debajo de 100 se aplica como gradiente (transparente arriba, color elegido abajo).')
                ->minValue(0)
                ->maxValue(100)
                ->step(5)
                ->default(100)
                ->decimalPlaces(0)
                ->fillTrack()
                ->tooltips()
                ->visible(fn (Get $get) => filled($get('properties.decorator_bottom')) && $get('properties.decorator_bottom') !== DecoratorShapeEnum::None->value),

            // --- Específicos de Slide (Hero) -------------------------------
            'position_container' => Forms\Components\Select::make('properties.position_container')
                ->label('Posición del contenedor')
                ->helperText('En mobile siempre se fuerza a "Abajo - Centro", sin importar lo elegido aquí.')
                ->options(PositionContainerEnum::class)
                ->default(PositionContainerEnum::BottomCenter->value),
            'align_content' => Forms\Components\Select::make('properties.align_content')
                ->label('Alineación del contenido')
                ->helperText('En mobile siempre se fuerza a "Centro", sin importar lo elegido aquí.')
                ->options(AlignContentEnum::class)
                ->default(AlignContentEnum::Center->value),
            'slide_background_color' => Forms\Components\ColorPicker::make('properties.slide_background_color')
                ->label('Color de fondo del slide')
                ->helperText('Transparente si se deja vacío.'),
            'slide_background_brightness' => Forms\Components\Slider::make('properties.slide_background_brightness')
                ->label('Brillo de la imagen (%)')
                ->helperText('100 = brillo normal.')
                ->minValue(0)
                ->maxValue(200)
                ->step(5)
                ->default(100)
                ->decimalPlaces(0)
                ->fillTrack()
                ->tooltips(),
            'slide_background_opacity' => Forms\Components\Slider::make('properties.slide_background_opacity')
                ->label('Opacidad de la imagen')
                ->minValue(0)
                ->maxValue(100)
                ->step(5)
                ->default(100)
                ->decimalPlaces(0)
                ->fillTrack()
                ->tooltips(),
            'slide_background_blend_mode' => Forms\Components\Select::make('properties.slide_background_blend_mode')
                ->label('Modo de fusión (blend mode)')
                ->options(BlendModeEnum::class)
                ->default(BlendModeEnum::Normal->value),
            'slide_background_filter_saturate' => Forms\Components\Slider::make('properties.slide_background_filter_saturate')
                ->label('Saturación (%)')
                ->minValue(0)
                ->maxValue(200)
                ->step(5)
                ->default(100)
                ->decimalPlaces(0)
                ->fillTrack()
                ->tooltips(),
            'slide_background_filter_grayscale' => Forms\Components\Slider::make('properties.slide_background_filter_grayscale')
                ->label('Escala de grises (%)')
                ->minValue(0)
                ->maxValue(100)
                ->step(5)
                ->default(0)
                ->decimalPlaces(0)
                ->fillTrack()
                ->tooltips(),
            'slide_background_filter_sepia' => Forms\Components\Slider::make('properties.slide_background_filter_sepia')
                ->label('Sepia (%)')
                ->minValue(0)
                ->maxValue(100)
                ->step(5)
                ->default(0)
                ->decimalPlaces(0)
                ->fillTrack()
                ->tooltips(),
            'slide_background_filter_contrast' => Forms\Components\Slider::make('properties.slide_background_filter_contrast')
                ->label('Contraste (%)')
                ->minValue(0)
                ->maxValue(200)
                ->step(5)
                ->default(100)
                ->decimalPlaces(0)
                ->fillTrack()
                ->tooltips(),
            'slide_background_filter_hue_rotate' => Forms\Components\Slider::make('properties.slide_background_filter_hue_rotate')
                ->label('Rotación de matiz (grados)')
                ->minValue(0)
                ->maxValue(360)
                ->step(5)
                ->default(0)
                ->decimalPlaces(0)
                ->fillTrack()
                ->tooltips(),
            'slide_background_filter_blur' => Forms\Components\Slider::make('properties.slide_background_filter_blur')
                ->label('Desenfoque (px)')
                ->minValue(0)
                ->maxValue(20)
                ->step(1)
                ->default(0)
                ->decimalPlaces(0)
                ->fillTrack()
                ->tooltips(),
        ];

        $selectedComponents = [];
        if (empty($fields)) {
            $selectedComponents = array_values($allFields);
        } else {
            foreach ($fields as $field) {
                if (isset($allFields[$field])) {
                    $selectedComponents[] = $allFields[$field];
                }
            }
        }

        return $selectedComponents;
    }
}
