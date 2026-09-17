<?php

namespace Database\Seeders;

use App\Enums\PageTypeEnum;
use App\Enums\PublishStatusEnum;
use App\Models\Page;
use App\Models\Service;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Catálogo de Servicios de CICA360 (Cliente 0), 2026-09-11 — **2da vuelta,
 * reemplaza por completo el dataset de la 1ra vuelta** (2026-09-10, ver
 * ADR-049): esa 1ra vuelta tenía 12 servicios de ejemplo (8 migrados desde
 * `content.items` del bloque + 4 inventados a partir de rubros de
 * `Cliente0TestimonialsSeeder`, con imágenes genéricas reutilizadas
 * cíclicamente) — el Tech Lead entregó el catálogo REAL: 9 servicios con
 * título/descripción exactos y una foto dedicada por cada uno, ya subida a
 * `storage/app/public/media/` (ver `Cliente0MediaSeeder`, entradas
 * `service_*`). Se descarta el dataset anterior completo, no se combinan.
 *
 * "Seguro Financiero" (2do de la lista) hereda el contenido de ejemplo que
 * en la 1ra vuelta vivía bajo "Seguros generales" — el Tech Lead aclaró que
 * ese contenido ("pólizas patrimoniales... sin coberturas de más ni letra
 * chica") en realidad correspondía a este título ("Seguro Financiero...
 * cuidamos tu patrimonio en cada paso"), no al que tenía antes. El resto de
 * los `intro` reutiliza/adapta el mismo tono de los rubros que ya existían
 * cuando el tema coincide (contable/financiera, legal, bienes raíces,
 * asesoría estratégica) y agrega 3 rubros nuevos que no existían en la 1ra
 * vuelta (editorial, turismo, notarial) con intro propio, corto, extendiendo
 * la descripción dada por el Tech Lead sin inventar detalles no verificables.
 *
 * `countries`: "Asesoría Notarial" es el único con alcance explícito a 2
 * países ("Escribanía ágil y segura en Uruguay y Argentina" — `UY`+`AR`,
 * dicho textualmente en la descripción). El resto de rubros de alcance
 * local queda en `UY` (ver testimonios: Montevideo, Punta del Este,
 * Tacuarembó, Ciudad Vieja); "Asesoría Editorial Integral"/"Turismo y
 * Asesoría Vacacional"/"Asesoramiento Legal Integral" ("toda la región")
 * quedan sin país explícito (`[]`, "Regional/Global" per ADR-035).
 *
 * `sort_order` = índice del array, en el mismo orden entregado por el Tech
 * Lead (tabla Título/Descripción) — es el orden que usa el bloque
 * `services_grid` con `content.order: asc` (ver `upsertServiciosPage()`).
 *
 * `content.intro`: igual que la 1ra vuelta, un párrafo corto por servicio,
 * sin `offers`/`coverages`/`why_choose_us`/`tip` (opcionales, el Tech Lead
 * los completa por servicio desde Studio cuando corresponda) — **excepto
 * "Seguro Financiero"** (2026-09-14, 3ra vuelta): trae los 4 campos
 * completos a partir de las capturas reales de CICA360 (7 `offers`, 15
 * `coverages` — solo "Automotores" con el detalle real entregado, el resto
 * con 3 a 10 sub-ítems de ejemplo por rubro para demostrar el acordeón con
 * listas de distinto largo, más `why_choose_us`/`tip`). Sirve de servicio
 * de referencia; el resto se sigue completando manualmente desde Studio.
 *
 * Idempotente vía `firstOrCreate` por `[tenant_id, slug]` (2026-09-14,
 * **4ta vuelta, FIX real**: antes `updateOrCreate` — bug reportado en vivo
 * por el Tech Lead, "no veo el texto..." tras correr este seeder: pisaba
 * SILENCIOSAMENTE `title`/`subtitle`/`countries`/`content` de un servicio
 * YA EXISTENTE con los valores de este archivo cada vez que corría, sin
 * importar que ya hubiera contenido real editado a mano en Studio —
 * exactamente lo que le pasó a "Seguro Financiero": perdió su
 * `subtitle`/`countries`/`content.intro` reales, reemplazados por valores
 * de ejemplo viejos de acá. `firstOrCreate` solo CREA si el servicio no
 * existe todavía; si ya existe, no lo toca — coherente con lo que el
 * docblock de arriba ya decía sobre `offers`/`coverages`/etc. ("el Tech
 * Lead los completa por servicio desde Studio cuando corresponda"), ahora
 * también aplicado en el código para TODOS los campos, no solo esos 4).
 * `subtitle`/`countries`/`content.intro` de "Seguro Financiero" en este
 * archivo se restauraron a partir de las capturas reales de CICA360 que
 * compartió el Tech Lead, para que reflejen lo que ya tenía cargado.
 *
 * Al final se podan los registros con slugs que ya NO están en este
 * dataset (pensado originalmente para los 12 de la 1ra vuelta) — **ojo**:
 * esto SIGUE corriendo sin importar `firstOrCreate` de arriba; si en algún
 * momento se crea un servicio real en Studio con un slug que no está en
 * este array de 9, este paso lo va a borrar. No se tocó en esta vuelta
 * (el bug reportado era de sobreescritura, no de borrado) pero es un
 * riesgo latente equivalente a tener presente.
 *
 * Requiere que `Cliente0Seeder` (tenant) y `Cliente0MediaSeeder` (imágenes)
 * hayan corrido antes.
 */
class Cliente0ServicesSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'cica360')->first();

        if (! $tenant) {
            return;
        }

        // 2026-09-15: footer principal como contenido inicial (pedido del Tech Lead:
        // "agregar en el seeder como parte del contenido inicial el footer principal").
        $footerPage = Page::where('tenant_id', $tenant->id)
            ->where('type', PageTypeEnum::Footer->value)
            ->where('slug', 'footer-principal')
            ->first();

        $services = [
            [
                'title' => 'Seguridad Financiera',
                'subtitle' => 'Seguros y retiros con enfoque de protección.',
                'intro' => 'Diseñamos seguros y planes de retiro pensados para proteger tu patrimonio y el de tu familia a largo plazo, con acompañamiento personalizado en cada etapa.',
                'image_file' => 'cica360_media_service_seguridad_financiera.webp',
                'countries' => ['UY'],
            ],
            [
                'title' => 'Seguro Financiero',
                // 2026-09-14, FIX real: `subtitle`/`intro`/`countries` de
                // acá quedaron desactualizados respecto al contenido REAL
                // que el Tech Lead ya tenía cargado en Studio (bug
                // reportado en vivo: "no veo el texto..." tras correr este
                // seeder, que con `updateOrCreate` pisó silenciosamente su
                // subtítulo/banderas/intro reales con estos 3 valores
                // viejos). Restaurados acá a partir de las capturas reales
                // de CICA360 que el propio Tech Lead compartió (banner +
                // tabs) — ver `firstOrCreate` más abajo en el loop, que
                // evita que esto vuelva a pasar en el futuro.
                'subtitle' => 'Protegemos tu patrimonio, simplificamos tu gestión.',
                'intro' => 'En CICA ofrecemos coberturas generales con las mejores compañías de Argentina para que cada cliente cuente con las coberturas adecuadas, sin pagar de más. Nuestro objetivo es reducir costos fijos mensuales, mejorar las coberturas existentes y ofrecer soluciones integrales, claras y confiables para personas, profesionales y empresas.',
                'image_file' => 'cica360_media_service_seguro_financiero.webp',
                'countries' => ['AR', 'UY'],

                // 2026-09-14, pedido del Tech Lead (capturas de "Seguros
                // Financiero" en CICA360 real: banner + tabs "¿Qué
                // ofrecemos?"/"Coberturas" + "¿Por qué elegirnos?" + tip):
                // único servicio del dataset con `offers`/`coverages`/
                // `why_choose_us`/`tip` completos — sirve como demo de
                // referencia para que el Tech Lead vea el módulo funcionando
                // de punta a punta en Studio y en el front antes de cargar
                // el resto manualmente. El resto de servicios queda sin
                // estos 4 campos a propósito (ver docblock de la clase).
                'offers' => [
                    ['highlight' => 'Optimización de costos', 'text' => 'en tus pólizas actuales.'],
                    ['highlight' => 'Ampliación de coberturas', 'text' => 'según necesidades reales.'],
                    ['highlight' => 'Selección estratégica de compañías:', 'text' => 'priorizamos solidez, atención y eficacia en siniestros.'],
                    ['highlight' => 'Gestión legal ante siniestros y asesoría', 'text' => 'personalizada desde el inicio.'],
                    ['highlight' => 'Contratos verificados jurídicamente,', 'text' => 'por el Estudio Jurídico Mosquera – Perticaro & Abogados.'],
                    ['highlight' => 'Asesoramiento integral', 'text' => 'en un solo lugar para todos tus bienes asegurables.'],
                    ['highlight' => 'Sin sobrecomisiones ni letra chica:', 'text' => 'somos agentes institorios, con respaldo jurídico y autorización oficial (Patente N° 11 – SSN Argentina).'],
                ],

                // Acordeón de "Coberturas" — 15 rubros. Solo "Automotores"
                // viene con el detalle real entregado por el Tech Lead; el
                // resto se completa con 3 a 10 sub-ítems de ejemplo (rubro
                // por rubro, random en cantidad) puramente como demo para
                // probar el acordeón con listas de distinto largo — el Tech
                // Lead los reemplaza por el detalle real desde Studio.
                'coverages' => [
                    ['label' => 'Hogar', 'intro' => 'Cubrimos:', 'items' => ['Incendio y rayo', 'Robo con violencia', 'Rotura de cristales', 'Daños por agua', 'Responsabilidad civil del hogar', 'Daños eléctricos', 'Caída de rayos', 'Asistencia hogar 24 horas']],
                    ['label' => 'Automotores', 'intro' => 'Cubrimos daños por:', 'items' => ['Robo o hurto (total o parcial)', 'Incendio', 'Daños materiales al vehículo', 'Reclamaciones de terceros']],
                    ['label' => 'Vida colectivos', 'intro' => 'Incluye:', 'items' => ['Fallecimiento', 'Invalidez total y permanente', 'Renta por incapacidad']],
                    ['label' => 'Comercio e Industria', 'intro' => 'Protege:', 'items' => ['Incendio y explosión', 'Robo de mercadería', 'Rotura de maquinaria', 'Pérdida de beneficios', 'Responsabilidad civil comercial', 'Cristales y carteles']],
                    ['label' => 'ART (Riesgo de Trabajo)', 'intro' => 'Cubre:', 'items' => ['Accidentes de trabajo', 'Enfermedades profesionales', 'Prestaciones médicas', 'Indemnizaciones por incapacidad']],
                    ['label' => 'Mala praxis', 'intro' => 'Ampara frente a:', 'items' => ['Reclamos por errores profesionales', 'Gastos de defensa legal', 'Indemnizaciones a terceros']],
                    ['label' => 'Transporte', 'intro' => 'Cubrimos:', 'items' => ['Mercadería en tránsito terrestre', 'Transporte marítimo', 'Transporte aéreo', 'Robo durante el traslado', 'Daños por accidente']],
                    ['label' => 'Aeronavegación', 'intro' => 'Incluye:', 'items' => ['Casco de la aeronave', 'Responsabilidad civil frente a terceros', 'Accidentes a la tripulación', 'Pérdida total o parcial']],
                    ['label' => 'Embarcaciones de placer', 'intro' => 'Cubrimos:', 'items' => ['Casco y máquinas', 'Responsabilidad civil náutica', 'Robo total o parcial', 'Asistencia náutica', 'Accidentes a ocupantes']],
                    ['label' => 'Riesgo agrícola', 'intro' => 'Protege ante:', 'items' => ['Granizo', 'Helada', 'Incendio de cultivos', 'Sequía', 'Exceso de lluvia']],
                    ['label' => 'Seguro de incendios', 'intro' => 'Cubre:', 'items' => ['Incendio', 'Rayo', 'Explosión', 'Daños por humo']],
                    ['label' => 'Accidentes personales', 'intro' => 'Cubre:', 'items' => ['Muerte accidental', 'Invalidez permanente', 'Gastos médicos y farmacéuticos', 'Asistencia al viajero']],
                    ['label' => 'Consorcios', 'intro' => 'Incluye:', 'items' => ['Incendio del edificio', 'Responsabilidad civil del consorcio', 'Cristales de uso común', 'Ascensores', 'Robo en áreas comunes', 'Daños por agua en cañerías']],
                    ['label' => 'Seguro de caución', 'intro' => 'Disponible para:', 'items' => ['Garantía de licitación', 'Garantía de cumplimiento de contrato', 'Garantía de alquiler', 'Garantía aduanera']],
                    ['label' => 'Garantía propietaria (Uruguay)', 'intro' => 'Cubre:', 'items' => ['Falta de pago de alquiler', 'Daños a la propiedad', 'Gastos de desocupación judicial', 'Servicios impagos']],
                ],

                'why_choose_us' => [
                    'title' => '¿Por qué elegirnos?',
                    'text' => 'Porque integramos en un solo equipo lo comercial, lo técnico y lo jurídico. Trabajamos con compañías líderes en Argentina, Uruguay y la región, cuidamos cada paso y te acompañamos frente a cualquier eventualidad.',
                ],

                'tip' => [
                    'title' => 'Soluciones reales, ajustadas a tu rubro, sin letra chica.',
                    'text' => 'Velamos por tu tranquilidad y si ocurre un siniestro, no estás solo: te ayudamos con la denuncia y te respaldamos.',
                ],
            ],
            [
                'title' => 'Asesoría y Consultoría Estratégica',
                'subtitle' => 'Impulso clave para emprendedores y PyMES.',
                'intro' => 'Trabajamos junto a emprendedores y pymes en la estrategia de cada etapa: desde la puesta en marcha hasta la expansión a nuevos mercados.',
                'image_file' => 'cica360_media_service_asesoria_y_consultoria_estrategica.webp',
                'countries' => ['UY'],
            ],
            [
                'title' => 'Asesoría Contable y Financiera',
                'subtitle' => 'Gestión clara para empresas en crecimiento.',
                'intro' => 'Ordenamos la contabilidad y las obligaciones tributarias de tu empresa, con reportes claros para tomar mejores decisiones financieras.',
                'image_file' => 'cica360_media_service_asesoria_contable_y_financiera.webp',
                'countries' => ['UY'],
            ],
            [
                'title' => 'Asesoría Editorial Integral',
                'subtitle' => 'Edición, diseño y publicación de nivel profesional.',
                'intro' => 'Acompañamos todo el proceso editorial de un libro o publicación, desde la edición y el diseño hasta la publicación final, con estándares profesionales.',
                'image_file' => 'cica360_media_service_asesoria_editorial_integral.webp',
                'countries' => [],
            ],
            [
                'title' => 'Turismo y Asesoría Vacacional',
                'subtitle' => 'Pasajes, hoteles y experiencias a tu medida.',
                'intro' => 'Organizamos pasajes, hospedaje y experiencias de viaje a medida, con asesoría personalizada para que cada vacación salga como la planeaste.',
                'image_file' => 'cica360_media_service_turismo_y_asesoria_vacacional.webp',
                'countries' => [],
            ],
            [
                'title' => 'Bienes Raíces e Inversión',
                'subtitle' => 'Compra, venta y proyectos inmobiliarios seguros.',
                'intro' => 'Acompañamos la compra, venta y gestión de propiedades como inversión, con todo el proceso guiado de punta a punta.',
                'image_file' => 'cica360_media_service_bienes_raices_e_inversion.webp',
                'countries' => ['UY'],
            ],
            [
                'title' => 'Asesoramiento Legal Integral',
                'subtitle' => 'Respaldo jurídico estratégico en toda la región.',
                'intro' => 'Revisamos y redactamos contratos comerciales, y acompañamos negociaciones complejas para que cierres acuerdos sin sorpresas legales, con respaldo jurídico en toda la región.',
                'image_file' => 'cica360_media_service_asesoramiento_legal_integral.webp',
                'countries' => [],
            ],
            [
                'title' => 'Asesoría Notarial',
                'subtitle' => 'Escribanía ágil y segura en Uruguay y Argentina.',
                'intro' => 'Trámites de escribanía ágiles y seguros, con gestión de firmas y documentación legal en Uruguay y Argentina.',
                'image_file' => 'cica360_media_service_asesoria_notarial.webp',
                'countries' => ['UY', 'AR'],
            ],
        ];

        $slugs = [];

        foreach ($services as $index => $service) {
            $slug = Str::slug($service['title']);
            $slugs[] = $slug;

            // `offers`/`coverages`/`why_choose_us`/`tip` son opcionales en
            // el array de arriba (2026-09-14) — solo "Seguro Financiero" los
            // trae hoy. `array_intersect_key` los agrega a `content` nada
            // más si están presentes, sin tocar el resto de servicios.
            $content = array_merge(
                ['intro' => $service['intro']],
                array_intersect_key($service, array_flip(['offers', 'coverages', 'why_choose_us', 'tip']))
            );

            // FIX real (2026-09-14): antes `updateOrCreate` — pisaba
            // `title`/`subtitle`/`countries`/`content`/etc. de un servicio
            // YA EXISTENTE con los valores de ESTE array cada vez que el
            // seeder corría, sin importar que el Tech Lead ya hubiese
            // editado ese contenido en Studio. Bug real reportado en vivo:
            // corrió el seeder después de que este archivo sumara
            // `offers`/`coverages` a "Seguro Financiero", y perdió el
            // `subtitle`/`countries`/`content.intro` reales que ya tenía
            // cargados (quedaron pisados por los valores viejos que había
            // acá). `firstOrCreate` busca solo por `[tenant_id, slug]`: si
            // el servicio YA EXISTE, lo deja completamente intacto (no lo
            // toca); solo lo crea —con estos valores de ejemplo— la
            // PRIMERA vez, cuando todavía no existe. Mismo criterio que ya
            // documenta el docblock de la clase ("el Tech Lead los
            // completa por servicio desde Studio cuando corresponda") —
            // ahora también aplicado en el código, no solo en el
            // comentario.
            $record = Service::firstOrCreate(
                ['tenant_id' => $tenant->id, 'slug' => $slug],
                [
                    'lang_iso' => 'es',
                    'title' => $service['title'],
                    'subtitle' => $service['subtitle'],
                    'status' => PublishStatusEnum::Published->value,
                    'published_at' => now(),
                    'image_id' => Cliente0MediaSeeder::mediaId($tenant, $service['image_file']),
                    // 2026-09-14: `image_detail_id` (imagen secundaria,
                    // pensada para el header del detalle) nuevo — como
                    // contenido inicial se duplica la MISMA imagen que
                    // `image_id` (mismo `image_file`, mismo Media, mismo
                    // id) en los dos campos. No es una imagen panorámica
                    // real todavía — el Tech Lead sube una propia por
                    // servicio desde Studio cuando corresponda, mismo
                    // criterio que el resto del contenido de este seeder.
                    'image_detail_id' => Cliente0MediaSeeder::mediaId($tenant, $service['image_file']),
                    'countries' => $service['countries'],
                    'content' => $content,
                    // 2026-09-14: `properties.show_decorative_detail` en
                    // `true` explícito para las 9 filas de contenido inicial
                    // — sin esto, `properties` queda `null` (nunca se seteó
                    // acá) y el Toggle de Studio hidrata "apagado" para un
                    // registro existente (el `->default(true)` de Filament
                    // solo aplica al CREAR desde el form, no a una fila ya
                    // sembrada), aunque el frontend YA trataba "ausente"
                    // como "mostrar" (`!== false`) — mismatch confuso: el
                    // admin veía el toggle apagado pero las banderas SÍ se
                    // veían en el sitio. Con el valor explícito, Studio y el
                    // sitio quedan alineados desde el primer render. NO se
                    // agrega `header_type` acá (no fue pedido) — su default
                    // 'normal' ya coincide entre Filament y el frontend sin
                    // el mismo problema (un `Select` vacío no implica un
                    // valor "activo" visualmente engañoso como sí pasa con
                    // un `Toggle`).
                    // 2026-09-15: footer principal como contenido inicial (pedido del Tech Lead).
                    'properties' => [
                        'show_decorative_detail' => true,
                        'footer_page_id' => $footerPage?->id,
                    ],
                    'sort_order' => $index,
                ]
            );

            // 2026-09-15: si el servicio ya existía de corridas anteriores y no
            // tiene asignado `footer_page_id`, se le asocia el footer principal
            // pedido por el Tech Lead ("agregar en el seeder como parte del contenido
            // inicial el footer principal").
            if ($footerPage && empty($record->properties['footer_page_id'])) {
                $props = $record->properties ?? [];
                $props['footer_page_id'] = $footerPage->id;
                $record->properties = $props;
                $record->save();
            }

            // Asegura que image_id y image_detail_id queden asignados si estaban nulos
            $mediaId = Cliente0MediaSeeder::mediaId($tenant, $service['image_file']);
            if ($mediaId && (empty($record->image_id) || empty($record->image_detail_id))) {
                $record->image_id = $record->image_id ?? $mediaId;
                $record->image_detail_id = $record->image_detail_id ?? $mediaId;
                $record->save();
            }
        }

        // Poda el dataset de la 1ra vuelta (12 servicios de ejemplo con
        // otros slugs) — sin esto, quedarían huérfanos y visibles en el
        // catálogo público junto a los 9 reales.
        Service::where('tenant_id', $tenant->id)->whereNotIn('slug', $slugs)->delete();
    }
}
