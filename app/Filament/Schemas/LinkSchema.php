<?php

namespace App\Filament\Schemas;

use App\Enums\LinkIconEnum;
use App\Models\Page;
use App\Models\Post;
use Filament\Forms;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;

class LinkSchema
{
    /**
     * @param  bool  $withIcon  Agrega un Select de ícono opcional
     *                          (`LinkIconEnum`) al ítem — 2026-09-02, solo para el `link_list` de
     *                          `colophon` ("faltan iconos" en la columna Contacto). `false` por
     *                          default: los demás ~10 consumidores de `LinkSchema::make()` (CTAs de
     *                          página, menús, etc.) no lo piden y no deben ganar un campo nuevo sin
     *                          pedirlo.
     */
    public static function make(string $name = 'links', string $label = 'Enlaces', bool $withIcon = false): Forms\Components\Repeater
    {
        return Forms\Components\Repeater::make($name)
            ->label($label)
            ->schema([
                ...($withIcon ? [
                    Forms\Components\Select::make('icon')
                        ->label('Ícono (opcional)')
                        ->options(LinkIconEnum::class)
                        ->placeholder('Sin ícono'),
                ] : []),

                Grid::make(2)
                    ->schema([
                        Forms\Components\Select::make('type')
                            ->label('Estilo del botón')
                            ->options([
                                'primary' => 'Primario',
                                'secondary' => 'Secundario',
                                'outline' => 'Delineado',
                                'text' => 'Texto',
                            ])
                            ->default('primary')
                            ->required(),

                        Forms\Components\TextInput::make('label')
                            ->label('Texto del botón')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\Select::make('source_type')
                            ->label('Tipo de origen')
                            ->options([
                                'custom' => 'Personalizado',
                                'page' => 'Página',
                                'post' => 'Entrada de Blog',
                                'url' => 'URL externa',
                            ])
                            ->default('custom')
                            ->required()
                            ->live(),

                        Forms\Components\Select::make('source_id')
                            ->label('Destino')
                            ->searchable()
                            ->options(function (Get $get) {
                                $sourceType = $get('source_type');
                                if ($sourceType === 'page') {
                                    // ->publiclyLinkable() (2026-09-02, bug real
                                    // en vivo): excluye Header/Footer — son
                                    // partials sin URL pública propia, no
                                    // deben poder elegirse como destino.
                                    return Page::query()->publiclyLinkable()->pluck('title', 'id');
                                }
                                if ($sourceType === 'post') {
                                    return Post::pluck('title', 'id');
                                }

                                return [];
                            })
                            ->visible(fn (Get $get) => in_array($get('source_type'), ['page', 'post']))
                            ->required(fn (Get $get) => in_array($get('source_type'), ['page', 'post'])),

                        Forms\Components\TextInput::make('url')
                            ->label('URL')
                            ->placeholder('https://ejemplo.com')
                            ->visible(fn (Get $get) => in_array($get('source_type'), ['custom', 'url']))
                            ->required(fn (Get $get) => in_array($get('source_type'), ['custom', 'url'])),
                    ]),

                Fieldset::make('Propiedades del enlace')
                    ->schema([
                        Forms\Components\Select::make('target')
                            ->label('Destino de apertura')
                            ->options([
                                '_self' => 'Misma pestaña (_self)',
                                '_blank' => 'Nueva pestaña (_blank)',
                            ])
                            ->default('_self')
                            ->required(),

                        Forms\Components\TextInput::make('alt')
                            ->label('Texto Alt SEO (Opcional)')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('class')
                            ->label('Clase CSS (Opcional)')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('id')
                            ->label('ID HTML (Opcional)')
                            ->maxLength(255),
                    ])
                    ->columns(2),
            ])
            ->itemLabel(fn (array $state): ?string => ($state['label'] ?? null) ? ($state['label'].' ('.($state['source_type'] ?? '').')') : null)
            ->collapsible()
            ->collapsed();
    }

    /**
     * Variante NO repetible: un único CTA, sin `Repeater` — a pedido del
     * Tech Lead para Slides (2026-08-30): "solo tendrá un boton CTA no se
     * necesita multiples enlaces". `links` sigue siendo el mismo array
     * jsonb en DB/API (compatible con `ContentLink[]`/`links[0]` que ya
     * consume el front) — solo cambia la UI de Studio, que deja de
     * ofrecer "agregar otro enlace". Bind directo a `"$name.0.*"` vía
     * dot-path (mismo patrón que `properties.*` en `PropertiesSchema`,
     * incluidos los `Get`/`Set` con el path completo en los closures —
     * sin `Repeater` no hay contenedor que resuelva paths relativos).
     * Devuelve los componentes ya separados en dos grupos (`main` /
     * `properties`) para que el caller los distribuya en columnas
     * distintas del layout, tal como pidió el Tech Lead.
     *
     * @return array{main: array<int, Forms\Components\Component>, properties: array<int, Forms\Components\Component>}
     */
    public static function makeSingle(string $name = 'links'): array
    {
        return [
            'main' => [
                Forms\Components\Select::make("{$name}.0.type")
                    ->label('Estilo del botón')
                    ->options([
                        'primary' => 'Primario',
                        'secondary' => 'Secundario',
                        'outline' => 'Delineado',
                        'text' => 'Texto',
                    ])
                    ->default('primary')
                    ->required(),

                Forms\Components\TextInput::make("{$name}.0.label")
                    ->label('Texto del botón')
                    ->required()
                    ->maxLength(255),

                Forms\Components\Select::make("{$name}.0.source_type")
                    ->label('Tipo de origen')
                    ->options([
                        'custom' => 'Personalizado',
                        'page' => 'Página',
                        'post' => 'Entrada de Blog',
                        'url' => 'URL externa',
                    ])
                    ->default('custom')
                    ->required()
                    ->live(),

                Forms\Components\Select::make("{$name}.0.source_id")
                    ->label('Destino')
                    ->searchable()
                    ->options(function (Get $get) use ($name) {
                        $sourceType = $get("{$name}.0.source_type");
                        if ($sourceType === 'page') {
                            // Ver comentario equivalente en make() arriba.
                            return Page::query()->publiclyLinkable()->pluck('title', 'id');
                        }
                        if ($sourceType === 'post') {
                            return Post::pluck('title', 'id');
                        }

                        return [];
                    })
                    ->visible(fn (Get $get) => in_array($get("{$name}.0.source_type"), ['page', 'post']))
                    ->required(fn (Get $get) => in_array($get("{$name}.0.source_type"), ['page', 'post'])),

                Forms\Components\TextInput::make("{$name}.0.url")
                    ->label('URL')
                    ->placeholder('https://ejemplo.com')
                    ->visible(fn (Get $get) => in_array($get("{$name}.0.source_type"), ['custom', 'url']))
                    ->required(fn (Get $get) => in_array($get("{$name}.0.source_type"), ['custom', 'url'])),
            ],
            'properties' => [
                Forms\Components\Select::make("{$name}.0.target")
                    ->label('Destino de apertura')
                    ->options([
                        '_self' => 'Misma pestaña (_self)',
                        '_blank' => 'Nueva pestaña (_blank)',
                    ])
                    ->default('_self')
                    ->required(),

                Forms\Components\TextInput::make("{$name}.0.alt")
                    ->label('Texto Alt SEO (Opcional)')
                    ->maxLength(255),

                Forms\Components\TextInput::make("{$name}.0.class")
                    ->label('Clase CSS (Opcional)')
                    ->maxLength(255),

                Forms\Components\TextInput::make("{$name}.0.id")
                    ->label('ID HTML (Opcional)')
                    ->maxLength(255),
            ],
        ];
    }
}
