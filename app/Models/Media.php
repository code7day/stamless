<?php

namespace App\Models;

use App\Enums\MediaDiskEnum;
use App\Traits\HasTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

#[Fillable(['tenant_id', 'uuid', 'name', 'file_name', 'mime_type', 'path', 'disk', 'size', 'alt_text'])]
class Media extends Model
{
    use HasTenant, HasUuid;

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'disk' => MediaDiskEnum::class,
            'size' => 'integer',
        ];
    }

    /**
     * Public URL of the file on its configured disk.
     *
     * Los discos `local`/`public` (ver `config/filesystems.php`, clave
     * `'url' => '/storage'`) devuelven una ruta RELATIVA — correcta para
     * consumidores same-origin (ej. previews dentro de Studio), pero
     * inútil para la API pública headless: el frontend (CICA360/Astro)
     * vive en un origen completamente distinto y no tiene forma de saber
     * contra qué host resolverla. R2/S3 ya devuelven una URL absoluta vía
     * su propio driver — esos casos no se tocan. Si el disco configurado
     * devuelve una ruta relativa, se completa con el host de la API
     * pública (`config('stamless.urls.api')`), que en local apunta al
     * mismo monolito/docroot que sirve `public/storage` de todas formas.
     */
    public function url(): string
    {
        $url = Storage::disk($this->disk?->value ?? MediaDiskEnum::Local->value)->url((string) $this->path);

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $url = rtrim(config('stamless.urls.api', config('app.url')), '/').$url;
        }

        return $url;
    }
}
