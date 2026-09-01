<?php

namespace App\Filament\Schemas;

// @deprecated 2026-08-30 — reemplazado por App\Filament\Schemas\MediaUpload
// (subida directa con preview real, en vez de dropdown + modal). Ningún
// Resource lo usa ya (ver PageResource/PostResource/SliderResource); se deja
// el archivo sin borrar porque este entorno no tiene permiso para eliminar
// archivos del folder de trabajo del usuario. Seguro de borrar en un PR
// normal fuera de este sandbox.

use Filament\Forms\Components\Select;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;
use App\Models\Media;

class MediaSelect
{
    public static function make(string $name, string $label): Select
    {
        return Select::make($name)
            ->label($label)
            ->options(function () {
                return Media::all()->mapWithKeys(function ($media) {
                    $url = $media->disk === 'public' ? "/storage/{$media->path}" : "/storage/{$media->path}";
                    
                    $isImage = str_starts_with($media->mime_type, 'image/');
                    $iconHtml = $isImage 
                        ? "<img src='{$url}' class='w-8 h-8 rounded object-cover' style='max-width: 32px; max-height: 32px; display: inline-block;' onerror=\"this.style.display='none'\" />"
                        : "<div class='w-8 h-8 rounded bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-gray-500 font-bold text-[9px]' style='width: 32px; height: 32px; display: inline-flex;'>DOC</div>";
                    
                    $html = "<div class='flex items-center gap-2 py-0.5'>
                        {$iconHtml}
                        <div class='flex flex-col text-left'>
                            <span class='font-medium text-xs text-gray-900 dark:text-gray-100' style='line-height: 1.1;'>{$media->name}</span>
                            <span class='text-[10px] text-gray-400' style='line-height: 1.1;'>{$media->file_name}</span>
                        </div>
                    </div>";
                    
                    return [$media->id => $html];
                });
            })
            ->allowHtml()
            ->native(false)
            ->searchable()
            ->preload()
            ->createOptionForm([
                TextInput::make('name')
                    ->label('Nombre descriptivo')
                    ->required(),
                FileUpload::make('path')
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
                            $file = is_array($state) ? reset($state) : $state;
                            if ($file instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile) {
                                $originalName = $file->getClientOriginalName();
                                $set('name', pathinfo($originalName, PATHINFO_FILENAME));
                            } elseif (is_string($file)) {
                                $set('name', pathinfo($file, PATHINFO_FILENAME));
                            }
                        }
                    })
                    ->live(),
                TextInput::make('alt_text')
                    ->label('Texto alternativo (Alt SEO)'),
                Hidden::make('disk')
                    ->default(fn () => config('filesystems.default') === 'local' ? 'public' : config('filesystems.default', 'public')),
                Hidden::make('lang_iso')
                    ->default('es'),
            ])
            ->createOptionUsing(function (array $data) {
                $filePath = is_array($data['path']) ? reset($data['path']) : $data['path'];
                
                $diskName = config('filesystems.default') === 'local' ? 'public' : config('filesystems.default', 'public');
                $disk = \Storage::disk($diskName);
                
                $size = 0;
                $mimeType = 'image/jpeg';
                if ($disk->exists($filePath)) {
                    $size = $disk->size($filePath);
                    $mimeType = $disk->mimeType($filePath);
                }
                
                $media = Media::create([
                    'tenant_id' => \Filament\Facades\Filament::getTenant()?->id,
                    'name' => $data['name'],
                    'path' => $filePath,
                    'file_name' => $data['file_name'] ?? basename($filePath),
                    'mime_type' => $mimeType,
                    'size' => $size,
                    'disk' => $diskName,
                    'alt_text' => $data['alt_text'] ?? null,
                    'lang_iso' => 'es',
                ]);
                
                return $media->id;
            });
    }
}
