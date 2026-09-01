<?php

namespace Database\Seeders;

use App\Enums\LanguageEnum;
use App\Enums\PublishStatusEnum;
use App\Models\Post;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Posts de prueba de CICA360 — data mínima coherente para poder probar
 * `GET /api/v1/cica360/posts` y `posts/{slug}` con contenido real
 * (ver ADR-018, punto F).
 */
class Cliente0PostsSeeder extends Seeder
{
    private const array POSTS = [
        [
            'slug' => 'como-elegir-seguro-de-vida',
            'title' => 'Cómo elegir un seguro de vida sin pagar de más',
            'subtitle' => 'Guía práctica para comparar coberturas',
            'excerpt' => 'Las variables que realmente importan al comparar pólizas de vida: cobertura, exclusiones, período de carencia y costo real a largo plazo.',
            'content' => "<p>Elegir un seguro de vida no debería ser un salto de fe. Antes de firmar, conviene comparar al menos tres variables: la cobertura real ante distintos escenarios, las exclusiones de la póliza y el costo total a lo largo del tiempo (no solo la cuota inicial).</p><p>En CICA360 acompañamos a cada cliente a leer la letra chica antes de decidir, no después.</p>",
            'seo_title' => 'Cómo elegir un seguro de vida | CICA360',
            'seo_description' => 'Guía práctica de CICA360 para comparar seguros de vida sin sorpresas: cobertura, exclusiones y costo real.',
        ],
        [
            'slug' => 'errores-comunes-en-contratos-comerciales',
            'title' => 'Los errores más comunes al firmar contratos comerciales',
            'subtitle' => 'Lo que revisamos antes de que firmes',
            'excerpt' => 'Cláusulas ambiguas, plazos mal definidos y falta de garantías: los errores que más vemos en contratos comerciales de Pymes.',
            'content' => "<p>La mayoría de los conflictos comerciales no nacen de mala fe, sino de contratos mal redactados: cláusulas ambiguas, plazos poco claros o garantías que nunca quedaron por escrito.</p><p>Nuestro equipo jurídico revisa cada contrato antes de la firma, para que la sorpresa nunca llegue después.</p>",
            'seo_title' => 'Errores comunes en contratos comerciales | CICA360',
            'seo_description' => 'Los errores más frecuentes al firmar contratos comerciales y cómo evitarlos, según el equipo jurídico de CICA360.',
        ],
        [
            'slug' => 'como-organizar-las-finanzas-de-una-pyme',
            'title' => 'Cómo organizar las finanzas de una Pyme desde el día uno',
            'subtitle' => 'Orden financiero sin depender de una gran estructura',
            'excerpt' => 'Cuatro hábitos simples de orden financiero que le ahorran dolores de cabeza a cualquier Pyme en su primer año.',
            'content' => "<p>No hace falta un departamento de finanzas para tener orden financiero. Separar cuentas personales de las del negocio, proyectar flujo de caja mensual, y revisar la carga tributaria con asesoría profesional son hábitos que cualquier Pyme puede adoptar desde el primer día.</p><p>Nuestro equipo de asesoría contable y financiera acompaña ese proceso paso a paso.</p>",
            'seo_title' => 'Finanzas de una Pyme: por dónde empezar | CICA360',
            'seo_description' => 'Hábitos simples de orden financiero para Pymes, según la asesoría contable y financiera de CICA360.',
        ],
    ];

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'cica360')->first();

        if (! $tenant) {
            return;
        }

        foreach (self::POSTS as $index => $post) {
            Post::updateOrCreate(
                ['tenant_id' => $tenant->id, 'lang_iso' => LanguageEnum::Spanish->value, 'slug' => $post['slug']],
                [
                    'title' => $post['title'],
                    'subtitle' => $post['subtitle'],
                    'excerpt' => $post['excerpt'],
                    'content' => $post['content'],
                    'status' => PublishStatusEnum::Published->value,
                    'meta' => [
                        'seo_title' => $post['seo_title'],
                        'seo_description' => $post['seo_description'],
                    ],
                    'published_at' => now()->subDays((count(self::POSTS) - $index) * 3),
                ]
            );
        }
    }
}
