<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\Testimonial;
use Illuminate\Database\Seeder;

/**
 * Testimonios / casos de éxito de CICA360 (Cliente 0), 2026-08-31.
 *
 * Antes vivían inline en `content.items` de cada bloque `testimonials`
 * dentro de `Cliente0ContentSeeder` (sin gestión centralizada, duplicados
 * entre la página "Casos de éxito" y el bloque de la home). Ahora son su
 * propio módulo (`TestimonialResource`, tabla `testimonials`) resuelto en
 * runtime contra la API pública — este seeder es la única fuente de estos
 * **12 testimonios** de ejemplo (2026-08-31, ampliado de 4 a 12 a pedido
 * del Tech Lead — "dataset de demo" con volumen real para probar el
 * filtro `limit`/`order` de los bloques, no solo el caso trivial de 3-4
 * items), `is_visible = true` en todos para que aparezcan en cualquier
 * bloque `testimonials` que los liste.
 *
 * `avatar_id` — solo 5 fotos reales existen en `storage/app/public/media/`
 * (`cica360_media_testimony-{1..5}.webp`) para 12 testimonios: se
 * REUTILIZAN a propósito (pedido explícito: "reutilizar de forma aleatoria
 * las 5 fotos"), cada testimonio con un `avatar_file` fijo elegido a mano
 * en un orden mezclado (sin dos consecutivos repitiendo la misma foto) —
 * NO se genera al azar en cada corrida (`rand()`/`Faker` sin seed) porque
 * eso rompería la idempotencia esperada de un seeder (`db:seed` repetido
 * debería dar el mismo resultado, no barajar los avatares en cada corrida).
 * Resuelto vía `Cliente0MediaSeeder::mediaId()` — mismo patrón de 2 pasos
 * que `Cliente0HomeSlidesSeeder`/`Cliente0ContentSeeder`: `Cliente0MediaSeeder`
 * siembra `media` primero (debe correr antes, ver `DatabaseSeeder`), este
 * seeder solo resuelve el id. Si el archivo no llegó a sembrarse (checkout
 * sin los assets), `mediaId()` devuelve `null` y el testimonio queda sin
 * avatar en vez de romper el seeder.
 *
 * `role` (2026-08-31, pedido del Tech Lead): el campo es opcional tanto en
 * la migración (`nullable()`) como en el form de `TestimonialResource`
 * (sin `->required()`, label ya decía "Opcional") — no hizo falta tocar
 * ninguno de los dos. Lo que sí cambió acá: los 12 testimonios de ejemplo
 * quedan con `role` en `null` a propósito, para calzar con el mockup real
 * (que solo muestra "— Nombre R.", sin cargo/empresa) y para que la línea
 * de cargo/empresa de `Testimonials.astro` (condicional: `{item.role &&
 * ...}`) se vea realmente oculta en el dataset de demo. El campo sigue
 * ahí, listo para que el Tech Lead cargue cargo+empresa reales por
 * testimonio desde Studio cuando corresponda — en ese momento la línea
 * aparece sola, sin tocar código.
 *
 * Idempotente vía `updateOrCreate` por `[tenant_id, name]`.
 *
 * Requiere que `Cliente0Seeder` (tenant) y `Cliente0MediaSeeder` (avatares)
 * hayan corrido antes.
 */
class Cliente0TestimonialsSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'cica360')->first();

        if (! $tenant) {
            return;
        }

        $testimonials = [
            [
                'name' => 'María Fernanda Silva',
                'role' => null, // antes: 'Emprendedora, Montevideo' — vacío a propósito (ver docblock), no borrado
                'quote' => 'CICA360 nos ayudó a proteger a nuestra familia y ordenar las finanzas del negocio en un mismo lugar.',
                'avatar_file' => 'cica360_media_testimony-2.webp',
            ],
            [
                'name' => 'Rodrigo Pereyra',
                'role' => null, // antes: 'Dueño de Pyme' — vacío a propósito (ver docblock), no borrado
                'quote' => 'La asesoría jurídica fue clave para cerrar un contrato comercial complejo sin sorpresas.',
                'avatar_file' => 'cica360_media_testimony-4.webp',
            ],
            [
                'name' => 'Lucía Martínez',
                'role' => null, // antes: 'Directora de instituto educativo' — vacío a propósito (ver docblock), no borrado
                'quote' => 'Nos acompañaron en la contratación de seguros institucionales con total transparencia.',
                'avatar_file' => 'cica360_media_testimony-1.webp',
            ],
            [
                'name' => 'Diego Acosta',
                'role' => null, // antes: 'Inversor inmobiliario' — vacío a propósito (ver docblock), no borrado
                'quote' => 'El equipo de bienes raíces de CICA360 encontró la oportunidad de inversión ideal para nosotros.',
                'avatar_file' => 'cica360_media_testimony-5.webp',
            ],
            [
                'name' => 'Valentina Rossi',
                'role' => null, // antes: 'Contadora, Punta del Este' — vacío a propósito (ver docblock), no borrado
                'quote' => 'El acompañamiento en la parte financiera nos permitió planificar el año con mucha más claridad que antes.',
                'avatar_file' => 'cica360_media_testimony-3.webp',
            ],
            [
                'name' => 'Martín Ibarra',
                'role' => null, // antes: 'Gerente de operaciones, industria textil' — vacío a propósito (ver docblock), no borrado
                'quote' => 'Necesitábamos pólizas a medida para varias plantas y armaron una cobertura que realmente se ajusta a nuestro riesgo.',
                'avatar_file' => 'cica360_media_testimony-1.webp',
            ],
            [
                'name' => 'Camila Fernández',
                'role' => null, // antes: 'Médica, clínica privada' — vacío a propósito (ver docblock), no borrado
                'quote' => 'La asesoría en seguros de responsabilidad civil profesional fue clara y rápida, sin vueltas.',
                'avatar_file' => 'cica360_media_testimony-4.webp',
            ],
            [
                'name' => 'Gonzalo Silveira',
                'role' => null, // antes: 'Productor agropecuario, Tacuarembó' — vacío a propósito (ver docblock), no borrado
                'quote' => 'Entendieron las particularidades del campo y nos ayudaron a asegurar maquinaria e infraestructura sin complicarnos.',
                'avatar_file' => 'cica360_media_testimony-2.webp',
            ],
            [
                'name' => 'Sofía Ramírez',
                'role' => null, // antes: 'Arquitecta, estudio propio' — vacío a propósito (ver docblock), no borrado
                'quote' => 'Nos guiaron en la compra de una propiedad para el estudio, todo el proceso fue transparente de punta a punta.',
                'avatar_file' => 'cica360_media_testimony-5.webp',
            ],
            [
                'name' => 'Federico Bentancourt',
                'role' => null, // antes: 'Comerciante, Ciudad Vieja' — vacío a propósito (ver docblock), no borrado
                'quote' => 'Simplificaron trámites que veníamos posponiendo hace años. Hoy tenemos todo en regla.',
                'avatar_file' => 'cica360_media_testimony-3.webp',
            ],
            [
                'name' => 'Agustina Correa',
                'role' => null, // antes: 'Docente universitaria' — vacío a propósito (ver docblock), no borrado
                'quote' => 'Me ayudaron a resolver un tema patrimonial que llevaba tiempo sin resolver, con mucha paciencia para explicar cada paso.',
                'avatar_file' => 'cica360_media_testimony-2.webp',
            ],
            [
                'name' => 'Nicolás Etchegaray',
                'role' => null, // antes: 'Gerente de RRHH, empresa logística' — vacío a propósito (ver docblock), no borrado
                'quote' => 'Implementamos beneficios de seguro de salud para todo el equipo con un proceso simple y bien acompañado.',
                'avatar_file' => 'cica360_media_testimony-4.webp',
            ],
        ];

        foreach ($testimonials as $index => $testimonial) {
            Testimonial::updateOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $testimonial['name']],
                [
                    'role' => $testimonial['role'],
                    'quote' => $testimonial['quote'],
                    'avatar_id' => Cliente0MediaSeeder::mediaId($tenant, $testimonial['avatar_file']),
                    'is_visible' => true,
                    'sort_order' => $index,
                ]
            );
        }
    }
}
