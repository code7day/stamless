<?php

namespace Database\Seeders;

use App\Enums\BlockTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\MenuItemTypeEnum;
use App\Enums\PageTypeEnum;
use App\Enums\PublishStatusEnum;
use App\Models\Block;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldDefinition;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Slider;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Contenido mínimo real de CICA360 (Cliente 0): páginas + bloques, menú
 * principal y formulario de contacto. Objetivo: tener data no vacía para
 * probar el API v1 (ADR-016) y para que el frontend tenga algo real que
 * consumir. Todo idempotente vía `updateOrCreate`; los bloques e items de
 * menú "extra" de una corrida anterior se podan (`sort_order >= count`)
 * para que el seeder también sea seguro si se reduce el contenido.
 *
 * Requiere que `Cliente0Seeder` haya corrido antes (tenant, slider
 * placeholder `home`). No depende de archivos de media reales: todos los
 * `*_id` de imagen dentro de `content`/`links` quedan `null` a propósito
 * (ver bloqueador de Cloudflare R2 en CURRENT_STATE.md).
 */
class Cliente0ContentSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'cica360')->first();

        if (! $tenant) {
            return;
        }

        $form = $this->upsertContactForm($tenant);

        $pages = [
            'contacto' => $this->upsertContactoPage($tenant, $form),
            'sobre-cica' => $this->upsertSobreCicaPage($tenant),
            'servicios' => $this->upsertServiciosPage($tenant),
            'casos-de-exito' => $this->upsertCasosDeExitoPage($tenant),
        ];
        $pages['home'] = $this->upsertHomePage($tenant, $pages);

        $this->upsertMainMenu($tenant, $pages);
    }

    /**
     * Form "Contacto principal" + sus 4 campos (name/email/phone/message),
     * reutilizando las `FormFieldDefinition` globales sembradas por
     * `FormFieldDefinitionSeeder` (no se inventan definitions nuevas).
     */
    private function upsertContactForm(Tenant $tenant): Form
    {
        $form = Form::updateOrCreate(
            ['tenant_id' => $tenant->id, 'lang_iso' => LanguageEnum::Spanish->value, 'slug' => 'contacto'],
            [
                'name' => 'Contacto principal',
                'description' => 'Formulario de contacto general del sitio de CICA360.',
                'notification_email' => 'owner@cica360.com',
                'notification_subject' => 'Nuevo contacto desde el sitio web',
                'send_copy_to_submitter' => false,
                'success_message' => 'Gracias por escribirnos. Te contactaremos a la brevedad.',
                'is_active' => true,
                'enable_honeypot' => true,
                'enable_recaptcha' => false,
            ]
        );

        foreach (['name', 'email', 'phone', 'message'] as $sortOrder => $key) {
            $definition = FormFieldDefinition::where('key', $key)->first();

            if (! $definition) {
                continue;
            }

            FormField::updateOrCreate(
                ['form_id' => $form->id, 'name' => $definition->key],
                [
                    'field_definition_id' => $definition->id,
                    'label' => $definition->label,
                    'type' => $definition->type->value,
                    'is_required' => $definition->default_required,
                    'is_encrypted' => $definition->default_encrypted,
                    'sort_order' => $sortOrder,
                    'is_active' => true,
                ]
            );
        }

        return $form;
    }

    private function upsertContactoPage(Tenant $tenant, Form $form): Page
    {
        $page = $this->upsertPage($tenant, 'contacto', 'Contacto', 'Conversemos sobre tu próximo paso', [
            'seo_title' => 'Contacto | CICA360',
            'seo_description' => 'Ponte en contacto con el equipo de CICA360 para asesoría en seguros, finanzas, temas jurídicos y bienes raíces.',
        ]);

        $this->syncBlocks($page, $tenant, [
            [
                'type' => BlockTypeEnum::RichText,
                'title' => 'Hablemos',
                'content' => ['body' => '<p>Escríbenos y un asesor de CICA360 se pondrá en contacto contigo a la brevedad para conversar sobre seguros, finanzas, asesoría legal o bienes raíces.</p>'],
                'properties' => [
                    'text_align' => 'left',
                    'content_width' => 'narrow',
                    'padding_y' => 'sm',
                    'show_scroll_indicator' => false,
                    'show_link' => false,
                ],
            ],
            [
                'type' => BlockTypeEnum::ContactForm,
                'title' => 'Envíanos tu consulta',
                'content' => [
                    'form_id' => $form->id,
                    'intro' => 'Completá el formulario y te responderemos en menos de 24 horas hábiles.',
                ],
            ],
        ]);

        return $page;
    }

    private function upsertSobreCicaPage(Tenant $tenant): Page
    {
        $page = $this->upsertPage($tenant, 'sobre-cica', 'Sobre CICA360', 'Centro Internacional de Consultoría y Asesoría', [
            'seo_title' => 'Sobre nosotros | CICA360',
            'seo_description' => 'Conocé la misión, visión y valores de CICA360, consultora uruguaya de seguros, finanzas y asesoría legal.',
        ]);

        $this->syncBlocks($page, $tenant, [
            [
                'type' => BlockTypeEnum::RichText,
                'title' => 'Quiénes somos',
                'content' => ['body' => '<p>CICA360 es un centro internacional de consultoría y asesoría que integra seguros, finanzas y temas jurídicos en un mismo lugar, para que profesionales, familias y empresas tomen mejores decisiones sin perder tiempo entre proveedores.</p>'],
                'properties' => [
                    'text_align' => 'center',
                    'content_width' => 'narrow',
                    'padding_y' => 'md',
                    'show_scroll_indicator' => false,
                    // Demostración de decorador inferior — mismo sistema visual
                    // que Hero/Slide (ver DecoratorShapeEnum).
                    'decorator_bottom' => 'wave',
                    'decorator_bottom_color' => '#E8E4ED',
                    'decorator_bottom_opacity' => 100,
                    'show_link' => false,
                ],
            ],
            [
                'type' => BlockTypeEnum::Features,
                'title' => 'Misión, visión y valores',
                'content' => [
                    'items' => [
                        ['icon' => 'heroicon-o-flag', 'title' => 'Misión', 'description' => 'Integrar seguros, finanzas y asesoría legal en un servicio único, transparente y cercano.'],
                        ['icon' => 'heroicon-o-eye', 'title' => 'Visión', 'description' => 'Ser el socio de referencia de profesionales y empresas de la región en la gestión integral de su patrimonio.'],
                        ['icon' => 'heroicon-o-heart', 'title' => 'Valores', 'description' => 'Transparencia, cercanía y compromiso de largo plazo con cada cliente.'],
                    ],
                ],
            ],
        ]);

        return $page;
    }

    private function upsertServiciosPage(Tenant $tenant): Page
    {
        $page = $this->upsertPage($tenant, 'servicios', 'Servicios', 'Soluciones integrales para cada etapa de tu proyecto', [
            'seo_title' => 'Servicios | CICA360',
            'seo_description' => 'Seguros, fondos, asesoría comercial, contable, jurídica, educación a distancia y bienes raíces en un solo lugar.',
        ]);

        $this->syncBlocks($page, $tenant, [
            [
                'type' => BlockTypeEnum::RichText,
                'title' => 'Qué ofrecemos',
                'content' => ['body' => '<p>Un equipo multidisciplinario que acompaña a profesionales, familias y empresas en las decisiones que más impactan su futuro.</p>'],
                'properties' => [
                    'text_align' => 'center',
                    'content_width' => 'boxed',
                    'padding_y' => 'sm',
                    'show_scroll_indicator' => false,
                    'show_link' => false,
                ],
            ],
            [
                'type' => BlockTypeEnum::ServicesGrid,
                'title' => 'Nuestros servicios',
                'content' => [
                    'items' => [
                        ['title' => 'Seguros generales', 'subtitle' => 'Cobertura patrimonial y de responsabilidad civil a medida.'],
                        ['title' => 'Seguros de vida y salud', 'subtitle' => 'Protección para vos y tu familia ante imprevistos.'],
                        ['title' => 'Fondos y seguros EE.UU.', 'subtitle' => 'Acceso a productos financieros y de seguro internacionales.'],
                        ['title' => 'Asesoría comercial', 'subtitle' => 'Estrategia y acompañamiento para negocios en crecimiento.'],
                        ['title' => 'Asesoría contable y financiera', 'subtitle' => 'Orden financiero y cumplimiento tributario.'],
                        ['title' => 'Servicios jurídicos', 'subtitle' => 'Asesoría legal preventiva y contractual.'],
                        ['title' => 'Educación a distancia', 'subtitle' => 'Formación continua para profesionales y equipos.'],
                        ['title' => 'Bienes raíces', 'subtitle' => 'Inversión y gestión inmobiliaria acompañada.'],
                    ],
                ],
            ],
        ]);

        return $page;
    }

    private function upsertCasosDeExitoPage(Tenant $tenant): Page
    {
        $page = $this->upsertPage($tenant, 'casos-de-exito', 'Casos de éxito', 'Historias reales de clientes que confiaron en nosotros', [
            'seo_title' => 'Casos de éxito | CICA360',
            'seo_description' => 'Testimonios de clientes de CICA360 en seguros, finanzas, asesoría legal y bienes raíces.',
        ]);

        $this->syncBlocks($page, $tenant, [
            [
                'type' => BlockTypeEnum::Testimonials,
                'title' => 'Lo que dicen nuestros clientes',
                // 2026-08-31: ya no trae `content.items` — los testimonios
                // de ejemplo (12, ver `Cliente0TestimonialsSeeder`) viven en
                // la tabla `testimonials`. Este bloque solo define el
                // filtro: acá se muestran los 4 más recientes, no los 12 —
                // a diferencia de la home (`upsertHomePage()`, `limit: 3`),
                // esta página SÍ está dedicada 100% a testimonios, así que
                // en un paso siguiente podría subirse el límite o sumar
                // paginación; por ahora se deja en 4 para no saturar de
                // entrada, mismo criterio "muestra un poco, no el dataset
                // completo" que ya usaba antes de tener 12 sembrados.
                'content' => ['limit' => 4, 'order' => 'desc'],
                // Colores del sistema de diseño CICA360 (2026-08-31, pedido
                // del Tech Lead): `cicagreen-500`/`cicagreen-400` — ver
                // `cica360/src/styles/global.css` (`--color-cicagreen-*`),
                // no un teal ad-hoc como antes (`#2b7c89`).
                'properties' => ['background_color' => '#206576', 'item_background_color' => '#4D919E', 'text_color' => '#ffffff'],
            ],
        ]);

        return $page;
    }

    /**
     * @param  array<string, Page>  $pages  Ya creadas (contacto/sobre-cica/servicios/casos-de-exito).
     */
    private function upsertHomePage(Tenant $tenant, array $pages): Page
    {
        $slider = Slider::where('tenant_id', $tenant->id)
            ->where('lang_iso', LanguageEnum::Spanish->value)
            ->where('slug', 'home')
            ->first();

        $page = Page::updateOrCreate(
            ['tenant_id' => $tenant->id, 'lang_iso' => LanguageEnum::Spanish->value, 'slug' => 'home'],
            [
                'title' => 'Home',
                'is_home' => true,
                'type' => PageTypeEnum::Page->value,
                'status' => PublishStatusEnum::Published->value,
                'meta' => [
                    'seo_title' => 'CICA360 — Seguros, finanzas y asesoría legal',
                    'seo_description' => 'Integramos seguros, finanzas y asesoría legal para potenciar tu crecimiento. Consultoría estratégica para profesionales, familias y empresas.',
                ],
                'published_at' => now(),
            ]
        );

        $this->syncBlocks($page, $tenant, [
            // El hero referencia el slider `home` (3 slides con sus propios CTAs)
            // en vez de duplicar título/imagen manualmente — ver Cliente0HomeSlidesSeeder.
            [
                'type' => BlockTypeEnum::Hero,
                'content' => [
                    'mode' => 'slider',
                    'slider_id' => $slider?->id,
                ],
            ],
            [
                'type' => BlockTypeEnum::RichText,
                'title' => 'Centro Internacional de Consultoría y Asesoría',
                // Subtítulo + cuerpo (2026-08-31, actualizado a pedido del Tech
                // Lead para matchear el mockup de referencia pixel-a-pixel):
                // antes solo tenía un body corto sin subtítulo — el mockup trae
                // una línea de subtítulo propia y un párrafo más largo con
                // énfasis en negrita sobre "asesoría integral y estratégica".
                'subtitle' => 'Conectamos conocimientos, potenciamos decisiones.',
                'content' => ['body' => '<p>En CICA acompañamos a individuos y familias, como a empresas, emprendedores y profesionales de distintos rubros en el crecimiento, optimización y fortalecimiento de sus operaciones. Nuestra misión es brindar una <strong>asesoría integral y estratégica</strong>, adaptada a cada necesidad, con un enfoque práctico, eficiente y comprometido.</p>'],
                'properties' => [
                    'text_align' => 'center',
                    'content_width' => 'boxed',
                    'padding_y' => 'lg',
                    // Flecha de scroll — es la primera sección después del Hero,
                    // invita a seguir bajando (referencia visual del Tech Lead).
                    'show_scroll_indicator' => true,
                    'show_link' => true,
                    // Bordes redondeados moderados, no pill (2026-08-31,
                    // feedback visual del Tech Lead con captura del botón real).
                    'link_radius' => 'lg',
                    'link_size' => 'lg',
                ],
                'links' => [
                    // Label "Conoce" (sin "más", 2026-08-31): el mockup de
                    // referencia trae el botón en una sola palabra + ícono "+".
                    // type 'outline' — botón con borde, no sólido, para
                    // distinguirlo del CTA dorado/sólido del Hero.
                    $this->link('Conoce', 'page', $pages['sobre-cica']->id, null, 'outline'),
                ],
            ],
            // "¿Qué hacemos?" / "¿A quién nos dirigimos?" (2026-08-31,
            // reemplazado a pedido del Tech Lead con mockup de referencia):
            // ANTES el primero era un bloque `Features` (grid de 6 íconos) —
            // el mockup real muestra ambas secciones como imagen+texto
            // alternado, mismo patrón que `Split` ya soporta vía
            // `content.media_position` (`left`/`right`). Se convierte el
            // primero a `Split` y se completa el segundo (antes con
            // `media_id: null` y una lista sin párrafo) con el contenido
            // real del mockup. Imágenes sembradas por `Cliente0MediaSeeder`
            // (debe correr antes — ver orden en `DatabaseSeeder`); si por lo
            // que sea no corrió, `mediaId()` devuelve `null` y el bloque
            // igual se crea (sin imagen, no rompe el seeder).
            [
                'type' => BlockTypeEnum::Split,
                'title' => '¿Qué hacemos?',
                'content' => [
                    'media_id' => Cliente0MediaSeeder::mediaId($tenant, 'cica360_media_split_1.webp'),
                    'media_position' => 'left',
                    'body' => '<p>Ofrecemos soluciones a medida en diversas áreas clave del negocio, con una mirada <strong>multidisciplinaria, internacional y orientada a resultados</strong>.</p>'
                        .'<p><em>Áreas de asesoría:</em></p>'
                        .'<ul>'
                        .'<li>Seguros y fondos de inversión en EE.UU</li>'
                        .'<li>Consultoría comercial</li>'
                        .'<li>Asesoría contable y financiera</li>'
                        .'<li>Servicios jurídicos</li>'
                        .'<li>Educación a distancia</li>'
                        .'<li>Bienes raíces e inversión inmobiliaria</li>'
                        .'</ul>',
                ],
                // `content_width: full` (2026-08-31): el diseño real del home usa el
                // bleed fullwidth para estas 2 secciones (imagen hasta el borde del
                // viewport, ver ADR-032 actualización 2) — se siembra explícito para
                // no depender del fallback `?? 'boxed'` del frontend.
                'properties' => ['content_width' => 'full'],
                'links' => [
                    $this->link('Conoce', 'page', $pages['servicios']->id, null, 'outline'),
                ],
            ],
            [
                'type' => BlockTypeEnum::Split,
                'title' => '¿A quién nos dirigimos?',
                'content' => [
                    'media_id' => Cliente0MediaSeeder::mediaId($tenant, 'cica360_media_split_2.webp'),
                    'media_position' => 'right',
                    'body' => '<ul>'
                        .'<li>Familias e individuos</li>'
                        .'<li>Emprendedores y autónomos</li>'
                        .'<li>Pymes y empresas consolidadas</li>'
                        .'<li>Instituciones educativas</li>'
                        .'</ul>'
                        .'<p>Cada rubro que abordamos tiene propuestas pensadas <strong>tanto para personas como para organizaciones</strong>, con un enfoque a medida, profesional y cercano.</p>',
                ],
                'properties' => ['content_width' => 'full'],
                'links' => [
                    $this->link('Quiero saber', 'page', $pages['contacto']->id, null, 'outline'),
                ],
            ],
            [
                'type' => BlockTypeEnum::Testimonials,
                'title' => 'Casos de éxito',
                'subtitle' => 'Conectamos conocimientos, potenciamos decisiones.',
                // Solo los 3 más recientes acá — el link "Ver más" manda a
                // la página con los 4 (ver `upsertCasosDeExitoPage()`).
                'content' => ['limit' => 5, 'order' => 'desc'],
                // Colores del sistema de diseño CICA360 (2026-08-31, ver
                // nota en `upsertCasosDeExitoPage()`): `cicagreen-500`/`400`.
                'properties' => ['background_color' => '#206576', 'item_background_color' => '#4D919E', 'text_color' => '#ffffff', 'show_link' => true],
                'links' => [
                    $this->link('Más casos de éxito', 'page', $pages['casos-de-exito']->id, null, 'outline'),
                ],
            ],
            [
                'type' => BlockTypeEnum::Logos,
                'title' => 'Empresas con las que trabajamos',
                'subtitle' => 'Aseguradoras, estudios jurídicos y organizaciones que confían en nuestra asesoría',
                // 2026-08-31, pedido del Tech Lead: los 7 logos ya subidos
                // (`Cliente0MediaSeeder`) reemplazan los 5 placeholders sin
                // imagen (`media_id: null`) que había antes — esta sección
                // no renderizaba NADA en el sitio real hasta ahora
                // (`Logos.astro` descarta silenciosamente cualquier item
                // sin `media.url`).
                // 2026-09-01, ampliado a 10 (pedido explícito: "generar 10
                // logos de partners de ejemplo"): con exactamente 7 la
                // sección quedaba en la grilla estática de una sola página
                // y nunca se probaba el modo carousel real. Con 10 items
                // (>7) `Logos.astro` pagina de a 7 — 2 páginas (7 + 3),
                // flechas/dots/drag visibles. Los 3 nuevos (`logo_8..10`)
                // son placeholders generados en el mismo estilo que los 7
                // anteriores (glifo abstracto simple + subrayado, sin
                // nombre de marca real).
                'content' => [
                    'items' => [
                        ['media_id' => Cliente0MediaSeeder::mediaId($tenant, 'cica360_media_logo_1.png'), 'alt' => 'Empresa asociada 1', 'url' => null],
                        ['media_id' => Cliente0MediaSeeder::mediaId($tenant, 'cica360_media_logo_2.png'), 'alt' => 'Empresa asociada 2', 'url' => null],
                        ['media_id' => Cliente0MediaSeeder::mediaId($tenant, 'cica360_media_logo_3.png'), 'alt' => 'Empresa asociada 3', 'url' => null],
                        ['media_id' => Cliente0MediaSeeder::mediaId($tenant, 'cica360_media_logo_4.png'), 'alt' => 'Empresa asociada 4', 'url' => null],
                        ['media_id' => Cliente0MediaSeeder::mediaId($tenant, 'cica360_media_logo_5.png'), 'alt' => 'Empresa asociada 5', 'url' => null],
                        ['media_id' => Cliente0MediaSeeder::mediaId($tenant, 'cica360_media_logo_6.png'), 'alt' => 'Empresa asociada 6', 'url' => null],
                        ['media_id' => Cliente0MediaSeeder::mediaId($tenant, 'cica360_media_logo_7.png'), 'alt' => 'Empresa asociada 7', 'url' => null],
                        ['media_id' => Cliente0MediaSeeder::mediaId($tenant, 'cica360_media_logo_8.png'), 'alt' => 'Empresa asociada 8', 'url' => null],
                        ['media_id' => Cliente0MediaSeeder::mediaId($tenant, 'cica360_media_logo_9.png'), 'alt' => 'Empresa asociada 9', 'url' => null],
                        ['media_id' => Cliente0MediaSeeder::mediaId($tenant, 'cica360_media_logo_10.png'), 'alt' => 'Empresa asociada 10', 'url' => null],
                    ],
                ],
                // Filtro por defecto (2026-08-31): grayscale completo +
                // opacidad reducida — cada logo vuelve a su color real al
                // pasar el mouse (ver `Logos.astro`). Mismo criterio visual
                // que pidió el Tech Lead con su captura de referencia.
                'properties' => ['media_filter_grayscale' => 100, 'media_opacity' => 60],
            ],
            [
                'type' => BlockTypeEnum::Cta,
                'title' => '¿Listo para transformar tu negocio?',
                'subtitle' => 'Conversemos y descubre cómo podemos ayudarte',
                'content' => ['body' => 'Agendá una primera conversación sin costo con nuestro equipo de asesores.'],
                'links' => [
                    $this->link('Empezar a planificar', 'page', $pages['contacto']->id),
                ],
            ],
        ]);

        return $page;
    }

    private function upsertMainMenu(Tenant $tenant, array $pages): void
    {
        $menu = Menu::updateOrCreate(
            ['tenant_id' => $tenant->id, 'lang_iso' => LanguageEnum::Spanish->value, 'slug' => 'menu-principal'],
            ['name' => 'Menú principal']
        );

        $items = [
            ['title' => 'Home', 'page' => $pages['home']],
            ['title' => 'Sobre CICA', 'page' => $pages['sobre-cica']],
            ['title' => 'Servicios', 'page' => $pages['servicios']],
            ['title' => 'Casos de éxito', 'page' => $pages['casos-de-exito']],
            ['title' => 'Consultar ahora', 'page' => $pages['contacto']],
        ];

        foreach ($items as $sortOrder => $item) {
            MenuItem::updateOrCreate(
                ['tenant_id' => $tenant->id, 'menu_id' => $menu->id, 'sort_order' => $sortOrder],
                [
                    'title' => $item['title'],
                    'type' => MenuItemTypeEnum::Page->value,
                    'reference_id' => $item['page']->id,
                    'url' => null,
                    'parent_id' => null,
                    'target' => '_self',
                    'is_active' => true,
                ]
            );
        }

        // Poda items de una corrida anterior si el menú tenía más entradas.
        MenuItem::where('menu_id', $menu->id)->where('sort_order', '>=', count($items))->delete();
    }

    /**
     * @param  array{seo_title?: string, seo_description?: string}  $meta
     */
    private function upsertPage(Tenant $tenant, string $slug, string $title, ?string $subtitle, array $meta = []): Page
    {
        return Page::updateOrCreate(
            ['tenant_id' => $tenant->id, 'lang_iso' => LanguageEnum::Spanish->value, 'slug' => $slug],
            [
                'title' => $title,
                'subtitle' => $subtitle,
                'type' => PageTypeEnum::Page->value,
                'is_home' => false,
                'status' => PublishStatusEnum::Published->value,
                'meta' => $meta,
                'published_at' => now(),
            ]
        );
    }

    /**
     * Sincroniza los bloques de una página en el orden dado (índice =
     * `sort_order`, igual convención que `saveRelationshipsUsing` de
     * `PageResource`). Poda bloques sobrantes de una corrida anterior con
     * más bloques que la actual, para que el seeder siga siendo idempotente
     * si se recorta contenido entre revisiones.
     *
     * @param  list<array{type: BlockTypeEnum, pretitle?: string, title?: string, subtitle?: string, content?: array, links?: array, properties?: array}>  $blocks
     */
    private function syncBlocks(Page $page, Tenant $tenant, array $blocks): void
    {
        foreach ($blocks as $sortOrder => $block) {
            Block::updateOrCreate(
                ['tenant_id' => $tenant->id, 'page_id' => $page->id, 'sort_order' => $sortOrder],
                [
                    'lang_iso' => LanguageEnum::Spanish->value,
                    'type' => $block['type']->value,
                    'pretitle' => $block['pretitle'] ?? null,
                    'title' => $block['title'] ?? null,
                    'subtitle' => $block['subtitle'] ?? null,
                    'content' => $block['content'] ?? [],
                    'links' => $block['links'] ?? [],
                    'properties' => $block['properties'] ?? [],
                    'is_visible' => true,
                ]
            );
        }

        $page->blocks()->where('sort_order', '>=', count($blocks))->delete();
    }

    /**
     * Construye un item de `links` respetando el schema real usado por
     * `App\Filament\Schemas\LinkSchema` (type/label/source_type/source_id/
     * url/target), no una estructura simplificada ad-hoc.
     */
    private function link(string $label, string $sourceType, ?int $sourceId = null, ?string $url = null, string $type = 'primary'): array
    {
        return [
            'type' => $type,
            'label' => $label,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'url' => $url,
            'target' => '_self',
        ];
    }
}
