<?php

namespace Database\Seeders;

use App\Enums\PublishStatusEnum;
use App\Models\Service;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Catálogo de Servicios de CICA360 (Cliente 0), 2026-08-31 — ver ADR
 * correspondiente en `docs/context/DECISIONS.md`.
 *
 * El Tech Lead compartió 2 capturas: la grilla del catálogo "Servicios"
 * (9 cards) y el detalle completo de una de ellas ("Seguros generales":
 * banner, intro, tabs "¿Qué ofrecemos?"/"Coberturas", "¿Por qué
 * elegirnos?", tip de ayuda). De las 9 cards de la grilla, solo **7 títulos
 * son distintos** — "Asesoría Comercial y Consultoría Estratégica" y
 * "Asesoría Contable y Financiera" aparecen duplicadas (misma card 2 veces,
 * mismo texto exacto) para rellenar una grilla de demo 3x3, no como 2
 * servicios reales adicionales — se sembraron primero los 7 servicios
 * únicos de la captura (ver ADR-034), sin crear contenido duplicado.
 *
 * Solo "Seguros generales" tenía contenido de detalle real y completo en
 * las capturas — se sembró TAL CUAL (texto verbatim de la captura, incluida
 * la mención al Estudio Jurídico Mosquera – Perticaro & Abogados y la
 * Patente N° 11 – SSN Argentina). Los otros 6 servicios de la captura no
 * tenían detalle visible más allá del título/subtítulo de su card — se
 * redactó contenido de ejemplo razonable en el mismo tono/estructura
 * (intro + "qué ofrecemos" + "coberturas"/incluye + "por qué elegirnos" +
 * tip), a revisar y reemplazar por el Tech Lead con la copy real cuando la
 * tenga.
 *
 * **2026-08-31, ampliación a 12** — pedido explícito del Tech Lead
 * ("generar 12 servicios"), mismo criterio ya usado para ampliar
 * Testimonios de 4 a 12: se agregaron 5 servicios más (Seguros de Vida y
 * Salud, Recursos Humanos y Gestión de Nómina, Comercio Exterior y
 * Aduanas, Seguros Empresariales y Riesgos Corporativos, Turismo y
 * Asistencia al Viajero) sin captura de mockup — contenido redactado desde
 * cero en el mismo tono/estructura, dentro del rubro real de CICA360
 * (seguros/jurídico/contable), también a revisar por el Tech Lead.
 *
 * `countries` es una mejor-estimación (para los 7 originales, de las
 * banderas visibles en las capturas; para los 5 nuevos, una estimación
 * razonable según el tipo de servicio) — ajustar si no coincide con la
 * intención real.
 *
 * `image_id` queda `null` en los 12 — "en un momento genero las imágenes"
 * (el Tech Lead las va a subir después vía Studio), mismo criterio que el
 * resto del contenido de Cliente 0 sin media real todavía.
 *
 * Idempotente vía `updateOrCreate` por `[tenant_id, lang_iso, slug]`.
 *
 * Requiere que `Cliente0Seeder` (tenant) haya corrido antes.
 */
class Cliente0ServicesSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'cica360')->first();

        if (! $tenant) {
            return;
        }

        $services = [
            [
                'title' => 'Seguros y fondos de inversión en EEUU',
                'subtitle' => 'Seguros y Fondos de Retiro en el Sistema Americano',
                'countries' => ['GLOBAL'],
                'content' => [
                    'intro' => 'Asesoramos a personas y familias de toda la región en la contratación de seguros de vida y fondos de inversión bajo el sistema financiero estadounidense, con compañías de primer nivel y respaldo internacional. Ideal para quienes buscan diversificar su patrimonio fuera de la volatilidad regional.',
                    'offers' => [
                        ['highlight' => 'Diversificación real', 'text' => 'de tu patrimonio en dólares, fuera del sistema financiero local.'],
                        ['highlight' => 'Compañías de primer nivel,', 'text' => 'con calificación internacional y trayectoria comprobada.'],
                        ['highlight' => 'Planificación de retiro', 'text' => 'con fondos de inversión a tu medida, según tu horizonte y perfil de riesgo.'],
                        ['highlight' => 'Seguros de vida universal', 'text' => 'con componente de ahorro e inversión.'],
                        ['highlight' => 'Acompañamiento en español,', 'text' => 'sin necesidad de residencia ni cuenta bancaria en Estados Unidos.'],
                        ['highlight' => 'Revisión periódica', 'text' => 'de la cartera junto a tu asesor asignado.'],
                    ],
                    'coverages' => [
                        ['label' => 'Seguros de vida universal indexado (IUL)'],
                        ['label' => 'Fondos de inversión y anualidades'],
                        ['label' => 'Planes de retiro individuales'],
                        ['label' => 'Seguros de vida a término (Term Life)'],
                        ['label' => 'Educación financiera para hijos', 'intro' => 'Incluye:', 'items' => ['Planes de ahorro educativo', 'Fondos indexados a largo plazo']],
                    ],
                    'why_choose_us' => [
                        'title' => '¿Por qué elegirnos?',
                        'text' => 'Porque combinamos el acceso al sistema financiero norteamericano con acompañamiento cercano en español, sin la letra chica que suele acompañar este tipo de productos.',
                    ],
                    'tip' => [
                        'title' => 'Tu patrimonio, protegido más allá de las fronteras.',
                        'text' => 'Te ayudamos a entender cada producto antes de firmar, en tu idioma y a tu ritmo.',
                    ],
                ],
            ],
            [
                'title' => 'Seguros generales',
                'subtitle' => 'Protegemos tu patrimonio, simplificamos tu gestión.',
                'countries' => ['AR', 'UY'],
                'content' => [
                    // Verbatim de la captura del Tech Lead — ver docblock de la clase.
                    'intro' => 'En CICA ofrecemos coberturas generales con las mejores compañías de Argentina para que cada cliente cuente con las coberturas adecuadas, sin pagar de más. Nuestro objetivo es reducir costos fijos mensuales, mejorar las coberturas existentes y ofrecer soluciones integrales, claras y confiables para personas, profesionales y empresas.',
                    'offers' => [
                        ['highlight' => 'Optimización de costos', 'text' => 'en tus pólizas actuales.'],
                        ['highlight' => 'Ampliación de coberturas', 'text' => 'según necesidades reales.'],
                        ['highlight' => 'Selección estratégica de compañías:', 'text' => 'priorizamos solidez, atención y eficacia en siniestros.'],
                        ['highlight' => 'Gestión legal ante siniestros y asesoría', 'text' => 'personalizada desde el inicio.'],
                        ['highlight' => 'Contratos verificados jurídicamente,', 'text' => 'por el Estudio Jurídico Mosquera – Perticaro & Abogados.'],
                        ['highlight' => 'Asesoramiento integral', 'text' => 'en un solo lugar para todos tus bienes asegurables.'],
                        ['highlight' => 'Sin sobrecomisiones ni letra chica:', 'text' => 'somos agentes institorios, con respaldo jurídico y autorización oficial (Patente N° 11 – SSN Argentina).'],
                    ],
                    'coverages' => [
                        ['label' => 'Hogar'],
                        ['label' => 'Automotores', 'intro' => 'Cubrimos daños por:', 'items' => ['Robo o hurto (total o parcial)', 'Incendio', 'Daños materiales al vehículo', 'Reclamaciones de terceros']],
                        ['label' => 'Vida colectivos'],
                        ['label' => 'Comercio e Industria'],
                        ['label' => 'ART (Riesgo de Trabajo)'],
                        ['label' => 'Mala praxis'],
                        ['label' => 'Transporte'],
                        ['label' => 'Aeronavegación'],
                        ['label' => 'Embarcaciones de placer'],
                        ['label' => 'Riesgo agrícola'],
                        ['label' => 'Seguro de incendios'],
                        ['label' => 'Accidentes personales'],
                        ['label' => 'Consorcios'],
                        ['label' => 'Seguro de caución'],
                        ['label' => 'Garantía propietaria (Uruguay)'],
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
            ],
            [
                'title' => 'Asesoría Comercial y Consultoría Estratégica',
                'subtitle' => 'para Emprendedores y Pymes',
                'countries' => ['UY', 'PY'],
                'content' => [
                    'intro' => 'Acompañamos a emprendedores y pequeñas y medianas empresas en la toma de decisiones estratégicas, desde la constitución del negocio hasta su expansión regional, con foco en resultados concretos y sostenibles.',
                    'offers' => [
                        ['highlight' => 'Diagnóstico comercial', 'text' => 'para identificar oportunidades de crecimiento reales.'],
                        ['highlight' => 'Planificación estratégica', 'text' => 'a corto y mediano plazo, con objetivos medibles.'],
                        ['highlight' => 'Acompañamiento en la constitución', 'text' => 'de sociedades y estructuras societarias.'],
                        ['highlight' => 'Negociación de contratos comerciales', 'text' => 'con respaldo jurídico incluido.'],
                        ['highlight' => 'Mentoría continua', 'text' => 'para founders y equipos de dirección.'],
                        ['highlight' => 'Conexión con la red de contactos', 'text' => 'de CICA360 en la región.'],
                    ],
                    'coverages' => [
                        ['label' => 'Plan de negocio y modelo comercial'],
                        ['label' => 'Estructuración societaria', 'intro' => 'Incluye:', 'items' => ['Constitución de sociedades', 'Pactos de socios', 'Reorganizaciones societarias']],
                        ['label' => 'Expansión regional'],
                        ['label' => 'Consultoría en pricing y rentabilidad'],
                        ['label' => 'Due diligence comercial'],
                    ],
                    'why_choose_us' => [
                        'title' => '¿Por qué elegirnos?',
                        'text' => 'Porque entendemos la realidad de las pymes de la región: recursos acotados, decisiones urgentes y la necesidad de un socio, no solo un consultor.',
                    ],
                    'tip' => [
                        'title' => 'Crecer con cabeza fría, sin perder velocidad.',
                        'text' => 'Te acompañamos en cada decisión clave, con la misma cercanía de siempre.',
                    ],
                ],
            ],
            [
                'title' => 'Seguros Jurídicos',
                'subtitle' => 'Protegemos tu patrimonio, simplificamos tu gestión',
                'countries' => ['UY', 'AR', 'PY'],
                'content' => [
                    'intro' => 'Combinamos seguros y asesoría legal en un mismo servicio, para que cuentes con protección patrimonial y respaldo jurídico ante cualquier eventualidad, sin tener que coordinar entre distintos proveedores.',
                    'offers' => [
                        ['highlight' => 'Cobertura legal integral,', 'text' => 'con acceso directo a nuestro estudio jurídico asociado.'],
                        ['highlight' => 'Defensa ante siniestros', 'text' => 'y reclamos de terceros.'],
                        ['highlight' => 'Redacción y revisión de contratos', 'text' => 'antes de firmarlos, no después de un problema.'],
                        ['highlight' => 'Asesoría en conflictos comerciales', 'text' => 'con estrategia clara desde el primer contacto.'],
                        ['highlight' => 'Gestión de trámites', 'text' => 'ante organismos públicos y compañías aseguradoras.'],
                    ],
                    'coverages' => [
                        ['label' => 'Responsabilidad civil profesional'],
                        ['label' => 'Protección jurídica patrimonial'],
                        ['label' => 'Defensa penal', 'intro' => 'Cubrimos:', 'items' => ['Delitos culposos de tránsito', 'Defensa en accidentes laborales']],
                        ['label' => 'Consultas legales ilimitadas'],
                        ['label' => 'Mediación y arbitraje'],
                    ],
                    'why_choose_us' => [
                        'title' => '¿Por qué elegirnos?',
                        'text' => 'Porque el respaldo jurídico no llega después del problema, sino desde el primer día del contrato.',
                    ],
                    'tip' => [
                        'title' => 'Sin sorpresas legales, sin letra chica.',
                        'text' => 'Revisamos cada cláusula antes de que la firmes, no después de que te afecte.',
                    ],
                ],
            ],
            [
                'title' => 'Asesoría Contable y Financiera',
                'subtitle' => 'Profesionales contables certificados',
                'countries' => ['UY', 'AR', 'EC'],
                'content' => [
                    'intro' => 'Ponemos a disposición un equipo de contadores y asesores financieros certificados para ordenar tus finanzas personales o las de tu empresa, con reportes claros y decisiones basadas en datos reales.',
                    'offers' => [
                        ['highlight' => 'Contadores certificados,', 'text' => 'con experiencia en normativa local y regional.'],
                        ['highlight' => 'Reportes financieros claros,', 'text' => 'sin jerga innecesaria.'],
                        ['highlight' => 'Planificación tributaria', 'text' => 'para optimizar la carga impositiva de forma legal.'],
                        ['highlight' => 'Gestión de nómina y liquidaciones', 'text' => 'para empresas de cualquier tamaño.'],
                        ['highlight' => 'Presupuestos y proyecciones', 'text' => 'para tomar decisiones con anticipación.'],
                    ],
                    'coverages' => [
                        ['label' => 'Contabilidad mensual y balances'],
                        ['label' => 'Liquidación de impuestos'],
                        ['label' => 'Auditoría interna', 'intro' => 'Incluye:', 'items' => ['Revisión de procesos contables', 'Detección de desvíos financieros']],
                        ['label' => 'Planificación financiera personal'],
                        ['label' => 'Asesoría para inversores extranjeros'],
                    ],
                    'why_choose_us' => [
                        'title' => '¿Por qué elegirnos?',
                        'text' => 'Porque cada número que te mostramos está respaldado por un profesional certificado, no por una planilla genérica.',
                    ],
                    'tip' => [
                        'title' => 'Números claros, decisiones más simples.',
                        'text' => 'Te explicamos cada reporte en un lenguaje que realmente entiendas.',
                    ],
                ],
            ],
            [
                'title' => 'Educación a Distancia',
                'subtitle' => 'Seguros y programas para instituciones educativas',
                'countries' => ['UY'],
                'content' => [
                    'intro' => 'Trabajamos junto a institutos y centros educativos que dictan formación a distancia, ofreciendo coberturas de seguros institucionales y asesoría para la gestión administrativa del centro.',
                    'offers' => [
                        ['highlight' => 'Seguros institucionales', 'text' => 'a medida de institutos y academias.'],
                        ['highlight' => 'Cobertura de accidentes', 'text' => 'para alumnos y personal docente.'],
                        ['highlight' => 'Asesoría administrativa', 'text' => 'para la gestión diaria del centro educativo.'],
                        ['highlight' => 'Respaldo jurídico', 'text' => 'ante reclamos de alumnos o familias.'],
                        ['highlight' => 'Acompañamiento en la transición', 'text' => 'hacia modelos de educación híbrida o 100% a distancia.'],
                    ],
                    'coverages' => [
                        ['label' => 'Seguro de responsabilidad civil institucional'],
                        ['label' => 'Accidentes personales de alumnos'],
                        ['label' => 'Seguro de infraestructura y equipamiento'],
                        ['label' => 'Asesoría legal en contratos con docentes'],
                    ],
                    'why_choose_us' => [
                        'title' => '¿Por qué elegirnos?',
                        'text' => 'Porque conocemos las particularidades del sector educativo y armamos coberturas que realmente aplican a su realidad.',
                    ],
                    'tip' => [
                        'title' => 'Tranquilidad para enseñar, sin distracciones administrativas.',
                        'text' => 'Nos ocupamos de la parte legal y de seguros para que el centro se enfoque en educar.',
                    ],
                ],
            ],
            [
                'title' => 'Bienes Raíces',
                'subtitle' => 'Inversión inmobiliaria con respaldo integral',
                'countries' => ['UY', 'AR'],
                'content' => [
                    'intro' => 'Acompañamos a inversores y compradores en cada etapa de una operación inmobiliaria, desde la búsqueda de la oportunidad hasta el cierre de la escritura, con respaldo jurídico y financiero en todo el proceso.',
                    'offers' => [
                        ['highlight' => 'Búsqueda de oportunidades', 'text' => 'de inversión ajustadas a tu perfil y presupuesto.'],
                        ['highlight' => 'Due diligence legal', 'text' => 'de cada propiedad antes de avanzar.'],
                        ['highlight' => 'Asesoría en financiamiento', 'text' => 'y estructuras de compra.'],
                        ['highlight' => 'Gestión de escrituración', 'text' => 'de punta a punta, con estudio jurídico asociado.'],
                        ['highlight' => 'Garantía propietaria', 'text' => 'para operaciones de alquiler sin depósito en efectivo.'],
                    ],
                    'coverages' => [
                        ['label' => 'Compra y venta de propiedades'],
                        ['label' => 'Garantía propietaria (Uruguay)'],
                        ['label' => 'Seguro de vivienda', 'intro' => 'Cubrimos:', 'items' => ['Incendio y daños estructurales', 'Robo o hurto', 'Responsabilidad civil del propietario']],
                        ['label' => 'Asesoría en inversión inmobiliaria'],
                    ],
                    'why_choose_us' => [
                        'title' => '¿Por qué elegirnos?',
                        'text' => 'Porque acompañamos toda la operación con un mismo equipo: comercial, legal y de seguros, sin que tengas que coordinar entre varios proveedores.',
                    ],
                    'tip' => [
                        'title' => 'Invertir en ladrillos, sin sorpresas de por medio.',
                        'text' => 'Revisamos cada detalle legal antes de que pongas un peso.',
                    ],
                ],
            ],

            // 2026-08-31 — 5 servicios nuevos (8 a 12), pedido explícito del
            // Tech Lead ("generar 12 servicios", mismo criterio que se usó
            // para ampliar Testimonios de 4 a 12). No hay captura de mockup
            // para estos — contenido redactado en el mismo tono/estructura
            // que los 7 anteriores, dentro del rubro real de CICA360
            // (seguros/jurídico/contable), a revisar y reemplazar por el
            // Tech Lead con la copy real cuando la tenga.
            [
                'title' => 'Seguros de Vida y Salud',
                'subtitle' => 'Protección para vos y tu familia',
                'countries' => ['UY', 'AR'],
                'content' => [
                    'intro' => 'Diseñamos coberturas de vida y salud a medida de cada familia, comparando entre las principales compañías de la región para conseguir el mejor equilibrio entre cobertura real y costo mensual.',
                    'offers' => [
                        ['highlight' => 'Comparativa entre compañías,', 'text' => 'sin costo y sin compromiso, antes de contratar.'],
                        ['highlight' => 'Cobertura de salud complementaria', 'text' => 'a mutualistas y sistemas públicos.'],
                        ['highlight' => 'Seguros de vida con capital asegurado', 'text' => 'ajustado a tus responsabilidades familiares.'],
                        ['highlight' => 'Gestión de siniestros y reembolsos', 'text' => 'para que no pierdas tiempo con trámites.'],
                        ['highlight' => 'Revisión anual de la póliza,', 'text' => 'para que siempre pagues lo justo.'],
                    ],
                    'coverages' => [
                        ['label' => 'Seguro de vida individual y familiar'],
                        ['label' => 'Salud complementaria', 'intro' => 'Incluye:', 'items' => ['Órdenes y tickets moderadores', 'Internación en sanatorios de primer nivel', 'Medicamentos de alto costo']],
                        ['label' => 'Invalidez y enfermedades graves'],
                        ['label' => 'Sepelio'],
                        ['label' => 'Maternidad y pediatría'],
                    ],
                    'why_choose_us' => [
                        'title' => '¿Por qué elegirnos?',
                        'text' => 'Porque comparamos de forma objetiva entre compañías — no vendemos un solo producto, buscamos el que mejor se ajusta a tu situación real.',
                    ],
                    'tip' => [
                        'title' => 'La salud de tu familia, sin pagar de más.',
                        'text' => 'Te mostramos las opciones reales del mercado, en un lenguaje simple.',
                    ],
                ],
            ],
            [
                'title' => 'Recursos Humanos y Gestión de Nómina',
                'subtitle' => 'Simplificá la gestión de tu equipo',
                'countries' => ['UY', 'PY'],
                'content' => [
                    'intro' => 'Ofrecemos gestión integral de recursos humanos para pymes que necesitan profesionalizar su área de personal sin sumar una estructura interna grande — desde la liquidación de sueldos hasta la selección de talento.',
                    'offers' => [
                        ['highlight' => 'Liquidación de sueldos y jornales,', 'text' => 'con cumplimiento normativo al día.'],
                        ['highlight' => 'Selección de personal', 'text' => 'con procesos ajustados a cada perfil buscado.'],
                        ['highlight' => 'Diseño de políticas internas', 'text' => 'de RRHH, ausentismo y beneficios.'],
                        ['highlight' => 'Gestión de altas, bajas y trámites', 'text' => 'ante los organismos correspondientes.'],
                        ['highlight' => 'Asesoría en desvinculaciones', 'text' => 'con respaldo legal para minimizar riesgos.'],
                    ],
                    'coverages' => [
                        ['label' => 'Liquidación mensual de nómina'],
                        ['label' => 'Reclutamiento y selección'],
                        ['label' => 'Clima organizacional y capacitación'],
                        ['label' => 'Auditoría de legajos', 'intro' => 'Revisamos:', 'items' => ['Contratos de trabajo', 'Documentación obligatoria', 'Riesgos laborales pendientes']],
                    ],
                    'why_choose_us' => [
                        'title' => '¿Por qué elegirnos?',
                        'text' => 'Porque entendemos que el equipo es el activo más importante de una pyme — cuidamos los procesos de RRHH con el mismo rigor que un estudio jurídico.',
                    ],
                    'tip' => [
                        'title' => 'Un equipo bien gestionado, un negocio más tranquilo.',
                        'text' => 'Nos ocupamos de la gestión de personas para que vos te enfoques en crecer.',
                    ],
                ],
            ],
            [
                'title' => 'Comercio Exterior y Aduanas',
                'subtitle' => 'Importá y exportá con respaldo integral',
                'countries' => ['UY', 'AR', 'PY'],
                'content' => [
                    'intro' => 'Acompañamos a empresas que importan o exportan en la región, con asesoría aduanera, seguros de transporte internacional y gestión de la documentación necesaria para operar sin contratiempos.',
                    'offers' => [
                        ['highlight' => 'Asesoría aduanera', 'text' => 'para clasificación arancelaria y regímenes especiales.'],
                        ['highlight' => 'Seguro de carga internacional,', 'text' => 'terrestre, marítimo y aéreo.'],
                        ['highlight' => 'Gestión de documentación', 'text' => 'para despachantes y organismos de control.'],
                        ['highlight' => 'Acompañamiento en negociaciones', 'text' => 'con proveedores y compradores extranjeros.'],
                        ['highlight' => 'Cobertura ante siniestros de carga', 'text' => 'con gestión completa del reclamo.'],
                    ],
                    'coverages' => [
                        ['label' => 'Seguro de transporte de mercaderías'],
                        ['label' => 'Responsabilidad civil del transportista'],
                        ['label' => 'Asesoría en clasificación arancelaria'],
                        ['label' => 'Gestión de siniestros de carga', 'intro' => 'Incluye:', 'items' => ['Robo o pérdida de mercadería', 'Daños por manipulación o transporte']],
                    ],
                    'why_choose_us' => [
                        'title' => '¿Por qué elegirnos?',
                        'text' => 'Porque conocemos la operativa real del comercio exterior en la región, no solo la teoría — y eso se traduce en menos demoras y menos sorpresas.',
                    ],
                    'tip' => [
                        'title' => 'Tu mercadería, cubierta en cada frontera.',
                        'text' => 'Te acompañamos desde la cotización hasta que la carga llega a destino.',
                    ],
                ],
            ],
            [
                'title' => 'Seguros Empresariales y Riesgos Corporativos',
                'subtitle' => 'Protección integral para tu empresa',
                'countries' => ['UY', 'AR', 'EC'],
                'content' => [
                    'intro' => 'Diseñamos programas de seguros corporativos a medida de cada empresa, desde pymes hasta grupos con operación regional, con foco en identificar y cubrir los riesgos reales del negocio.',
                    'offers' => [
                        ['highlight' => 'Diagnóstico de riesgos', 'text' => 'específico para tu rubro y operación.'],
                        ['highlight' => 'Programas de seguros multiramo,', 'text' => 'gestionados desde un solo punto de contacto.'],
                        ['highlight' => 'Cobertura de directores y gerentes (D&O),', 'text' => 'ante reclamos por decisiones de gestión.'],
                        ['highlight' => 'Seguro de responsabilidad civil empresarial,', 'text' => 'frente a terceros y clientes.'],
                        ['highlight' => 'Gestión centralizada de siniestros', 'text' => 'para toda la operación de la empresa.'],
                    ],
                    'coverages' => [
                        ['label' => 'Todo riesgo operativo e industrial'],
                        ['label' => 'Responsabilidad civil empresarial'],
                        ['label' => 'Directores y gerentes (D&O)'],
                        ['label' => 'Interrupción de negocio', 'intro' => 'Cubre:', 'items' => ['Pérdida de ingresos por siniestro', 'Costos fijos durante la interrupción']],
                        ['label' => 'Fidelidad de empleados'],
                    ],
                    'why_choose_us' => [
                        'title' => '¿Por qué elegirnos?',
                        'text' => 'Porque diseñamos el programa de seguros a partir de un diagnóstico real de tu negocio, no de una póliza estándar armada para cualquier empresa.',
                    ],
                    'tip' => [
                        'title' => 'Tu empresa, protegida ante lo que realmente importa.',
                        'text' => 'Revisamos anualmente el programa junto a vos, ajustándolo a cómo crece tu negocio.',
                    ],
                ],
            ],
            [
                'title' => 'Turismo y Asistencia al Viajero',
                'subtitle' => 'Viajá tranquilo, a cualquier destino',
                'countries' => ['GLOBAL'],
                'content' => [
                    'intro' => 'Ofrecemos asistencia al viajero y seguros de turismo para viajes de placer, estudio o trabajo, con cobertura médica internacional y asistencia las 24 horas en cualquier parte del mundo.',
                    'offers' => [
                        ['highlight' => 'Cobertura médica internacional,', 'text' => 'con red de prestadores en los principales destinos.'],
                        ['highlight' => 'Asistencia 24/7', 'text' => 'en español, ante cualquier emergencia en el viaje.'],
                        ['highlight' => 'Cobertura de equipaje y documentación', 'text' => 'ante pérdida o robo.'],
                        ['highlight' => 'Planes por viaje o anuales,', 'text' => 'según tu frecuencia de viaje.'],
                        ['highlight' => 'Asesoría en visados y requisitos', 'text' => 'de entrada según destino.'],
                    ],
                    'coverages' => [
                        ['label' => 'Asistencia médica y odontológica de urgencia'],
                        ['label' => 'Cancelación e interrupción de viaje'],
                        ['label' => 'Pérdida o demora de equipaje'],
                        ['label' => 'Repatriación sanitaria', 'intro' => 'Incluye:', 'items' => ['Traslado a centro médico de referencia', 'Repatriación en caso de fallecimiento']],
                    ],
                    'why_choose_us' => [
                        'title' => '¿Por qué elegirnos?',
                        'text' => 'Porque te ayudamos a elegir el plan según tu destino y tipo de viaje real, no una cobertura genérica que no se ajusta a tu itinerario.',
                    ],
                    'tip' => [
                        'title' => 'El mundo, sin preocupaciones de por medio.',
                        'text' => 'Estamos disponibles antes, durante y después de tu viaje.',
                    ],
                ],
            ],
        ];

        foreach ($services as $index => $service) {
            $slug = Str::slug($service['title']);

            Service::updateOrCreate(
                ['tenant_id' => $tenant->id, 'lang_iso' => 'es', 'slug' => $slug],
                [
                    'title' => $service['title'],
                    'subtitle' => $service['subtitle'],
                    'status' => PublishStatusEnum::Published->value,
                    'countries' => $service['countries'],
                    'content' => $service['content'],
                    'sort_order' => $index,
                    'published_at' => now(),
                ]
            );
        }
    }
}
