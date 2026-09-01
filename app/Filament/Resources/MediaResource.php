<?php

namespace App\Filament\Resources;

use App\Enums\MediaDiskEnum;
use App\Models\Media;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Actions;
use App\Filament\Resources\MediaResource\Pages;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;

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
                            ->getUploadedFileNameForStorageUsing(function (\Livewire\Features\SupportFileUploads\TemporaryUploadedFile $file) {
                                $tenantSlug = \Filament\Facades\Filament::getTenant()?->slug ?? 'global';
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
                    ])
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

                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('file_name')
                    ->label('Nombre de archivo')
                    ->searchable(),

                Tables\Columns\TextColumn::make('mime_type')
                    ->label('Tipo Mime')
                    ->sortable(),

                Tables\Columns\TextColumn::make('size')
                    ->label('Tamaño')
                    ->formatStateUsing(fn ($state) => number_format($state / 1024, 2) . ' KB')
                    ->sortable(),

                Tables\Columns\TextColumn::make('disk')
                    ->label('Disco')
                    ->badge()
                    ->color(fn ($state) => match ($state?->value) {
                        'r2' => 'primary',
                        's3' => 'warning',
                        'public' => 'success',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('disk')
                    ->label('Disco')
                    ->options(MediaDiskEnum::class),
            ])
            ->actions([
                Actions\EditAction::make()
                    ->slideOver(),
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
                    ->slideOver(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageMedia::route('/'),
        ];
    }
}
