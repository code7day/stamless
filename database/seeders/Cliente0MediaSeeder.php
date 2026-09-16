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
        // Encabezado de "Sobre CICA" (bloque `heading`, 2026-09-05): 3
        // recortes responsivos ya subidos por el Tech Lead a
        // `storage/app/public/media/` — foto de skyline con silueta,
        // referencia real en `docs/UX-UI-design/ABOUT.pdf` (cica360).
        'header_desktop' => [
            'file' => 'cica360_media_header-desktop.webp',
            'name' => 'Encabezado Sobre CICA — Desktop',
            'alt' => 'Vista de rascacielos al atardecer con la silueta de una persona en primer plano',
        ],
        'header_tablet' => [
            'file' => 'cica360_media_header-tablet.webp',
            'name' => 'Encabezado Sobre CICA — Tablet',
            'alt' => 'Vista de rascacielos al atardecer con la silueta de una persona en primer plano',
        ],
        'header_mobile' => [
            'file' => 'cica360_media_header-mobile.webp',
            'name' => 'Encabezado Sobre CICA — Móvil',
            'alt' => 'Vista de rascacielos al atardecer con la silueta de una persona en primer plano',
        ],

        // Bloque `features` de "Sobre CICA" (Misión/Visión/Valores,
        // 2026-09-07): 3 fotos ya subidas por el Tech Lead a
        // `storage/app/public/media/`, una por item — reemplazan los
        // íconos heroicon placeholder que traía el seed original.
        'mission' => [
            'file' => 'cica360_media_mission.webp',
            'name' => 'Misión — CICA360',
            'alt' => 'Asesora de CICA360 sonriendo en una oficina con vista a la ciudad',
        ],
        'vision' => [
            'file' => 'cica360_media_vision.webp',
            'name' => 'Visión — CICA360',
            'alt' => 'Equipo de CICA360 señalando el horizonte urbano al atardecer',
        ],
        'values' => [
            'file' => 'cica360_media_values.webp',
            'name' => 'Valores — CICA360',
            'alt' => 'Asesor y clientes de CICA360 dándose la mano en una oficina',
        ],

        // Catálogo de Servicios (2026-09-11, ver `Cliente0ServicesSeeder` y
        // ADR-049): 9 fotos reales, una por servicio del catálogo real del
        // Tech Lead (reemplaza el reuso cíclico de 6 imágenes genéricas de
        // la 1ra vuelta de este módulo, que ya no aplica — cada servicio
        // real tiene su propia foto dedicada).
        'service_seguridad_financiera' => [
            'file' => 'cica360_media_service_seguridad_financiera.webp',
            'name' => 'Servicio — Seguridad Financiera',
            'alt' => 'Seguridad Financiera — seguros y retiros con enfoque de protección',
        ],
        'service_seguro_financiero' => [
            'file' => 'cica360_media_service_seguro_financiero.webp',
            'name' => 'Servicio — Seguro Financiero',
            'alt' => 'Seguro Financiero — cuidamos tu patrimonio en cada paso',
        ],
        'service_asesoria_y_consultoria_estrategica' => [
            'file' => 'cica360_media_service_asesoria_y_consultoria_estrategica.webp',
            'name' => 'Servicio — Asesoría y Consultoría Estratégica',
            'alt' => 'Asesoría y Consultoría Estratégica — impulso clave para emprendedores y PyMES',
        ],
        'service_asesoria_contable_y_financiera' => [
            'file' => 'cica360_media_service_asesoria_contable_y_financiera.webp',
            'name' => 'Servicio — Asesoría Contable y Financiera',
            'alt' => 'Asesoría Contable y Financiera — gestión clara para empresas en crecimiento',
        ],
        'service_asesoria_editorial_integral' => [
            'file' => 'cica360_media_service_asesoria_editorial_integral.webp',
            'name' => 'Servicio — Asesoría Editorial Integral',
            'alt' => 'Asesoría Editorial Integral — edición, diseño y publicación de nivel profesional',
        ],
        'service_turismo_y_asesoria_vacacional' => [
            'file' => 'cica360_media_service_turismo_y_asesoria_vacacional.webp',
            'name' => 'Servicio — Turismo y Asesoría Vacacional',
            'alt' => 'Turismo y Asesoría Vacacional — pasajes, hoteles y experiencias a tu medida',
        ],
        'service_bienes_raices_e_inversion' => [
            'file' => 'cica360_media_service_bienes_raices_e_inversion.webp',
            'name' => 'Servicio — Bienes Raíces e Inversión',
            'alt' => 'Bienes Raíces e Inversión — compra, venta y proyectos inmobiliarios seguros',
        ],
        'service_asesoramiento_legal_integral' => [
            'file' => 'cica360_media_service_asesoramiento_legal_integral.webp',
            'name' => 'Servicio — Asesoramiento Legal Integral',
            'alt' => 'Asesoramiento Legal Integral — respaldo jurídico estratégico en toda la región',
        ],
        'service_asesoria_notarial' => [
            'file' => 'cica360_media_service_asesoria_notarial.webp',
            'name' => 'Servicio — Asesoría Notarial',
            'alt' => 'Asesoría Notarial — escribanía ágil y segura en Uruguay y Argentina',
        ],

        // Open Graph por defecto del tenant (2026-09-13, ver genesis
        // ADR-065 — "considerar en el seeder de contenido inicial como
        // setting general tanto para el SEO como para el OG", ya subidas
        // por el Tech Lead a `storage/app/public/media/`): usadas por
        // `Cliente0ContentSeeder::upsertSeoDefaults()` para poblar
        // `og.default_image_rect_id`/`og.default_image_square_id` — el
        // fallback que aplica `ResolvesPublicLinks::attachResolvedSeoMeta()`
        // en CUALQUIER página/post/servicio que no suba su propia imagen OG.
        'og_horizontal' => [
            'file' => 'cica360_media_og_horizontal.jpg',
            'name' => 'Open Graph — Horizontal (1200x630, default del sitio)',
            'alt' => 'CICA360 — Seguros, fondos, asesoría comercial, contable, jurídica, educación a distancia y bienes raíces',
        ],
        'og_square' => [
            'file' => 'cica360_media_og_square.jpg',
            'name' => 'Open Graph — Cuadrada (600x600, default del sitio)',
            'alt' => 'CICA360 — Seguros, fondos, asesoría comercial, contable, jurídica, educación a distancia y bienes raíces',
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
        $defaultDisk = config('filesystems.default');
        $targetDisk = $defaultDisk === 'r2' ? MediaDiskEnum::R2->value : MediaDiskEnum::Public->value;

        if (! Storage::disk('public')->exists($path)) {
            $this->command?->warn("Cliente0MediaSeeder: falta storage/app/public/{$path}, se omite.");

            return;
        }

        if ($targetDisk === MediaDiskEnum::R2->value) {
            try {
                if (! Storage::disk('r2')->exists($path)) {
                    $mime = Storage::disk('public')->mimeType($path) ?: 'image/webp';
                    Storage::disk('r2')->put($path, Storage::disk('public')->get($path), [
                        'visibility' => 'public',
                        'mimetype' => $mime,
                        'ContentType' => $mime,
                    ]);
                }
            } catch (\Throwable $e) {
                $this->command?->warn("Cliente0MediaSeeder: no se pudo subir {$path} a R2 ({$e->getMessage()}), sembrando como disco public.");
                $targetDisk = MediaDiskEnum::Public->value;
            }
        }

        Media::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'path' => $path,
            ],
            [
                'name' => $name,
                'file_name' => $file,
                'mime_type' => Storage::disk('public')->mimeType($path) ?: 'image/webp',
                'size' => Storage::disk('public')->size($path),
                'disk' => $targetDisk,
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
