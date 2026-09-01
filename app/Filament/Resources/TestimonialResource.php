<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TestimonialResource\Pages;
use App\Filament\Schemas\MediaUpload;
use App\Models\Testimonial;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Módulo de Testimonios / Casos de Éxito (2026-08-31) — antes vivían como
 * un `Repeater` inline dentro de `content.items` del bloque `testimonials`
 * de cada página (sin gestión centralizada, sin poder reusar el mismo
 * testimonio en más de una sección, sin ocultar uno puntual sin borrarlo).
 * Ahora es su propio recurso: el bloque `testimonials` de `PageResource`
 * queda reducido a encabezado + filtro (cuántos mostrar, en qué orden) +
 * link opcional — la data real se gestiona acá y se resuelve en runtime
 * contra la API pública (`ResolvesPublicLinks::attachResolvedBlockContent()`,
 * respetando `is_visible` + el filtro elegido en cada bloque). Ver ADR en
 * `docs/context/DECISIONS.md`.
 */
class TestimonialResource extends Resource
{
    protected static ?string $model = Testimonial::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Testimonios';

    protected static ?string $pluralLabel = 'Testimonios / Casos de Éxito';

    protected static ?string $modelLabel = 'Testimonio';

    protected static ?string $slug = 'testimonials';

    /**
     * Layout "avatar a la izquierda, datos a la derecha" (2026-08-31, UX;
     * corregido el mismo día — 2da vuelta: la primera versión anidaba
     * `Group` dentro de `Group`+`Grid` para lograr el mismo layout visual,
     * y el Tech Lead mandó una captura mostrando que la Section quedaba
     * MÁS angosta todavía, sin usar el ancho real del modal — grids de
     * Filament anidados (`Section` columns → `Group` → `Grid` columns)
     * pueden colapsar a un ancho "shrink-to-fit" en vez de estirarse al
     * 100% del contenedor. Aplanado: todos los campos son hijos DIRECTOS
     * de la única `Section`, cada uno con su propio `columnSpan()` — sin
     * `Group` ni `Grid` intermedios. `->extraAttributes(['class' =>
     * 'w-full'])` en la Section fuerza el 100% de ancho por las dudas,
     * cinturón y tirantes. `->modalWidth('2xl')` en las 3 acciones que
     * abren este form (crear, editar, empty state) para que el slide-over
     * tenga el ancho real que este layout necesita.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(2)
                    ->extraAttributes(['class' => 'w-full'])
                    ->schema([
                        // Avatar recortado circular de entrada (2026-08-31):
                        // se ve como una card de testimonio real desde el
                        // primer preview, sin que el editor tenga que
                        // adivinar cómo va a quedar recortado en el sitio.
                        MediaUpload::make('avatar_id', 'Avatar (Opcional)')
                            ->circleCropper()
                            ->columnSpan(1),

                        Group::make()
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                ->label('Nombre del autor')
                                ->required()
                                ->maxLength(255)
                                ->columnSpan(1),

                                Forms\Components\TextInput::make('role')
                                    ->label('Puesto / Empresa (Opcional)')
                                    ->maxLength(255)
                                    ->columnSpan(1),

                                Forms\Components\Toggle::make('is_visible')
                                    ->label('Visible')
                                    ->helperText('Oculta el testimonio de la API pública (y de cualquier bloque que lo liste) sin borrarlo.')
                                    ->default(true)
                                    ->required()
                                    ->columnSpanFull(),
                            ])
                            ->columnSpan(1),

                        Forms\Components\Textarea::make('quote')
                            ->label('Testimonio / Frase')
                            ->required()
                            ->rows(4)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('avatar.path')
                    ->label('')
                    ->disk(fn ($record) => $record?->avatar?->disk?->value ?? 'public')
                    ->circular(),

                // Nombre+puesto fusionados en 1 columna, 2 filas (2026-08-31,
                // UX: mismo patrón que title/subtitle en ServiceResource)
                // — `->description()` es el patrón nativo de Filament: nombre
                // arriba en negrita, puesto/empresa debajo en gris.
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable(['name', 'role'])
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Testimonial $record): ?string => $record->role),

                Tables\Columns\ToggleColumn::make('is_visible')
                    ->label('Visible')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_visible')
                    ->label('Visibilidad')
                    ->placeholder('Todos')
                    ->trueLabel('Solo visibles')
                    ->falseLabel('Solo ocultos'),
            ])
            ->actions([
                Actions\EditAction::make()
                    ->slideOver()
                    ->modalWidth('2xl'),
                Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),

                    // Toggle masivo (2026-08-31, UX): ocultar/mostrar varios
                    // testimonios de una sola vez sin entrar uno por uno —
                    // útil para desactivar los de un cliente puntual o
                    // reactivar una tanda entera.
                    Actions\BulkAction::make('show')
                        ->label('Marcar como visibles')
                        ->icon('heroicon-o-eye')
                        ->action(fn ($records) => $records->each->update(['is_visible' => true]))
                        ->deselectRecordsAfterCompletion(),

                    Actions\BulkAction::make('hide')
                        ->label('Marcar como ocultos')
                        ->icon('heroicon-o-eye-slash')
                        ->action(fn ($records) => $records->each->update(['is_visible' => false]))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No hay testimonios cargados')
            ->emptyStateDescription('Los testimonios que crees acá quedan disponibles para el bloque "Testimonios" de cualquier página.')
            ->emptyStateActions([
                Actions\CreateAction::make()
                    ->slideOver()
                    ->modalWidth('2xl'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageTestimonials::route('/'),
        ];
    }
}
