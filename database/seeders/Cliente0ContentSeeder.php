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
use App\Models\Setting;
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

        // Orden 2026-09-07 (antes: `sobre-cica` se creaba 1ro, antes que
        // `casos-de-exito`): `upsertSobreCicaPage()` ahora clona el bloque
        // `testimonials` de la home con un link a la página `casos-de-exito`
        // (`$this->link('Más casos de éxito', 'page', $pages['casos-de-exito']->id, ...)`,
        // mismo patrón que usa `upsertHomePage()`) — necesita que esa página
        // ya exista en `$pages` para resolver el id, así que `casos-de-exito`
        // (y `servicios`, sin dependencias) pasan a crearse ANTES.
        $pages = [
            'contacto' => $this->upsertContactoPage($tenant, $form),
            // 2026-09-11 (revert): `servicios` había pasado a depender de
            // `$pages['contacto']` para un CTA final propio — se detectó
            // duplicado (ver comentario en `upsertServiciosPage()`, el CTA
            // ya viene incluido vía el bloque `footer` compartido) y se
            // sacó; `servicios` vuelve a no tener dependencias, mismo
            // criterio que `casos-de-exito`.
            'servicios' => $this->upsertServiciosPage($tenant),
            'casos-de-exito' => $this->upsertCasosDeExitoPage($tenant),
        ];
        $pages['sobre-cica'] = $this->upsertSobreCicaPage($tenant, $pages);
        $pages['footer'] = $this->upsertFooterPage($tenant, $pages);
        // 2026-09-13, pedido del Tech Lead: footer propio para la página de
        // Contacto, sin el CTA "¿Listo para transformar tu negocio?" (ver
        // docblock de `upsertFooterContactoPage()`) — el resto del sitio
        // sigue usando `footer-principal` (con CTA) tal cual.
        $pages['footer-contacto'] = $this->upsertFooterContactoPage($tenant);
        $pages['home'] = $this->upsertHomePage($tenant, $pages);

        // Bloque `footer` (2026-09-01, pedido del Tech Lead): se agrega al
        // final de CADA página pública, referenciando el Content compartido
        // `footer-principal` recién creado arriba — de esa forma el CTA del
        // pie de página vuelve a aparecer en todo el sitio, ahora vía el
        // mecanismo explícito por bloque (reemplaza el fetch global fijo
        // que tenía `BaseLayout.astro` en el frontend, ver ADR
        // correspondiente en DECISIONS.md). Se hace en un segundo paso,
        // fuera de `syncBlocks()` de cada página, porque el Content de
        // footer recién existe en este punto del seeder. `contacto` es la
        // ÚNICA excepción (2026-09-13): usa `footer-contacto` en vez de
        // `footer-principal` — ver comentario de arriba.
        foreach (['sobre-cica', 'servicios', 'casos-de-exito', 'home'] as $slug) {
            $this->appendFooterBlock($pages[$slug], $tenant, $pages['footer']->id);
        }
        $this->appendFooterBlock($pages['contacto'], $tenant, $pages['footer-contacto']->id);

        $this->upsertMainMenu($tenant, $pages);

        // 2026-09-13 (pedido del Tech Lead, ver genesis ADR-065): "considerar
        // en el seeder de contenido inicial como setting general tanto para
        // el SEO como para el OG" — sin dependencia de ninguna `$pages` de
        // arriba, puede ir al final sin importar el orden.
        $this->upsertSeoDefaults($tenant);
    }

    /**
     * Form "Contacto principal" — reutiliza las `FormFieldDefinition`
     * globales sembradas por `FormFieldDefinitionSeeder` (no se inventan
     * definitions nuevas), pero cada `FormField` de ESTE form puede pisar
     * el `label`/`is_required`/`options` del default global — necesario acá
     * porque el mockup real de "Contactame" (2026-09-11, pedido del Tech
     * Lead: "quiero que se genere el formulario basico y que envie al
     * endpoint correcto") pide labels específicos ("WhatsApp" en vez de
     * "Teléfono", "Consulta" en vez de "Mensaje") y 2 campos nuevos tipo
     * `select` con opciones concretas de CICA360 (país, área de interés) —
     * el catálogo global (`FormFieldDefinitionSeeder`) no tiene opinión
     * sobre esos valores, son propios de este tenant/form.
     *
     * `ContactSubmissionService::splitPayload()` (genesis) arma el `Contact`
     * a partir de `Form::fields()` — cualquier campo NO listado acá,
     * aunque el frontend lo mande, se ignora en silencio; agregar un campo
     * nuevo al form REAL es sumarlo a este array, no tocar el frontend
     * primero.
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

        // País (2026-09-11, ampliado el mismo día: "aumentar mas paises del
        // continente latinoamericano centro-sur"): los 8 originales tenían
        // bandera real ya sembrada para el catálogo de Servicios
        // (`sources/flags/` → `public/flags/` en cica360) — este `<select>`
        // de Contacto es texto plano, SIN ícono de bandera, así que sumar
        // países acá no depende de tener un asset de bandera nuevo. Se
        // completa el resto de Sudamérica hispanohablante (Colombia,
        // Venezuela) + Centroamérica hispanohablante (Panamá, Costa Rica,
        // Nicaragua, Honduras, El Salvador, Guatemala) — CONTINENTE
        // deliberadamente acotado a Centro y Sudamérica: sin México
        // (Norteamérica), sin Caribe (Cuba/Rep. Dominicana/Puerto Rico) ni
        // Guyana/Surinam/Belice (no hispanohablantes), no pedidos. Sigue
        // sin ser el listado ISO completo de `CountryEnum` (genesis, otro
        // dominio, el de Servicios). "Argentina" primero porque así lo
        // muestra el mockup (valor por default del `<select>`).
        $countryOptions = [
            ['value' => 'AR', 'label' => 'Argentina'],
            ['value' => 'UY', 'label' => 'Uruguay'],
            ['value' => 'BR', 'label' => 'Brasil'],
            ['value' => 'BO', 'label' => 'Bolivia'],
            ['value' => 'CL', 'label' => 'Chile'],
            ['value' => 'PY', 'label' => 'Paraguay'],
            ['value' => 'PE', 'label' => 'Perú'],
            ['value' => 'EC', 'label' => 'Ecuador'],
            ['value' => 'CO', 'label' => 'Colombia'],
            ['value' => 'VE', 'label' => 'Venezuela'],
            ['value' => 'PA', 'label' => 'Panamá'],
            ['value' => 'CR', 'label' => 'Costa Rica'],
            ['value' => 'NI', 'label' => 'Nicaragua'],
            ['value' => 'HN', 'label' => 'Honduras'],
            ['value' => 'SV', 'label' => 'El Salvador'],
            ['value' => 'GT', 'label' => 'Guatemala'],
        ];

        // Área de interés (2026-09-11): los 9 títulos REALES del catálogo
        // de Servicios (`Cliente0ServicesSeeder`, mismo orden) — copiados a
        // mano, no una relación en vivo a la tabla `services`: si el
        // catálogo cambia, esta lista se actualiza acá también (alcance
        // "formulario básico" pedido explícitamente, sin sincronización
        // dinámica).
        $areaOfInterestOptions = [
            ['value' => 'Seguridad Financiera', 'label' => 'Seguridad Financiera'],
            ['value' => 'Seguro Financiero', 'label' => 'Seguro Financiero'],
            ['value' => 'Asesoría y Consultoría Estratégica', 'label' => 'Asesoría y Consultoría Estratégica'],
            ['value' => 'Asesoría Contable y Financiera', 'label' => 'Asesoría Contable y Financiera'],
            ['value' => 'Asesoría Editorial Integral', 'label' => 'Asesoría Editorial Integral'],
            ['value' => 'Turismo y Asesoría Vacacional', 'label' => 'Turismo y Asesoría Vacacional'],
            ['value' => 'Bienes Raíces e Inversión', 'label' => 'Bienes Raíces e Inversión'],
            ['value' => 'Asesoramiento Legal Integral', 'label' => 'Asesoramiento Legal Integral'],
            ['value' => 'Asesoría Notarial', 'label' => 'Asesoría Notarial'],
        ];

        // Reglas de formato adicionales (2026-09-12, pedido del Tech Lead:
        // "validar bien los campos que sean coherentes y congruentes al
        // dato y tipo de dato" — con el detalle exacto de nombre/correo/
        // ciudad) — van en `FormField::validation_rules` (columna del
        // esquema desde el inicio del proyecto, sin uso real hasta esta
        // fecha, ver `ContactSubmissionService::rulesForField()`), NO
        // hardcodeadas por nombre de campo en el servicio: así el servicio
        // sigue siendo 100% genérico/dinámico para cualquier form/tenant, y
        // estas reglas puntuales de CICA360 quedan como DATA acá, igual que
        // `options` de país/área de interés más arriba.
        //
        // `$letterPattern`: "solo debe permitirse letras, un solo espacio
        // entre cada palabra ni al final, los espacios que desee entre cada
        // grupo de letras" — `\p{L}` (Unicode) cubre acentos/ñ/ç del
        // español y portugués sin listar cada letra a mano; el `?:` interno
        // hace que cada grupo de letras tenga como máximo un espacio antes
        // (nunca 2 seguidos), y el anchor `^...$` descarta espacio inicial o
        // final. Mismo pattern para nombre y ciudad — mismo pedido exacto
        // para ambos ("minimo 3 caracteres y maximo 40").
        $letterPattern = 'regex:/^\p{L}+(?: \p{L}+)*$/u';
        $nameRules = ['min:3', 'max:40', $letterPattern];
        $cityRules = ['min:3', 'max:40', $letterPattern];

        // Email: "no tenga caracteres especiales fuera del standar ...
        // guion, underline, punto intermedio, al menos un @, y validar que
        // el dominio sea un standar tld" — se suma ENCIMA de la regla base
        // `email:rfc,filter` que ya aplica el servicio para cualquier campo
        // tipo `FormFieldTypeEnum::Email` (esta regex es la capa EXTRA,
        // específica de este form, que fuerza el TLD final de 2-24 letras).
        $emailRules = ['regex:/^[\w.+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,24}$/'];

        // WhatsApp: 2026-09-12 (2da vuelta) — el frontend ahora arma
        // `phone` como "+{código de país}{dígitos del número local}" (ej.
        // "+51987654321", ver `ContactForm.tsx`), y ESE formato ya lo cubre
        // por completo la regla BASE de `ContactSubmissionService::
        // rulesForField()` para cualquier campo `FormFieldTypeEnum::Tel`
        // ("+" opcional + solo dígitos) — no hace falta una
        // `validation_rules` extra acá, sería una regex duplicada que con
        // el tiempo podría desincronizarse de la real. Si CICA360 llegara a
        // necesitar algo MÁS estricto que la regla base (ej. un rango de
        // longitud propio), ahí sí volvería a tener sentido sumarla acá.

        // Consulta: 2026-09-12 (3ra vuelta) — "esa textarea debe tener una
        // validacion coherente al tipo de info que recibirá, nada de html,
        // solo texto, signos de puntuacion o cualquier otro pero solo texto
        // plano". Allow-list (en vez de una block-list, más segura por
        // diseño: cualquier caracter NUEVO/raro que aparezca en el futuro
        // queda afuera por default, no hay que acordarse de agregarlo a una
        // lista de "prohibidos"): letras Unicode (`\p{L}`, cubre acentos/ñ),
        // dígitos (`\p{N}`), espacios en blanco (`\s`, incluye saltos de
        // línea — es un `<textarea>`) y puntuación/símbolos de uso normal
        // en una consulta en español (`. , ; : ! ? ' " ( ) - _ ¿ ¡ % / @ #
        // & * + = $ °`). Deliberadamente AFUERA: `< > { } [ ] \ \` ~ ^ |` —
        // los caracteres típicos de HTML/markup/código, que es justo lo que
        // se pidió excluir. Es una capa MÁS estricta que `NoHtmlTags` (que
        // solo bloquea tags bien formados) — acá directamente ningún
        // caracter fuera de la lista pasa, tag válido o no.
        $messageRules = ['regex:/^[\p{L}\p{N}\s.,;:!?\'"()\-_¿¡%\/@#&*+=$°]*$/u'];

        // Orden/labels/`required` (2026-09-12, pedido del Tech Lead sobre
        // el orden de campos del mockup original: "cambiar el orden:
        // nombre, correo, pais, whatsapp, cuidad, area interes, caja de
        // consulta" — reemplaza el orden anterior, Ciudad ya NO va 3ra).
        // `label`/`required`/`validation_rules` acá SIEMPRE pisan el
        // default de la `FormFieldDefinition` (que sigue siendo genérico,
        // reusable por cualquier otro form/tenant sin este copy/reglas
        // puntuales).
        $fieldsConfig = [
            ['key' => 'name', 'label' => 'Nombre y Apellido', 'required' => true, 'validation_rules' => $nameRules],
            ['key' => 'email', 'label' => 'Correo electrónico', 'required' => true, 'validation_rules' => $emailRules],
            ['key' => 'country', 'label' => 'País', 'required' => true, 'options' => $countryOptions],
            ['key' => 'phone', 'label' => 'WhatsApp', 'required' => false],
            ['key' => 'city', 'label' => 'Ciudad', 'required' => true, 'validation_rules' => $cityRules],
            ['key' => 'area_of_interest', 'label' => 'Área de interés', 'required' => true, 'options' => $areaOfInterestOptions],
            ['key' => 'message', 'label' => 'Consulta', 'required' => false, 'validation_rules' => $messageRules],
        ];

        foreach ($fieldsConfig as $sortOrder => $config) {
            $definition = FormFieldDefinition::where('key', $config['key'])->first();

            if (! $definition) {
                continue;
            }

            FormField::updateOrCreate(
                ['form_id' => $form->id, 'name' => $definition->key],
                [
                    'field_definition_id' => $definition->id,
                    'label' => $config['label'],
                    'type' => $definition->type->value,
                    'is_required' => $config['required'],
                    'is_encrypted' => $definition->default_encrypted,
                    'options' => $config['options'] ?? null,
                    'validation_rules' => $config['validation_rules'] ?? null,
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
            // Heading (Sección de Títulos) — 2026-09-06, mismo patrón que
            // `upsertSobreCicaPage()` (ver ese método para el detalle
            // completo del degradado/decorador), replicado acá a pedido
            // del Tech Lead: "generar en seeder el contenido inicial, con
            // el primer bloque heading para las paginas internas de
            // servicios, casos de exito, contactos". Sin un PDF de diseño
            // propio para esta página (a diferencia de `sobre-cica`, que sí
            // tenía `ABOUT.pdf`), se reutilizan las MISMAS 3 imágenes de
            // encabezado (`header_desktop/tablet/mobile`, ver
            // `Cliente0MediaSeeder`) como banner genérico compartido entre
            // páginas internas.
            //
            // Título/subtítulo con voseo rioplatense (2026-09-06, pedido
            // explícito del Tech Lead: "los titulos o subtitulos del
            // hearings que sean mas uruguayos que es parecido al lexico
            // argento", con ejemplo puntual "en contacto que diga
            // 'Contactame'") — a propósito DISTINTO del título "Contacto"
            // del ítem de menú (`upsertFooterPage()`/nav principal): acá es
            // el titular del banner, no la etiqueta de navegación, así que
            // puede divergir. No se tocó el eslogan compartido
            // "Conectamos conocimientos, potenciamos decisiones." (usado
            // en 3 lugares más: Hero, footer, ver `upsertHomePage()`) por
            // ser una marca/tagline transversal, no copy propio de este
            // bloque.
            [
                'type' => BlockTypeEnum::Heading,
                'title' => 'Contactame',
                'subtitle' => 'Contanos en qué podemos ayudarte',
                'content' => [
                    'image_desktop_id' => Cliente0MediaSeeder::mediaId($tenant, 'cica360_media_header-desktop.webp'),
                    'image_tablet_id' => Cliente0MediaSeeder::mediaId($tenant, 'cica360_media_header-tablet.webp'),
                    'image_mobile_id' => Cliente0MediaSeeder::mediaId($tenant, 'cica360_media_header-mobile.webp'),
                ],
                'properties' => [
                    'background_type' => 'image',
                    'overlay_color' => '#2D2C4D',
                    'overlay_opacity' => 90,
                    'decorator_bottom' => 'wave',
                    // 2026-09-11 (pedido del Tech Lead: "el bloque heading
                    // tiene el decorator blanco en contactame cuando tiene
                    // que tener el mismo color del fondo del bloque
                    // formulario") — el decorador inferior de este banner es
                    // la "costura" visual hacia la sección de abajo
                    // (`contact_form`, ver el bloque siguiente en este mismo
                    // array: `background_color: '#F6F6F6'`), así que su
                    // color tiene que calzar con ESE fondo, no quedar
                    // blanco puro (`#ffffff`, el default de
                    // `Heading.astro::decoratorFill()` cuando no se setea
                    // explícito) — antes coincidía "por accidente" con el
                    // default, pero era el valor equivocado para lo que
                    // sigue debajo.
                    'decorator_bottom_color' => '#F6F6F6',
                    'title_alignment' => 'center',
                ],
            ],
            // 2026-09-11 (pedido del Tech Lead, con captura del bloque
            // `rich_text` "Hablemos" tal como se veía renderizado sobre el
            // formulario): "no necesitamos este bloque, y el formulario no
            // debe tener nada en el heading" — el `rich_text` introductorio
            // se elimina por completo (antes vivía acá, entre el banner
            // `heading` y `contact_form`); el bloque `contact_form` pierde
            // su `title`/`content.intro` — el mockup real de "Contactame"
            // (ver PROGRESS.md, misma fecha) va directo del banner al
            // formulario, sin ningún texto intermedio.
            [
                'type' => BlockTypeEnum::ContactForm,
                'content' => [
                    'form_id' => $form->id,
                ],
                // 2026-09-11 (pedido del Tech Lead, mockup real de
                // "Contactame"): "la espectativa tambien indica que el
                // fondo es cicagray-50" — mismo `#F6F6F6` ya usado como
                // fondo de `services_grid`/`testimonials_grid` (ver
                // entradas de PROGRESS.md del 2026-09-11).
                'properties' => [
                    'background_type' => 'solid',
                    'background_color' => '#F6F6F6',
                ],
            ],
        ]);

        return $page;
    }

    /**
     * @param  array<string, Page>  $pages  Ya creadas (contacto/servicios/casos-de-exito) — ver orden en `run()`.
     */
    private function upsertSobreCicaPage(Tenant $tenant, array $pages): Page
    {
        $page = $this->upsertPage($tenant, 'sobre-cica', 'Sobre CICA360', 'Centro Internacional de Consultoría y Asesoría', [
            'seo_title' => 'Sobre nosotros | CICA360',
            'seo_description' => 'Conocé la misión, visión y valores de CICA360, consultora uruguaya de seguros, finanzas y asesoría legal.',
        ]);

        $this->syncBlocks($page, $tenant, [
            // Heading (Sección de Títulos) — 2026-09-05, pedido del Tech
            // Lead con referencia real `docs/UX-UI-design/ABOUT.pdf`
            // (cica360): franja superior con imagen de fondo (skyline con
            // silueta, tono violeta), título "Sobre CICA" + subtítulo, y
            // decorador inferior tipo onda en blanco (mismo criterio visual
            // que separa el Hero del resto del contenido en la home).
            // `pretitle` queda vacío a propósito: en el PDF el texto grande
            // en blanco ES el título, no hay una línea de pretitle propia
            // arriba (el "Sobre CICA" que se ve en el nav es el link activo
            // del menú, no parte de este bloque).
            [
                'type' => BlockTypeEnum::Heading,
                'title' => 'Sobre CICA',
                'subtitle' => 'Conectamos conocimientos, potenciamos decisiones.',
                'content' => [
                    'image_desktop_id' => Cliente0MediaSeeder::mediaId($tenant, 'cica360_media_header-desktop.webp'),
                    'image_tablet_id' => Cliente0MediaSeeder::mediaId($tenant, 'cica360_media_header-tablet.webp'),
                    'image_mobile_id' => Cliente0MediaSeeder::mediaId($tenant, 'cica360_media_header-mobile.webp'),
                ],
                'properties' => [
                    'background_type' => 'image',
                    // Overlay degradado (spec de Figma, 2026-09-06): capa
                    // intermedia entre la imagen de fondo y el texto, color
                    // sólido del 0% al 35% de la sección (100% de opacidad),
                    // degradando a transparente hacia el 100% (ver
                    // `Heading.astro`, `linear-gradient` + `color-mix()`).
                    // Sin este par de properties el bloque NUNCA pintaba el
                    // overlay: `overlayOpacity` caía a 0 por default
                    // (`properties.overlay_opacity ?? 0`) y la condición
                    // `overlayOpacity > 0 && properties.overlay_color` del
                    // componente fallaba en silencio — el código del
                    // degradado estaba bien, pero sin datos sembrados nunca
                    // se ejecutaba.
                    //
                    // `overlay_opacity`: el tramo sólido (0-35%) NO está
                    // pensado para verse del todo — el propósito es quedar
                    // oculto detrás del navbar (`Header.astro`,
                    // `position: fixed`, ~88px de alto en reposo: `pt-7` +
                    // `data-glass-bar` `h-[60px]`), no atenuado sobre la
                    // imagen. Un intento anterior (mismo día) bajó esto a
                    // `40` pensando que el tramo sólido se veía "exagerado"
                    // — pero esa vuelta tenía el overlay en `inset-0` (a lo
                    // largo de TODA la sección, cientos de px), muy por
                    // encima del alto real del navbar, así que gran parte
                    // del tramo sólido quedaba VISIBLE en vez de oculta. El
                    // fix real fue del lado de cica360 (`Heading.astro`,
                    // altura del overlay ajustada contra el navbar y luego
                    // a `h-[70%]` de la sección) — acá el dato pasó primero
                    // a `100` (valor literal del spec), y ahora, pedido
                    // explícito del Tech Lead (captura del panel de
                    // Filament con el slider en `90`): default inicial
                    // sembrado en `90`, no `100` — deja un resquicio mínimo
                    // de transparencia incluso en el tramo "sólido".
                    'overlay_color' => '#2D2C4D',
                    'overlay_opacity' => 90,
                    // Decorador inferior: onda blanca, sólida (sin
                    // degradado) — mismo valor (`wave`, SINGULAR) que
                    // consume `DecoratorShapeEnum`/`DecoratorShape` en el
                    // frontend; ver fix del `Select` de este bloque en
                    // `PageResource.php` (antes ofrecía `'waves'`, plural,
                    // que nunca hubiera coincidido).
                    'decorator_bottom' => 'wave',
                    'decorator_bottom_color' => '#ffffff',
                    'title_alignment' => 'center',
                ],
            ],
            // Texto Enriquecido, 2do bloque de la página — 2026-09-06,
            // reemplaza el placeholder anterior ("Quiénes somos", con
            // decorador inferior) a pedido del Tech Lead, con captura de
            // referencia: bloque simple, SIN heading — ni `pretitle` ni
            // `title` ni `subtitle`, solo el párrafo de `content.body` (por
            // eso no se setea `title` acá; `RichText.astro` ya renderiza
            // condicionalmente esos 3 campos, así que omitirlos alcanza,
            // sin properties nuevas). Tampoco lleva `decorator_top`/
            // `decorator_bottom` — pedido explícito ("no necesita
            // decorator"), simplemente no se declaran esas properties (mismo
            // criterio que el bloque introductorio del Home, ver
            // `upsertHomePage()` más abajo, que tampoco las declara).
            // `content_width: boxed` + `padding_y: lg` — mismo par de
            // valores que ese bloque introductorio del Home (línea ~465,
            // el `rich_text` que sigue al Hero) para quedar alineado al
            // mismo ritmo vertical/ancho de columna que ya usan los demás
            // bloques del sitio, en vez de reinventar un tercer valor
            // (antes tenía `content_width: narrow` + `padding_y: md`, sin
            // relación con ningún otro bloque).
            // Copy: mismo significado/enfoque del texto original que pasó
            // el Tech Lead, con un giro leve a voseo rioplatense en 2ª
            // persona ("te ofrecemos", "tus necesidades", "te acompaña",
            // "asesorarte") — mismo criterio ya aplicado a otros heading de
            // páginas internas (ver "Contactame" en `upsertContactoPage()`),
            // sin agregar ni quitar ningún concepto: sigue siendo enfoque
            // integral/cercano/profesional + red de profesionales +
            // compromiso/transparencia/visión estratégica, en el mismo
            // orden. El cierre en `<strong>` reproduce el énfasis en
            // negrita de la captura de referencia.
            [
                'type' => BlockTypeEnum::RichText,
                'content' => ['body' => '<p>En CICA creemos que cada persona, familia, emprendimiento o empresa tiene su propio camino. Por eso te ofrecemos un enfoque integral, cercano y profesional, que entiende tus necesidades específicas y te acompaña con soluciones efectivas. Somos una red de profesionales especializados en distintas áreas, unidos por una misma vocación: <strong>asesorarte con compromiso, transparencia y visión estratégica.</strong></p>'],
                'properties' => [
                    'text_align' => 'center',
                    'content_width' => 'boxed',
                    'padding_y' => 'lg',
                    'show_scroll_indicator' => false,
                    'show_link' => false,
                ],
            ],
            // Features (Misión/Visión/Valores) — 2026-09-07, reemplaza el
            // placeholder original (íconos heroicon + copy genérico) por el
            // contenido real que pasó el Tech Lead ("con esos datos tal
            // cual preparar el seeder"), con captura de referencia: 3
            // tarjetas con foto real arriba (`cica360_media_mission/
            // vision/values.webp`, ya subidas a `storage/app/public/media/`
            // — ver `Cliente0MediaSeeder`), Misión/Visión en párrafo,
            // Valores como lista de viñetas. `icon` se deja sin setear en
            // los 3 — ahora que hay imagen real, el ícono heroicon queda
            // como respaldo opcional para items SIN foto (no es el caso
            // acá).
            //
            // CORRECCIÓN 2026-09-07: `content_format` (por item) se movió a
            // `properties.list_style` (por BLOQUE) — ver
            // `FeatureListStyleEnum` y el docblock del bloque `features` en
            // `PageResource.php`. Ya no se setea nada por item; Misión/
            // Visión no traen `items[]` así que no les afecta el formato de
            // lista/grid, y Valores sí trae `items[]` así que se muestra
            // según `list_style` abajo.
            //
            // CORRECCIÓN 2026-09-07 (2da vuelta, Tech Lead: "en este diseño
            // no se usa ningun heading, no te diste cuenta?"): se sacó el
            // `title: 'Misión, visión y valores'` que se había agregado acá
            // — la captura de referencia (Figma "Desktop - ABOUT-US") iba
            // directo del párrafo introductorio a las 3 tarjetas, sin ningún
            // heading de sección entre medio.
            //
            // REVERSIÓN DELIBERADA 2026-09-09 (pedido explícito del Tech
            // Lead, motivo distinto al de la decisión de arriba — no es que
            // el heading "esté mal", es una necesidad nueva): en tablet/
            // mobile, `Features.astro` pinea esta sección a
            // `100vh`/`100dvh` mientras dura el scroll horizontal del
            // carousel (necesario por cómo funciona el pin de GSAP
            // ScrollTrigger — ver PROGRESS.md de cica360, 2026-09-09,
            // "el height:100vh era necesario, no cosmético"); con las
            // tarjetas centradas verticalmente en una sección de pantalla
            // completa, sin heading quedaba mucho vacío arriba/abajo del
            // carousel. Pedido textual: "poner un mejor titulo y subtitulo
            // un poco largos ahi para disimular un poco... que no sea un
            // simple relleno si no que sea util y con proposito" —
            // título/subtítulo con enfoque persuasivo/PNL
            // (presuposiciones, predicados sensoriales, refuerzo de
            // estabilidad/confianza), coherente con la identidad de marca
            // (voseo regional ya establecido en el resto del sitio) y
            // reforzando los mismos VALORES que las tarjetas de abajo listan
            // (transparencia, cercanía, compromiso) para que el heading no
            // se sienta desconectado del contenido que presenta.
            // CORRECCIÓN 2026-09-09 (2da vuelta, error real en vivo): el
            // primer intento de este pretitle/título/subtítulo rompió el
            // seeder — `QueryException`, "value too long for type character
            // varying(255)" (`subtitle` de `blocks` es `varchar(255)`, el
            // texto original rondaba los 265 caracteres). Corrección del
            // Tech Lead, con el error real como evidencia: "muy largo el
            // titulo, tiene que ser mas corto y no estamos usando
            // pretitulo" — se saca `pretitle` por completo (consistente con
            // el resto de este seeder: `testimonials`/`logos` en esta misma
            // página tampoco usan pretitle, no era una excepción a propósito
            // acá) y el `title`/`subtitle` bajan de una oración larga a algo
            // mucho más corto.
            //
            // CORRECCIÓN 2026-09-09 (3ra vuelta, mismo día): el subtítulo de
            // la 2da vuelta (185 caracteres, con dos puntos + enumeración)
            // ya entraba en la columna, pero el Tech Lead marcó un problema
            // distinto de estilo: "recuerda que los subtitulos tampoco
            // deberian ser una descripcion, son subtitulos, largo pero no
            // muy largos" — un subtítulo es una FRASE, no un párrafo
            // descriptivo con puntuación interna. Se acortó a una sola
            // oración de 58 caracteres.
            //
            // CORRECCIÓN 2026-09-09 (4ta vuelta, mismo día, con captura en
            // vivo): "un poco mas de texto en el subtitulo creo que
            // exageraste" — la vuelta anterior se pasó de corta en la
            // dirección opuesta. Se sube a 86 caracteres, un punto medio
            // entre las 2 correcciones previas: una sola oración fluida (NO
            // una lista con dos puntos, sigue siendo una frase real), un
            // poco más larga que el resto de los subtítulos de este seeder
            // pero sin llegar a sonar a descripción/párrafo.
            // CORRECCIÓN 2026-09-09 (5ta vuelta, mismo día, pedido de layout no
            // de estilo): "Cambiar titulo y subtitulo para que el titulo no
            // pase de una linea y el subtitulo no pase de 2 lineas" — en
            // `Features.astro` (cica360) el heading vive dentro de la
            // sección pineada a `100vh` en mobile/tablet (ver PROGRESS.md de
            // cica360, "Features.astro"), así que cuánto texto ocupa
            // verticalmente importa para el layout, no solo para el estilo.
            // El título anterior (35 caracteres, "La confianza se construye
            // de cerca") envolvía a 2 líneas incluso en el breakpoint más
            // chico; el subtítrulo anterior (86 caracteres) envolvía a 3.
            // Se acortan ambos manteniendo el mismo enfoque persuasivo/PNL
            // ya establecido (presuposición, cercanía, confianza, refuerzo
            // de los mismos valores que listan las tarjetas de abajo —
            // Cercanía/Transparencia/Compromiso están en `content.items`
            // "Valores" más abajo en este mismo bloque): título baja a 19
            // caracteres ("Confianza de cerca", cabe en 1 línea en
            // cualquier resolución del sitio), subtítulo baja a 51
            // caracteres ("Cercanía, transparencia y compromiso en cada
            // paso.", cabe en 2 líneas).
            [
                'type' => BlockTypeEnum::Features,
                'title' => 'Confianza de cerca',
                'subtitle' => 'Cercanía, transparencia y compromiso en cada paso.',
                'content' => [
                    'items' => [
                        [
                            'image_id' => Cliente0MediaSeeder::mediaId($tenant, 'cica360_media_mission.webp'),
                            'title' => 'Misión',
                            'description' => 'Brindar asesoría integral, confiable y de calidad, respondiendo con agilidad y empatía a las necesidades reales de nuestros clientes.',
                        ],
                        [
                            'image_id' => Cliente0MediaSeeder::mediaId($tenant, 'cica360_media_vision.webp'),
                            'title' => 'Visión',
                            'description' => 'Ser referentes en consultoría multidisciplinaria en Latinoamérica, conectando soluciones con personas y organizaciones que buscan crecer.',
                        ],
                        [
                            'image_id' => Cliente0MediaSeeder::mediaId($tenant, 'cica360_media_values.webp'),
                            'title' => 'Valores',
                            'items' => ['Profesionalismo', 'Empatía', 'Transparencia', 'Compromiso', 'Innovación', 'Cercanía'],
                        ],
                    ],
                ],
                // `feature_style: boxed_shadow` es ya el default de
                // `PropertiesSchema` (ver `FeatureCardStyleEnum`) — se
                // sigue seteando EXPLÍCITO acá (mismo criterio que
                // `background_type` en el resto del seeder) porque es
                // justamente el estilo que trae el diseño de referencia,
                // no un valor "de paso". Mismo criterio para `list_style:
                // list` (ver `FeatureListStyleEnum`) — es el formato que
                // necesita "Valores" para mostrar sus viñetas.
                //
                // FIX 2026-09-07 (reportado por el Tech Lead: "te diste
                // cuenta que falta el tipo de fondo color?"): `background_type:
                // solid` estaba seteado pero SIN `background_color` — sin un
                // color base cargado, `resolveBackgroundStyle()` (cica360,
                // `src/lib/background.ts`) devuelve `''` y la sección queda
                // transparente pese a decir "Sólido". Se agrega
                // `background_color: '#F6F6F6'` (mismo tono off-white que
                // `text_background_color` de los bloques `rich_text`
                // "¿Qué hacemos?" del Home, ver más abajo en este archivo) —
                // da contraste contra las tarjetas blancas de `boxed_shadow`
                // sin competir con ellas. Valor razonable por consistencia,
                // pendiente confirmación visual del Tech Lead.
                'properties' => [
                    'feature_style' => 'boxed_shadow',
                    'card_rounded' => true,
                    'list_style' => 'list',
                    'background_type' => 'solid',
                    'background_color' => '#F6F6F6',
                    'content_width' => 'boxed',
                    'padding_y' => 'lg',
                ],
            ],
            // Testimonials ("Casos de éxito") + Logos ("Empresas con las que
            // trabajamos") — 2026-09-07, pedido explícito del Tech Lead con
            // captura de referencia (Figma "Desktop - ABOUT-US"): "falta los
            // bloques Testimonios y logos / Socios con todo lo que tienen en
            // home, practicamente clonarlos antes del footer". El bloque CTA
            // ("¿Listo para transformar tu negocio?") que se ve al pie en la
            // captura NO se agrega acá — ya viene incluido automáticamente
            // vía el bloque `footer` compartido (`appendFooterBlock()`,
            // referencia a `upsertFooterPage()`, que ya tiene ese CTA desde
            // 2026-09-01) — agregarlo de nuevo acá lo duplicaría.
            //
            // Testimonials: mismo bloque que `upsertHomePage()` (`limit: 5`,
            // `order: desc`, colores `cicagreen-500`/`400`, link "Más casos
            // de éxito" → `casos-de-exito`) — la captura muestra el mismo
            // patrón exacto (3 tarjetas visibles + botón "MÁS CASOS DE
            // ÉXITO"), así que se clona tal cual en vez de reinventar un 2do
            // criterio de cuántos mostrar. Requiere `$pages['casos-de-exito']`
            // ya creada — ver el reordenamiento en `run()`.
            [
                'type' => BlockTypeEnum::Testimonials,
                'title' => 'Casos de éxito',
                'subtitle' => 'Conectamos conocimientos, potenciamos decisiones.',
                'content' => ['limit' => 5, 'order' => 'desc'],
                'properties' => ['background_type' => 'solid', 'background_color' => '#206576', 'item_background_color' => '#4D919E', 'text_color' => '#ffffff', 'show_link' => true],
                'links' => [
                    $this->link('Más casos de éxito', 'page', $pages['casos-de-exito']->id, null, 'outline'),
                ],
            ],
            // Logos: mismos 10 items + mismo filtro grayscale/opacidad que
            // `upsertHomePage()` (mismo carousel, misma data — no hay un 2do
            // set de logos para esta página). Title/subtitle SÍ cambian: la
            // captura de referencia de "Sobre CICA" trae su propio subtítulo
            // ("Soluciones integrales diseñadas para impulsar tu negocio"),
            // distinto al de la home ("Aseguradoras, estudios jurídicos y
            // organizaciones que confían en nuestra asesoría") — se respeta
            // el texto tal cual aparece en cada captura en vez de forzar el
            // mismo subtítulo en las 2 páginas.
            [
                'type' => BlockTypeEnum::Logos,
                'title' => 'Empresas con las que trabajamos',
                'subtitle' => 'Soluciones integrales diseñadas para impulsar tu negocio',
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
                'properties' => ['media_filter_grayscale' => 100, 'media_opacity' => 60],
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
            // Heading (Sección de Títulos) — mismo patrón/motivo que el de
            // `upsertContactoPage()` (ver ese método para el detalle
            // completo). Subtítulo con voseo rioplatense (queda tal cual
            // se pidió). Título: 2da vuelta (mismo día) — el título largo
            // con voseo ("Descubrí nuestros servicios") caía a 2 líneas en
            // mobile/375px; el Tech Lead pidió acortar o volver al título
            // corto del diseño original, conservando SOLO "Contactame"
            // (`upsertContactoPage()`) como la excepción con tono argento
            // — acá vuelve a ser "Servicios", igual al que ya recibe
            // `upsertPage()` más abajo.
            [
                'type' => BlockTypeEnum::Heading,
                'title' => 'Servicios',
                'subtitle' => 'Te acompañamos en cada etapa de tu proyecto',
                'content' => [
                    'image_desktop_id' => Cliente0MediaSeeder::mediaId($tenant, 'cica360_media_header-desktop.webp'),
                    'image_tablet_id' => Cliente0MediaSeeder::mediaId($tenant, 'cica360_media_header-tablet.webp'),
                    'image_mobile_id' => Cliente0MediaSeeder::mediaId($tenant, 'cica360_media_header-mobile.webp'),
                ],
                'properties' => [
                    'background_type' => 'image',
                    'overlay_color' => '#2D2C4D',
                    'overlay_opacity' => 90,
                    'decorator_bottom' => 'wave',
                    // 2026-09-11 (pedido del Tech Lead: "cicagray-50 es el
                    // background del bloque servicio y el mismo del
                    // decorador en el header"): antes `#ffffff` — pasa a
                    // `#F6F6F6` (`--color-cicagray-50` en cica360/global.css)
                    // para que el decorador se funda con el fondo del bloque
                    // `services_grid` de abajo, en vez de la costura blanca
                    // contra un fondo gris.
                    'decorator_bottom_color' => '#F6F6F6',
                    'title_alignment' => 'center',
                ],
            ],
            [
                // 2026-09-10 (2da vuelta, corrección del Tech Lead: "en el
                // contenido inicial [la página de] servicio[s] no tiene[n]
                // el bloque de texto enriquecido"): el mockup real de
                // "Servicios" no tiene ningún párrafo introductorio entre el
                // banner y el grid — va directo del Heading al catálogo. Se
                // saca el bloque `rich_text` ("Qué ofrecemos") que se había
                // agregado sin base en el diseño de referencia.
                //
                // 2026-09-10: ya no trae `content.items` — el catálogo vive
                // en la tabla `services` (9 registros reales, ver
                // `Cliente0ServicesSeeder`), resuelto en runtime por
                // `ResolvesPublicLinks` (mismo patrón que `testimonials`,
                // ADR-033/ADR-049).
                //
                // 2026-09-11 (pedido del Tech Lead: "falta especificar la
                // cantidad a mostrar de forma dinamica como 9 y el orden
                // manual del catalogo"): antes `limit: null` (sin tope,
                // "todos los publicados") — pasa a `limit: 9` como ejemplo
                // explícito de la configuración admin-editable del bloque
                // (`content.limit`/`content.order`, ver "Catálogo de
                // servicios" en `PageResource.php`), en vez de dejarla sin
                // usar en el contenido semilla. `order: asc` = orden MANUAL,
                // el `sort_order` curado a mano en `ServiceResource` (no
                // recencia, a diferencia de `testimonials` — ver ADR-049).
                //
                // Sin `title` (2da vuelta, mismo pedido: "el bloque de
                // servicios [va] pero sin titulo de contenido"): el mockup
                // no tiene ningún heading propio arriba del grid — el banner
                // superior ("Servicios") ya cumple ese rol. `BlockHeading`
                // (cica360) no renderiza nada si pretitle/title/subtitle
                // están los 3 ausentes, así que basta con no setearlos acá.
                'type' => BlockTypeEnum::ServicesGrid,
                'content' => ['limit' => 9, 'order' => 'asc'],
                // 2026-09-11 (pedido del Tech Lead: "cicagray-50 es el
                // background del bloque servicio y el mismo del decorador
                // en el header") — mismo hex que `decorator_bottom_color`
                // del bloque `heading` de arriba (`#F6F6F6`,
                // `--color-cicagray-50` en cica360/global.css), para que la
                // ola del banner se funda con el fondo de este bloque en
                // vez de cortar contra blanco.
                'properties' => [
                    'background_type' => 'solid',
                    'background_color' => '#F6F6F6',
                ],
            ],
            // 2026-09-11 (revert, bug real reportado con captura: "doble
            // bloque en el contenido inicial" — 2 banners idénticos "¿Listo
            // para transformar tu negocio?" apilados): el CTA final NO se
            // agrega acá — ya viene incluido automáticamente vía el bloque
            // `footer` compartido (`appendFooterBlock()`, referencia a
            // `upsertFooterPage()`, que ya tiene ese CTA desde 2026-09-01) —
            // agregarlo de nuevo acá lo duplicaba, exactamente como en
            // `upsertHomePage()`/`upsertSobreCicaPage()` (ver comentario
            // idéntico más abajo en este mismo archivo). Se había agregado
            // por error en la 1ra vuelta de este cambio, sin recordar este
            // precedente ya establecido.
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
            // Heading (Sección de Títulos) — mismo patrón/motivo que el de
            // `upsertContactoPage()` (ver ese método para el detalle
            // completo). Subtítulo con voseo rioplatense (queda tal cual
            // se pidió). Título: 2da vuelta (mismo día) — el título largo
            // con voseo ("Conocé nuestros casos de éxito") caía a 2 líneas
            // en mobile/375px; el Tech Lead pidió acortar o volver al
            // título corto del diseño original, conservando SOLO
            // "Contactame" (`upsertContactoPage()`) como la excepción con
            // tono argento — acá vuelve a ser "Casos de éxito", igual al
            // que ya recibe `upsertPage()` más abajo.
            [
                'type' => BlockTypeEnum::Heading,
                'title' => 'Casos de éxito',
                'subtitle' => 'Historias reales de quienes ya confiaron en nosotros',
                'content' => [
                    'image_desktop_id' => Cliente0MediaSeeder::mediaId($tenant, 'cica360_media_header-desktop.webp'),
                    'image_tablet_id' => Cliente0MediaSeeder::mediaId($tenant, 'cica360_media_header-tablet.webp'),
                    'image_mobile_id' => Cliente0MediaSeeder::mediaId($tenant, 'cica360_media_header-mobile.webp'),
                ],
                'properties' => [
                    'background_type' => 'image',
                    'overlay_color' => '#2D2C4D',
                    'overlay_opacity' => 90,
                    'decorator_bottom' => 'wave',
                    // 2026-09-11 (mismo criterio que `upsertServiciosPage()`,
                    // pedido del Tech Lead: "cicagray-50 es el background
                    // del bloque servicio y el mismo del decorador en el
                    // header"): `#ffffff` → `#F6F6F6` para que la ola se
                    // funda con el fondo del bloque `testimonials_grid` de
                    // abajo, en vez de cortar contra blanco.
                    'decorator_bottom_color' => '#F6F6F6',
                    'title_alignment' => 'center',
                ],
            ],
            [
                // 2026-09-11, reemplazo completo (pedido del Tech Lead, con
                // captura de mockup "Casos de éxito" — grid 3×3 real de
                // tarjetas con avatar/frase/nombre + botón "MÁS CASOS", no
                // un teaser): "es un bloque de testimonios que creamos a
                // modo preview o resumen solo para home u otras paginas,
                // pero este tiene que ser un bloque nuevo especial como el
                // de servicios, donde va el heading y luego la
                // configuracion todo igual al de servicios en el admin".
                // Antes: `BlockTypeEnum::Testimonials` (el bloque teaser,
                // `limit: 4, order: desc` por recencia, colores
                // `cicagreen-*` ad-hoc de esta página) — pasa a
                // `BlockTypeEnum::TestimonialsGrid` (mismo tratamiento
                // exacto que `services_grid` en `upsertServiciosPage()`,
                // ver ADR nuevo): `order: asc` (orden manual curado a mano
                // en `TestimonialResource`, NO recencia), sin `title` (el
                // mockup no tiene heading propio sobre el grid — el banner
                // superior ya cumple ese rol, mismo criterio que
                // `services_grid`), fondo `cicagray-50` (mismo color que el
                // decorador del banner de arriba).
                //
                // `content.limit: 9` (2026-09-11, 2da vuelta — antes
                // `null`/sin tope): "se necesita dejar la cantidad a
                // mostrar y orden que este seteado e integrado con el
                // frontsite" — mismo criterio explícito ya aplicado a
                // `services_grid` (ver comentario de `upsertServiciosPage()`
                // más arriba): ejemplificar el campo admin-editable
                // (`content.limit`, Section "Catálogo de casos de éxito" en
                // `PageResource.php`) con un valor concreto en vez de dejarlo
                // sin usar. A diferencia de servicios (9 de 9, sin recorte
                // real), acá SÍ recorta de verdad: hay 12 testimonios
                // sembrados (`Cliente0TestimonialsSeeder`), así que 9
                // demuestra el límite en acción — `TestimonialsGrid.astro`
                // ya resuelve `content.items[]` (recortado/ordenado server-
                // side en `ResolvesPublicLinks::transformBlockContent()`,
                // rama `testimonials_grid`) sin cambios de código, mismo
                // mecanismo 100% client-side de "Más casos" ya integrado.
                'type' => BlockTypeEnum::TestimonialsGrid,
                'content' => ['limit' => 9, 'order' => 'asc'],
                'properties' => [
                    'background_type' => 'solid',
                    'background_color' => '#F6F6F6',
                ],
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
                // `text_background_color` (2026-09-01, la captura de referencia del
                // Tech Lead lo mostraba y se nos había pasado): gris claro detrás de
                // la columna de texto, independiente del fondo de la sección.
                // `#F6F6F6` = `cicagray-50` del Design System (ver
                // `--color-cicagray-50` en `cica360/src/styles/global.css`) —
                // el campo es un `ColorPicker` de hex crudo, no puede
                // referenciar la clase Tailwind directo, se siembra el hex
                // exacto de ese paso de la escala.
                'properties' => ['content_width' => 'full', 'text_background_color' => '#F6F6F6'],
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
                // Mismo `text_background_color` que "¿Qué hacemos?" de arriba, para
                // que las 2 secciones alternadas de la home compartan el mismo look.
                'properties' => ['content_width' => 'full', 'text_background_color' => '#F6F6F6'],
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
                'properties' => ['background_type' => 'solid', 'background_color' => '#206576', 'item_background_color' => '#4D919E', 'text_color' => '#ffffff', 'show_link' => true],
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
        ]);
        // El bloque CTA ("¿Listo para transformar tu negocio?") que vivía
        // acá se trasladó al Content tipo `Footer` (2026-09-01, pedido
        // explícito del Tech Lead: "trasladar el bloque predeterminado
        // Llamado a la Acción, de esa forma dependerá del footer") — ver
        // `upsertFooterPage()` más abajo. La Home ya no lo siembra directo.

        return $page;
    }

    /**
     * Content principal tipo `Footer` (2026-09-01, pedido del Tech Lead:
     * el picker de bloques de un `Footer` queda restringido — ver
     * `PageResource.php`, filtro por `type` en `Builder::blocks()` — a
     * `image`/`cta`/`features`/`faq`/`contact_form`/`testimonials`/`logos`,
     * sin heading/hero/rich_text/legal_notice/split/services_grid). El CTA
     * "¿Listo para transformar tu negocio?" que antes vivía hardcodeado en
     * la Home se traslada acá tal cual (mismo contenido/properties/link) —
     * de ahora en más el footer es dueño de ese bloque, no la Home.
     *
     * Cómo llega al frontend (2026-09-01, actualizado): cada página pública
     * agrega un bloque `footer` (ver `appendFooterBlock()` más abajo, y
     * `Builder\Block::make('footer')` en `PageResource.php`) que referencia
     * este Content por id (`content.footer_page_id`). El backend
     * (`ResolvesPublicLinks::transformBlockContent()`) resuelve ESTE
     * Content completo — con sus propios bloques ya resueltos — anidado
     * como `footer_page.blocks[]`, y `FooterBlock.astro` en cica360 los
     * re-despacha al `BlockRenderer` genérico. Reemplaza el mecanismo
     * anterior (fetch fijo a `footer-principal` en `BaseLayout.astro`).
     */
    private function upsertFooterPage(Tenant $tenant, array $pages): Page
    {
        $page = Page::updateOrCreate(
            ['tenant_id' => $tenant->id, 'lang_iso' => LanguageEnum::Spanish->value, 'slug' => 'footer-principal'],
            [
                'title' => 'Footer principal',
                'is_home' => false,
                'type' => PageTypeEnum::Footer->value,
                'status' => PublishStatusEnum::Published->value,
                'meta' => [],
                'published_at' => now(),
            ]
        );

        $this->syncBlocks($page, $tenant, [
            [
                // Mismo preseteo exacto que tenía en `upsertHomePage()`
                // (rediseño completo del bloque, ver `PageResource.php`/
                // `Cta.astro`): `background_color` = `cicaindigo-500`
                // (`#2D2C4D`, DEFAULT del Design System) + `text_color`
                // blanco, `content_width: boxed`, botón `link_radius: lg`
                // (rounded-lg, moderado, no pill) + `link_size: lg`.
                'type' => BlockTypeEnum::Cta,
                'title' => '¿Listo para transformar tu negocio?',
                'subtitle' => 'Conversemos y descubre cómo podemos ayudarte a alcanzar tus objetivos',
                'properties' => [
                    'background_type' => 'solid',
                    'background_color' => '#2D2C4D',
                    'text_color' => '#FFFFFF',
                    'content_width' => 'boxed',
                    'padding_y' => 'lg',
                    'show_link' => true,
                    'link_radius' => 'lg',
                    'link_size' => 'lg',
                ],
                'links' => [
                    $this->link('Empezar a planificar', 'page', $pages['contacto']->id),
                ],
            ],
            ...$this->footerColophonAndBottomBlocks(),
        ]);

        return $page;
    }

    /**
     * 2026-09-13, pedido del Tech Lead con captura ("tiene que haber un
     * footer adicional para contactos... en esa sección footer de
     * contactos solo tenga el colophon y la barra inferior con sus
     * propiedades, osea lo mismo que el footer principal pero sin el
     * bloque CTA") — el CTA "¿Listo para transformar tu negocio?" tiene
     * sentido en el resto del sitio (invita a ir a la página de Contacto),
     * pero ES redundante en la propia página de Contacto: el visitante ya
     * está ahí. Reusa `footerColophonAndBottomBlocks()` (idéntico
     * Colophon/FooterBottom que `footer-principal`, mismo contacto/redes/
     * copyright — un solo lugar para mantenerlos en sincro) sin el bloque
     * `cta` inicial.
     */
    private function upsertFooterContactoPage(Tenant $tenant): Page
    {
        $page = Page::updateOrCreate(
            ['tenant_id' => $tenant->id, 'lang_iso' => LanguageEnum::Spanish->value, 'slug' => 'footer-contactos'],
            [
                'title' => 'Footer Contactos',
                'is_home' => false,
                'type' => PageTypeEnum::Footer->value,
                'status' => PublishStatusEnum::Published->value,
                'meta' => [],
                'published_at' => now(),
            ]
        );

        $this->syncBlocks($page, $tenant, $this->footerColophonAndBottomBlocks());

        return $page;
    }

    /**
     * Colophon + FooterBottom compartidos entre `footer-principal` (con
     * CTA propio antepuesto) y `footer-contactos` (sin CTA) — extraído acá
     * el mismo día que se agregó este 2do footer, para que ambos footers
     * muestren siempre el mismo contacto/redes/copyright sin mantener 2
     * copias del mismo array que puedan desincronizarse con el tiempo.
     *
     * @return list<array{type: BlockTypeEnum, content?: array, properties?: array}>
     */
    private function footerColophonAndBottomBlocks(): array
    {
        return [
            // COLOPHON (2026-09-02, pedido del Tech Lead, con captura de
            // referencia: 3 columnas — marca/tagline, contacto, redes
            // sociales). Seed con colores SÓLIDOS únicamente ("en el seeder
            // va solo colores solidos predefinidos como están" — el
            // degradado queda disponible como opción en Studio, pero no se
            // siembra acá). `link_list`/`social_links` con la MISMA forma
            // que produce el Builder anidado de `PageResource.php`
            // (`{type, data}` por sub-bloque) — `hola@cica360.com` ya es el
            // contacto real del tenant (ver `Tenant::publicUrl()`/
            // `Cliente0Seeder`); el teléfono es el de la captura de
            // referencia del Tech Lead.
            //
            // Columna 1 ("marca"), corrección 2026-09-02 ("falta el logo
            // gris... acompañando con el texto entre comillas, sin título,
            // no usar el típico heading"): `title` pasa a `null` — el
            // wordmark ya NO se sube por Studio (no había ningún sub-bloque
            // `image_link` acá, de hecho) sino que `Colophon.astro` lo
            // hardcodea (mismo criterio que `Header.astro`: los 3 SVG de
            // `public/logos/` son del sitio, no contenido de tenant) para
            // cualquier columna sin título que traiga `description` —
            // "columna de marca" por convención, no por un campo nuevo.
            // `description` se mantiene como el texto de la cita — el
            // frontend le agrega las comillas tipográficas + itálica, no se
            // hardcodean acá.
            //
            // Columna 2 ("Contacto"), corrección 2026-09-02 ("faltan
            // iconos"): cada link de `link_list` gana `icon` (`LinkIconEnum`,
            // ver `LinkSchema::make(..., withIcon: true)`) — correo con el
            // ícono de "enviar" de la captura, teléfono con el logo de
            // WhatsApp (mismo número, ya era un link a `wa.me`).
            [
                'type' => BlockTypeEnum::Colophon,
                'content' => [
                    'columns' => [
                        [
                            'title' => null,
                            'description' => 'Conectamos conocimientos, potenciamos decisiones.',
                            'blocks' => [],
                        ],
                        [
                            'title' => 'Contacto',
                            'description' => null,
                            'blocks' => [
                                [
                                    'type' => 'link_list',
                                    'data' => [
                                        'items' => [
                                            ['type' => 'text', 'label' => 'hola@cica360.com', 'source_type' => 'url', 'url' => 'mailto:hola@cica360.com', 'target' => '_self', 'icon' => 'email'],
                                            ['type' => 'text', 'label' => '+598 99 063 352', 'source_type' => 'url', 'url' => 'https://wa.me/59899063352', 'target' => '_blank', 'icon' => 'whatsapp'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'title' => 'Síguenos',
                            'description' => null,
                            'blocks' => [
                                [
                                    'type' => 'social_links',
                                    'data' => [
                                        'items' => [
                                            ['platform' => 'facebook', 'url' => 'https://facebook.com/cica360'],
                                            ['platform' => 'instagram', 'url' => 'https://instagram.com/cica360'],
                                            ['platform' => 'linkedin', 'url' => 'https://linkedin.com/company/cica360'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'properties' => [
                    'content_width' => 'boxed',
                    'background_type' => 'solid',
                    // 2026-09-02, pedido del Tech Lead: fondo predeterminado
                    // real del pie de página (`#191838`) — distinto del
                    // `#2D2C4D` (indigo, DEFAULT del Design System) que usa
                    // el CTA de arriba, a propósito.
                    'background_color' => '#191838',
                    'text_color' => '#FFFFFF',
                    'padding_y' => 'lg',
                ],
            ],

            // 2026-09-02, rediseño completo del bloque (ver ADR-042). Nota
            // histórica: al escribir este bloque CICA360 todavía era Free
            // Forever (ADR-006) y `copyright_text` quedaba ignorado por el
            // gate de white-label — por eso el comentario original decía
            // "no se siembra acá". Ya no aplica: el mismo día se creó el
            // plan Auspicio/Convenio (ADR-043) y CICA360 fue reasignado a
            // ese plan — `copyright_text` en este plan SÍ se usa (es el
            // fragmento "año + nombre" que Console pide para el campo
            // "Año y nombre (Auspicio/Convenio)", ver `PageResource.php`),
            // así que ahora se siembra con el valor real cargado por el
            // Tech Lead en Studio ("2026 CICA360"). `right_type`/`menu_id`/
            // `right_text` siguen sin seleccionar a propósito ("predeterminado
            // en los seeders sin nada o vacío", pedido explícito) — el Tech
            // Lead activa "Mostrar menú" a mano en Studio si lo necesita.
            [
                'type' => BlockTypeEnum::FooterBottom,
                'content' => [
                    'copyright_text' => '2026 CICA360',
                ],
                'properties' => [
                    'background_type' => 'solid',
                    'background_color' => '#191838',
                    'text_color' => '#FFFFFF',
                ],
            ],
        ];
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
     * Agrega el bloque `footer` (referencia al Content compartido tipo
     * `Footer`, ver `upsertFooterPage()`) al final de una página, en un
     * `sort_order` posterior al último bloque "de contenido" ya sembrado
     * por `syncBlocks()`. Separado de `syncBlocks()` a propósito: el id del
     * Content de footer recién se conoce después de crear TODAS las
     * páginas de contenido (ver orden en `run()`), así que no puede
     * incluirse en el array de bloques que arma cada `upsertXPage()`.
     *
     * Idempotente igual que `syncBlocks()`: en cada corrida, `syncBlocks()`
     * de la página vuelve a podar cualquier bloque en `sort_order >=
     * count($blocks)` (lo que incluye este bloque `footer` de la corrida
     * anterior) y este método lo vuelve a crear en el siguiente `sort_order`
     * libre — el resultado neto es el mismo, solo se recrea la fila.
     */
    private function appendFooterBlock(Page $page, Tenant $tenant, int $footerPageId): void
    {
        $sortOrder = $page->blocks()->max('sort_order');
        $sortOrder = $sortOrder === null ? 0 : $sortOrder + 1;

        Block::updateOrCreate(
            ['tenant_id' => $tenant->id, 'page_id' => $page->id, 'sort_order' => $sortOrder],
            [
                'lang_iso' => LanguageEnum::Spanish->value,
                'type' => BlockTypeEnum::Footer->value,
                'pretitle' => null,
                'title' => null,
                'subtitle' => null,
                'content' => ['footer_page_id' => $footerPageId],
                'links' => [],
                'properties' => [],
                'is_visible' => true,
            ]
        );
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

    /**
     * SEO/Open Graph por defecto del tenant (2026-09-13, ver genesis
     * ADR-065 y `App\Filament\Pages\Preferences`) — pedido explícito del
     * Tech Lead: "considerar en el seeder de contenido inicial como setting
     * general tanto para el SEO como para el OG". Sin esto, una página sin
     * su propio `meta.seo_*`/`meta.og_*` (la gran mayoría del contenido
     * inicial) cae en una API pública sin ningún `og:image` y con
     * título/descripción repitiendo en cascada el mismo dato base — visible
     * en vivo al inspeccionar "Ver código fuente" del sitio. Este método
     * puebla ese fallback con copy genérico real de CICA360 (no un
     * placeholder tipo "Lorem ipsum") y las 2 imágenes OG (horizontal
     * 1200x630 / cuadrada 600x600) que el Tech Lead ya subió a
     * `storage/app/public/media/` — ver `Cliente0MediaSeeder::FILES`
     * (`og_horizontal`/`og_square`).
     *
     * A diferencia del resto de este seeder (que crea `Page`/`Block`/etc.,
     * todos con `HasTenant`), `Setting` no auto-completa `tenant_id` en un
     * contexto de seeder (no hay tenant resuelto en `TenantManager`, eso
     * solo pasa en un request HTTP real vía `SyncTenantManagerWithFilament`/
     * `ResolvesTenant`) — se pasa `tenant_id` explícito en el `updateOrCreate`,
     * mismo patrón que `Media::firstOrCreate()` en `Cliente0MediaSeeder`.
     * `updateOrCreate` por `['tenant_id', 'key']` (mismo índice único de la
     * tabla `settings`) hace esto idempotente, igual que el resto del
     * seeder — no pisa un valor que el Tech Lead ya haya cambiado a mano
     * desde Preferencias EXCEPTO que welcome de nuevo con el mismo valor
     * (comportamiento aceptado: es contenido inicial, no un valor protegido).
     */
    private function upsertSeoDefaults(Tenant $tenant): void
    {
        $values = [
            'seo.default_title' => 'CICA360 — Seguros, Fondos y Asesoría Integral',
            'seo.default_keywords' => 'seguros, fondos de inversión, asesoría comercial, asesoría contable, asesoría jurídica, educación a distancia, bienes raíces, CICA360',
            'seo.default_description' => 'CICA360 es tu aliado integral en seguros, fondos, asesoría comercial, contable y jurídica, educación a distancia y bienes raíces — todo en un solo lugar.',
            'og.default_title' => 'CICA360 — Tu aliado integral en seguros y asesoría',
            'og.default_description' => 'Seguros, fondos, asesoría comercial, contable, jurídica, educación a distancia y bienes raíces. Conocé todo lo que CICA360 puede hacer por vos.',
            'og.default_image_rect_id' => Cliente0MediaSeeder::mediaId($tenant, 'cica360_media_og_horizontal.jpg'),
            'og.default_image_square_id' => Cliente0MediaSeeder::mediaId($tenant, 'cica360_media_og_square.jpg'),
        ];

        foreach ($values as $key => $value) {
            // `Cliente0MediaSeeder::mediaId()` devuelve `null` si el archivo
            // no se sembró (por ejemplo, un checkout sin los assets nuevos
            // todavía) — se guarda igual como `null` en vez de omitir la
            // clave, mismo criterio que el resto de este seeder con FKs de
            // imagen opcionales (`upsertHomePage()`, etc.): la ausencia del
            // archivo no debe romper el seeder completo.
            Setting::updateOrCreate(
                ['tenant_id' => $tenant->id, 'key' => $key],
                ['value' => $value]
            );
        }
    }
}
