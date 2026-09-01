<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Orden obligatorio: los seeders de plataforma/catálogo (planes,
     * módulos, form field definitions) van primero porque `Cliente0Seeder`
     * los necesita para asociar la suscripción, activar módulos, etc.
     * `Cliente0MediaSeeder` (2026-08-31, patrón nuevo: assets iniciales
     * commiteados en `storage/app/public/media/` + sembrados a la tabla
     * `media` vía seeder, en vez de subirlos a mano por Studio) corre
     * DESPUÉS de `Cliente0Seeder` (necesita el tenant) y ANTES de
     * `Cliente0ContentSeeder` y `Cliente0HomeSlidesSeeder`, porque ambos
     * resuelven FKs de imagen (`content.media_id` de los bloques Split,
     * `image_desktop_id`/etc. de las slides) contra los `Media` ya
     * sembrados vía `Cliente0MediaSeeder::mediaId()`. `Cliente0TestimonialsSeeder`
     * (2026-08-31, módulo propio de testimonios — ya no viven inline en
     * `content.items` del bloque) y `Cliente0ServicesSeeder` (2026-08-31,
     * módulo propio de servicios — catálogo + detalle, ver ADR) corren
     * junto a `Cliente0MediaSeeder`: sin dependencia estricta con
     * `Cliente0ContentSeeder` (ninguno de los dos depende de que las
     * páginas ya existan), pero agrupados ahí por prolijidad — sirven data
     * propia que el resto del contenido puede llegar a referenciar después
     * (ej. un futuro bloque que liste servicios reales, mismo patrón ya
     * resuelto para `testimonials`). `Cliente0ContentSeeder` (páginas/menú/
     * form) corre antes que `Cliente0HomeSlidesSeeder` para que los CTA de
     * las slides puedan resolver a páginas reales (contacto/servicios) en
     * vez de caer al placeholder `#`. Todos los seeders son idempotentes
     * (`firstOrCreate`/`updateOrCreate`), seguros de re-ejecutar en
     * desarrollo.
     */
    public function run(): void
    {
        $this->call([
            PlanSeeder::class,
            ModuleSeeder::class,
            FormFieldDefinitionSeeder::class,
            PlatformSeeder::class,
            Cliente0Seeder::class,
            Cliente0MediaSeeder::class,
            Cliente0TestimonialsSeeder::class,
            Cliente0ServicesSeeder::class,
            Cliente0ContentSeeder::class,
            Cliente0HomeSlidesSeeder::class,
            Cliente0PostsSeeder::class,
        ]);
    }
}
