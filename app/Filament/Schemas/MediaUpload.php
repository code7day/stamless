<?php

namespace App\Filament\Schemas;

use App\Filament\Resources\MediaResource;
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
    /**
     * @param  string|null  $helperText  Texto de ayuda propio del campo (UX
     *                                   puntual, ej. "para qué se usa esta
     *                                   imagen"). 2026-09-14: parámetro
     *                                   nuevo, opcional — a propósito NO se
     *                                   encadena `->helperText()` después
     *                                   de `make()` en el resource, porque
     *                                   PISARÍA el closure de acá abajo que
     *                                   ya usa ese mismo método para avisar
     *                                   el límite de plan alcanzado
     *                                   (`MediaResource::mediaLimitMessage()`).
     *                                   Con el parámetro, ambos mensajes
     *                                   conviven: el de límite SIEMPRE tiene
     *                                   prioridad (bloqueante), el propio
     *                                   del campo se muestra el resto del
     *                                   tiempo. Sin pasar nada, el
     *                                   comportamiento es idéntico al de
     *                                   antes para los 7 call sites
     *                                   existentes (ninguno lo usaba).
     */
    public static function make(string $name, string $label, string $accept = 'image', ?string $helperText = null): FileUpload
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
                $tenantId = Filament::getTenant()?->id ?? auth()->user()?->tenant_id;
                $media = Media::query()
                    ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
                    ->find((int) $file);

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
            // A propósito NO borra el archivo del disco ni la fila `Media`
            // (2026-09-11, pedido del Tech Lead tras un borrado real:
            // "mejor que no borre la original por que puede que lo esté
            // usando otro componente o bloque, es mejor que genere una
            // copia"). `media` es una tabla centralizada — el mismo id
            // puede estar referenciado desde el `content` (jsonb) de
            // muchos bloques distintos a la vez (heading, features,
            // slides, etc.), sin un pivot ni FKs normales que permitan
            // chequear barato "¿lo sigue usando algo más?" antes de
            // decidir si borrar. Confirmado en vivo: al editar/recortar
            // una imagen ya guardada (bug real de Filament, ver
            // PROGRESS.md) o al simplemente quitarla de un campo, este
            // callback SÍ se disparaba y borraba el archivo físico aunque
            // esa misma imagen siguiera en uso en otro bloque/página —
            // pérdida de datos real, no hipotética (un `Cliente0MediaSeeder`
            // que apunta a un archivo puesto a mano en disco quedó roto
            // así en esta misma sesión). "Quitar" un campo de imagen ahora
            // solo desvincula la referencia de ESE campo (ya lo hace
            // Filament al actualizar el estado del Builder) — el archivo y
            // la fila `Media` quedan intactos en la Biblioteca de Medios,
            // reutilizables desde cualquier otro bloque. El borrado real
            // (cuando de verdad ya no se necesita) se hace a propósito
            // desde `MediaResource::DeleteAction`, nunca como efecto
            // secundario de editar un campo puntual.
            ->deleteUploadedFileUsing(function (): void {})
            // 2026-09-14, ADR-067: cada subida por ESTE campo crea una fila
            // `Media` NUEVA (ver `saveUploadedFileUsing()` arriba — nunca
            // reemplaza/borra una existente, por el mismo criterio de
            // `deleteUploadedFileUsing()` de más arriba), así que cuenta
            // para el mismo tope de `Tenant::maxMedia()` que ya hacía
            // cumplir `MediaResource` en su propio botón "Crear". Hasta
            // esta vuelta, ESTE campo (usado en Páginas/Posts/Servicios/
            // Sliders/Testimonios) no lo chequeaba — era el único hueco por
            // el que se podía seguir sumando archivos sin límite. Se
            // reusa `MediaResource::isMediaLimitReached()`/
            // `mediaLimitMessage()` tal cual (mismo mensaje, mismo umbral,
            // sin duplicar la lógica) — nota honesta: `->disabled()`
            // congela el campo COMPLETO, no solo "subir uno nuevo": si un
            // Page/Service ya tenía una imagen cargada en este campo y el
            // tenant llega al tope en OTRO lado, ese campo puntual también
            // queda sin poder editarse/quitarse hasta bajar de tope —
            // mismo nivel de granularidad que ya usa `MediaResource` para
            // su propio botón "Crear" (no hay una forma nativa en Filament
            // de deshabilitar solo "agregar" y dejar "quitar" habilitado
            // en un `FileUpload`).
            ->disabled(fn (): bool => MediaResource::isMediaLimitReached())
            ->helperText(fn (): ?string => MediaResource::isMediaLimitReached() ? MediaResource::mediaLimitMessage() : $helperText);

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
