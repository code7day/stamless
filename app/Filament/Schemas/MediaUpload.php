<?php

namespace App\Filament\Schemas;

use App\Models\Media;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Campo de subida directa que reemplaza al viejo `MediaSelect` (dropdown +
 * modal de creación). El usuario arrastra/selecciona el archivo y ve su
 * preview real (imagen o video) de inmediato, sin pasar por un selector de
 * biblioteca.
 *
 * Por debajo sigue existiendo la tabla `media` centralizada: el estado del
 * campo Filament NO es la ruta en disco (como espera `FileUpload` de forma
 * nativa) sino el `id` interno del registro `Media` creado al subir, para
 * que se pueda asignar directo a las columnas FK existentes
 * (`image_desktop_id`, `video_desktop_id`, etc.) sin migraciones ni cambios
 * de contrato en la API — que sigue exponiendo `url`/`uuid`, nunca el id.
 */
class MediaUpload
{
    public static function make(string $name, string $label, string $accept = 'image'): FileUpload
    {
        $upload = FileUpload::make($name)
            ->label($label)
            ->disk(fn () => self::diskName())
            ->directory('media')
            ->visibility('public')
            ->fetchFileInformation(false)
            ->getUploadedFileNameForStorageUsing(function (TemporaryUploadedFile $file) {
                $tenantSlug = Filament::getTenant()?->slug ?? 'global';
                $datetime = now()->format('YmdHis');
                $extension = $file->getClientOriginalExtension();

                return "{$tenantSlug}_media_{$datetime}.{$extension}";
            })
            ->saveUploadedFileUsing(function (FileUpload $component, TemporaryUploadedFile $file): ?string {
                $path = $component->saveUploadedFile($file);

                if ($path === null) {
                    return null;
                }

                $media = Media::create([
                    'tenant_id' => Filament::getTenant()?->id,
                    'name' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                    'path' => $path,
                    'file_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'disk' => $component->getDiskName(),
                ]);

                return (string) $media->id;
            })
            ->getUploadedFileUsing(function (string $file): ?array {
                $media = Media::find((int) $file);

                if (! $media) {
                    return null;
                }

                return [
                    'name' => $media->file_name ?? $media->name,
                    'size' => $media->size ?? 0,
                    'type' => $media->mime_type,
                    'url' => self::previewUrl($media),
                ];
            })
            ->deleteUploadedFileUsing(function (string $file): void {
                $media = Media::find((int) $file);

                if (! $media) {
                    return;
                }

                rescue(fn () => Storage::disk($media->disk?->value ?? 'public')->delete($media->path), report: false);

                $media->delete();
            });

        return match ($accept) {
            'video' => $upload
                ->acceptedFileTypes(['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'])
                ->maxSize(51200),
            'any' => $upload->maxSize(10240),
            default => $upload
                ->image()
                ->imageEditor()
                ->maxSize(5120),
        };
    }

    private static function diskName(): string
    {
        return config('filesystems.default') === 'local' ? 'public' : config('filesystems.default', 'public');
    }

    /**
     * URL para el preview DENTRO de Studio — a propósito NO usa `Media::url()`
     * (que fuerza el host de la API pública, `stamless.urls.api`, para que la
     * respuesta del API sea consumible por un frontend en otro dominio).
     * Studio vive en su propio host (`stamless.urls.studio`); si el preview
     * usara la URL de la API, FilePond hace un `fetch()` cross-origin para
     * medir el archivo (ver `file-upload.js`, `server.load`) que las rutas de
     * `storage/*` no tienen habilitado en `config/cors.php` (solo cubre
     * `v1/*`) — el campo se queda "Esperando tamaño" para siempre tras
     * recargar. Con disco local/public la URL del driver ya es relativa
     * (`/storage/...`), mismo origen que Studio, sin problema de CORS. En
     * R2/S3 el driver ya devuelve una URL absoluta propia (no pasa por este
     * host-completion), así que tampoco se ve afectado.
     */
    private static function previewUrl(Media $media): string
    {
        return Storage::disk($media->disk?->value ?? 'public')->url((string) $media->path);
    }
}
