<?php

namespace Database\Seeders;

use App\Enums\MediaDiskEnum;
use App\Models\Media;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Media inicial de Cliente 0 — **patrón nuevo (2026-08-31, a pedido
 * explícito del Tech Lead)**: los archivos físicos van COMMITEADOS al repo
 * en `storage/app/public/media/` (no está en `.gitignore` — solo
 * `/public/storage`, el symlink, y `storage/*.key` lo están), y este
 * seeder crea el registro `Media` que apunta a cada uno. Nada se sube en
 * runtime — el archivo ya existe en el disco antes de correr `db:seed`.
 *
 * Es el paso 1 de un patrón de 2 pasos que se vuelve el ESTÁNDAR para todo
 * contenido inicial con imágenes/video (no solo el Hero): 1) este seeder
 * (u otro análogo) inyecta en `media` primero; 2) el seeder de contenido
 * (`Cliente0HomeSlidesSeeder`, y a futuro `Cliente0ContentSeeder` para
 * imágenes destacadas de pages/posts, etc.) resuelve el `id` vía
 * `Cliente0MediaSeeder::mediaId()` y lo asigna a la FK correspondiente.
 * Así el contenido inicial completo (datos Y assets) queda reproducible
 * desde `php artisan migrate:fresh --seed` en cualquier entorno, sin
 * volver a subir nada a mano por Studio.
 *
 * Idempotente: `firstOrCreate` por `tenant_id` + `path` — un archivo físico
 * nunca debería tener 2 registros `Media` distintos. Si el archivo no
 * existe en disco (por ejemplo, alguien corre `db:seed` en un checkout que
 * no trajo los assets), se omite con un warning en vez de crear un
 * registro `Media` roto apuntando a nada.
 *
 * **Límite explícito, documentado también en `CURRENT_STATE.md`**: siembra
 * contra el disco `public` (local/desarrollo) — Cloudflare R2 (producción)
 * todavía no está configurado. Cuando R2 esté listo, migrar estos assets
 * es subirlos una vez y actualizar `disk`/`path` en los registros `Media`
 * ya creados — no hace falta rehacer la estructura de este seeder.
 */
class Cliente0MediaSeeder extends Seeder
{
    /**
     * Catálogo de archivos a sembrar, todos relativos a `media/` dentro del
     * disco `public` (mismo directorio que usa `App\Filament\Schemas\MediaUpload`
     * para subidas reales desde Studio — convención consistente).
     *
     * @var array<string, array{file: string, name: string, alt: string}>
     */
    private const array FILES = [
        'home_slide_1' => [
            'file' => 'cica360_media_slide1.webp',
            'name' => 'Fondo Hero — Slide 1 (El socio que necesitas)',
            'alt' => 'El socio que necesitas',
        ],
        'home_slide_2' => [
            'file' => 'cica360_media_slide2.webp',
            'name' => 'Fondo Hero — Slide 2 (Sin letra chica)',
            'alt' => 'Sin letra chica',
        ],
        'home_slide_3' => [
            'file' => 'cica360_media_slide3.webp',
            'name' => 'Fondo Hero — Slide 3 (Tu futuro se diseña hoy)',
            'alt' => 'Tu futuro se diseña hoy',
        ],
        'home_split_1' => [
            'file' => 'cica360_media_split_1.webp',
            'name' => 'Split Home — ¿Qué hacemos?',
            'alt' => 'Equipo de CICA360 en una reunión de asesoría',
        ],
        'home_split_2' => [
            'file' => 'cica360_media_split_2.webp',
            'name' => 'Split Home — ¿A quién nos dirigimos?',
            'alt' => 'Personas conversando sobre sus proyectos con CICA360',
        ],
        // Avatares de Testimonios (2026-08-31, ver ADR-033 — ampliado el
        // mismo día): el Tech Lead subió 5 fotos a `storage/app/public/media/`
        // para **12** testimonios de ejemplo (`Cliente0TestimonialsSeeder`)
        // — cada foto se REUTILIZA en 2 o 3 testimonios distintos (pedido
        // explícito: "reutilizar de forma aleatoria las 5 fotos"). Por eso
        // el `name`/`alt` de estos 5 registros `Media` es genérico (no
        // atado a una persona puntual como antes de la ampliación a 12) —
        // atarlo a un nombre sería engañoso ahora que cada foto aparece en
        // más de un testimonio.
        'testimony_1' => [
            'file' => 'cica360_media_testimony-1.webp',
            'name' => 'Avatar Testimonio 1',
            'alt' => 'Foto de perfil, cliente CICA360',
        ],
        'testimony_2' => [
            'file' => 'cica360_media_testimony-2.webp',
            'name' => 'Avatar Testimonio 2',
            'alt' => 'Foto de perfil, cliente CICA360',
        ],
        'testimony_3' => [
            'file' => 'cica360_media_testimony-3.webp',
            'name' => 'Avatar Testimonio 3',
            'alt' => 'Foto de perfil, cliente CICA360',
        ],
        'testimony_4' => [
            'file' => 'cica360_media_testimony-4.webp',
            'name' => 'Avatar Testimonio 4',
            'alt' => 'Foto de perfil, cliente CICA360',
        ],
        'testimony_5' => [
            'file' => 'cica360_media_testimony-5.webp',
            'name' => 'Avatar Testimonio 5',
            'alt' => 'Foto de perfil, cliente CICA360',
        ],
        // Logos del bloque "Empresas con las que trabajamos" (2026-08-31):
        // 7 archivos subidos por el Tech Lead — nombres/alt genéricos
        // porque son placeholders de marca (el Tech Lead los va a
        // reemplazar por logos reales de socios cuando los tenga; por eso
        // no se numeran por nombre de empresa, a diferencia de un logo
        // real que sí llevaría su nombre en `alt`).
        'logo_1' => [
            'file' => 'cica360_media_logo_1.png',
            'name' => 'Logo socio 1',
            'alt' => 'Empresa asociada 1',
        ],
        'logo_2' => [
            'file' => 'cica360_media_logo_2.png',
            'name' => 'Logo socio 2',
            'alt' => 'Empresa asociada 2',
        ],
        'logo_3' => [
            'file' => 'cica360_media_logo_3.png',
            'name' => 'Logo socio 3',
            'alt' => 'Empresa asociada 3',
        ],
        'logo_4' => [
            'file' => 'cica360_media_logo_4.png',
            'name' => 'Logo socio 4',
            'alt' => 'Empresa asociada 4',
        ],
        'logo_5' => [
            'file' => 'cica360_media_logo_5.png',
            'name' => 'Logo socio 5',
            'alt' => 'Empresa asociada 5',
        ],
        'logo_6' => [
            'file' => 'cica360_media_logo_6.png',
            'name' => 'Logo socio 6',
            'alt' => 'Empresa asociada 6',
        ],
        'logo_7' => [
            'file' => 'cica360_media_logo_7.png',
            'name' => 'Logo socio 7',
            'alt' => 'Empresa asociada 7',
        ],
        // Logos 8-10 (2026-09-01): ampliación de 7 a 10 a pedido explícito
        // del Tech Lead ("generar 10 logos de partners de ejemplo") —
        // mismo criterio de placeholder que los 7 anteriores (glifo
        // abstracto simple + subrayado, sin nombre de marca real). Con 10
        // items el bloque `logos` supera el umbral de 7 y dispara el modo
        // carousel de verdad (2 páginas: 7 + 3) — antes, con exactamente 7,
        // solo se veía la grilla estática de una página.
        'logo_8' => [
            'file' => 'cica360_media_logo_8.png',
            'name' => 'Logo socio 8',
            'alt' => 'Empresa asociada 8',
        ],
        'logo_9' => [
            'file' => 'cica360_media_logo_9.png',
            'name' => 'Logo socio 9',
            'alt' => 'Empresa asociada 9',
        ],
        'logo_10' => [
            'file' => 'cica360_media_logo_10.png',
            'name' => 'Logo socio 10',
            'alt' => 'Empresa asociada 10',
        ],
    ];

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'cica360')->first();

        if (! $tenant) {
            return;
        }

        foreach (self::FILES as $meta) {
            $this->seedFile($tenant, $meta['file'], $meta['name'], $meta['alt']);
        }
    }

    private function seedFile(Tenant $tenant, string $file, string $name, string $alt): void
    {
        $path = "media/{$file}";

        if (! Storage::disk('public')->exists($path)) {
            $this->command?->warn("Cliente0MediaSeeder: falta storage/app/public/{$path}, se omite.");

            return;
        }

        Media::firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'path' => $path,
            ],
            [
                'name' => $name,
                'file_name' => $file,
                'mime_type' => Storage::disk('public')->mimeType($path) ?: 'image/webp',
                'size' => Storage::disk('public')->size($path),
                'disk' => MediaDiskEnum::Public->value,
                'alt_text' => $alt,
            ]
        );
    }

    /**
     * Resuelve el `id` interno de un `Media` ya sembrado por su `path`
     * relativo dentro de `media/` — helper reusable para que otros
     * seeders de contenido (`Cliente0HomeSlidesSeeder`, y a futuro
     * `Cliente0ContentSeeder`) asignen la FK sin repetir la query. Devuelve
     * `null` si el archivo nunca se sembró (no rompe el seeder que lo
     * llama — ver `Cliente0HomeSlidesSeeder`, que deja la FK sin tocar en
     * ese caso en vez de forzar `null`).
     */
    public static function mediaId(Tenant $tenant, string $file): ?int
    {
        return Media::where('tenant_id', $tenant->id)
            ->where('path', "media/{$file}")
            ->value('id');
    }
}
