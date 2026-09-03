<?php

namespace App\Filament\Resources;

use App\Enums\MediaDiskEnum;
use App\Filament\Resources\MediaResource\Pages;
use App\Models\Media;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class MediaResource extends Resource
{
    protected static ?string $model = Media::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationLabel = 'Multimedia';

    protected static ?string $pluralLabel = 'Archivos Multimedia';

    protected static ?string $modelLabel = 'Archivo Multimedia';

    protected static ?string $slug = 'media';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nombre descriptivo')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\FileUpload::make('path')
                            ->label('Archivo')
                            ->required()
                            ->disk(fn () => config('filesystems.default') === 'local' ? 'public' : config('filesystems.default', 'public'))
                            ->directory('media')
                            ->visibility('public')
                            ->storeFileNamesIn('file_name')
                            ->getUploadedFileNameForStorageUsing(function (TemporaryUploadedFile $file) {
                                $tenantSlug = Filament::getTenant()?->slug ?? 'global';
                                $datetime = now()->format('YmdHis');
                                $extension = $file->getClientOriginalExtension();

                                return "{$tenantSlug}_media_{$datetime}.{$extension}";
                            })
                            ->afterStateUpdated(function ($state, Set $set) {
                                if ($state) {
                                    $filePath = is_array($state) ? reset($state) : $state;
                                    $set('name', pathinfo($filePath, PATHINFO_FILENAME));

                                    $diskName = config('filesystems.default') === 'local' ? 'public' : config('filesystems.default', 'public');
                                    $set('disk', $diskName);

                                    $disk = \Storage::disk($diskName);
                                    if ($disk->exists($filePath)) {
                                        $set('mime_type', $disk->mimeType($filePath));
                                        $set('size', $disk->size($filePath));
                                    }
                                }
                            })
                            ->live(),

                        Forms\Components\TextInput::make('alt_text')
                            ->label('Texto alternativo (Alt SEO)')
                            ->maxLength(255),

                        Forms\Components\Hidden::make('disk')
                            ->default(fn () => config('filesystems.default') === 'local' ? 'public' : config('filesystems.default', 'public')),

                        Forms\Components\Hidden::make('file_name'),
                        Forms\Components\Hidden::make('mime_type'),
                        Forms\Components\Hidden::make('size'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('path')
                    ->label('Previsualización')
                    ->disk(fn ($record) => $record->disk?->value ?? 'public')
                    ->square(),

                // "Nombre de archivo" + "Tipo Mime" fusionados debajo de
                // "Nombre" (2026-09-01, pedido del Tech Lead) — mismo
                // patrón title/subtitle ya usado en PostResource/PageResource
                // (`->description()`), pero acá la segunda línea es HTML: un
                // badge real con el mime type (`Illuminate\Support\HtmlString`
                // — Filament renderiza `Htmlable` sin escapar en `description()`).
                // Color del badge según prefijo de mime, ver `mimeBadgeClasses()`
                // al final de la clase.
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable(['name', 'file_name'])
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Media $record): HtmlString => new HtmlString(
                        e($record->file_name).
                        ' <span class="inline-flex items-center rounded-md px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset '.
                        self::mimeBadgeClasses($record->mime_type).'">'.e($record->mime_type).'</span>'
                    )),

                Tables\Columns\TextColumn::make('size')
                    ->label('Tamaño')
                    ->formatStateUsing(fn ($state) => number_format($state / 1024, 2).' KB')
                    ->sortable(),

                // Columna "Disco" sacada de la tabla (2026-09-01, pedido del
                // Tech Lead: "no es relevante") — el filtro de abajo se deja,
                // sigue sirviendo para acotar la lista aunque el dato ya no
                // se muestre en cada fila.
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('disk')
                    ->label('Disco')
                    ->options(MediaDiskEnum::class),
            ])
            ->actions([
                // `->modalWidth('md')` (2026-09-02, pedido en vivo del Tech
                // Lead: el slideOver se veía casi vacío — el form real es 1
                // sola columna (nombre/archivo/alt), sin necesidad del ancho
                // default) — mismo patrón `->modalWidth('Nxl')` ya usado en
                // ServiceResource/SliderResource/TestimonialResource.
                Actions\EditAction::make()
                    ->slideOver()
                    ->modalWidth('md'),
                Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultPaginationPageOption(50)
            ->emptyStateActions([
                Actions\CreateAction::make()
                    ->slideOver()
                    ->modalWidth('md'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageMedia::route('/'),
        ];
    }

    /**
     * Clases Tailwind (mismo look que un badge de Filament: `ring-1
     * ring-inset`, fondo suave + texto del mismo tono) según el prefijo del
     * mime type. Usado en la descripción HTML de la columna "Nombre" —
     * ver `table()` arriba.
     */
    private static function mimeBadgeClasses(?string $mimeType): string
    {
        $prefix = explode('/', $mimeType ?? '')[0] ?? '';

        return match ($prefix) {
            'image' => 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-400/10 dark:text-success-400 dark:ring-success-400/20',
            'video' => 'bg-primary-50 text-primary-700 ring-primary-600/20 dark:bg-primary-400/10 dark:text-primary-400 dark:ring-primary-400/20',
            'application' => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400 dark:ring-warning-400/20',
            default => 'bg-gray-50 text-gray-600 ring-gray-500/10 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/20',
        };
    }
}
