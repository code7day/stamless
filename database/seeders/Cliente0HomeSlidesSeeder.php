<?php

namespace Database\Seeders;

use App\Enums\LanguageEnum;
use App\Enums\SlideBackgroundTypeEnum;
use App\Models\Page;
use App\Models\Slider;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class Cliente0HomeSlidesSeeder extends Seeder
{
    /**
     * Contenido de las 3 slides del home según el mockup de diseño
     * (CICA — "El socio que necesitas" / "Sin letra chica" /
     * "Tu futuro se diseña hoy"). `background_file` (2026-08-31, patrón
     * nuevo a pedido del Tech Lead — ver `Cliente0MediaSeeder`): apunta al
     * archivo ya sembrado en `media` vía `Cliente0MediaSeeder::mediaId()`,
     * mismo id reusado para `image_desktop_id`/`image_tablet_id`/
     * `image_mobile_id` — **un solo fondo por breakpoint por ahora** (no
     * hay todavía variantes recortadas por dispositivo; cuando existan,
     * cada campo puede apuntar a un archivo distinto sin tocar esta
     * estructura). Cada CTA intenta resolver a una página real por slug
     * (`cta_target_slug`); si esa página todavía no existe (p. ej. se
     * corre este seeder solo, sin `Cliente0ContentSeeder`), cae a un link
     * `custom` con `url = '#'` como placeholder — se autocompleta en la
     * próxima corrida completa de `db:seed`.
     */
    private const array SLIDES = [
        [
            'title' => 'Tu futuro se diseña hoy',
            'subtitle' => 'Consultoría estratégica para tus metas de vida, inversión y patrimonio.',
            'cta_label' => 'Empezar a planificar',
            'cta_target_slug' => 'contacto',
            'position_container' => 'bottom-center',
            'align_content' => 'center',
            'background_file' => 'cica360_media_slide1.webp',
        ],
        [
            'title' => 'Sin letra chica',
            'subtitle' => 'Asesoría transparente para profesionales que valoran su tiempo.',
            'cta_label' => 'Descubrir servicios',
            'cta_target_slug' => 'servicios',
            'position_container' => 'bottom-left',
            'align_content' => 'left',
            'background_file' => 'cica360_media_slide2.webp',
        ],
        [
            'title' => 'El socio que necesitas',
            'subtitle' => 'Integramos seguros, finanzas y asesoría legal para potenciar tu crecimiento.',
            'cta_label' => 'Agendar asesoría',
            'cta_target_slug' => 'contacto',
            'position_container' => 'top-center',
            'align_content' => 'center',
            'background_file' => 'cica360_media_slide3.webp',
        ],
    ];

    /**
     * `properties` compartido por las 3 slides del home (además de
     * `position_container`/`align_content`, que varían por slide — ver
     * `SLIDES` arriba). Onda blanca sólida al pie, sin efectos de imagen
     * (todo en sus defaults), coherente con `HERO-3-SLIDES-IMPORTANTS.png`.
     *
     * @return array<string, mixed>
     */
    private function baseProperties(string $positionContainer, string $alignContent): array
    {
        return [
            'position_container' => $positionContainer,
            'align_content' => $alignContent,
            'decorator_bottom' => 'wave',
            'decorator_bottom_color' => '#ffffff',
            'decorator_bottom_opacity' => 100,
            // `show_scroll_indicator` NO va acá (corrección del mismo día,
            // 2026-08-31): es una property del Slider en general, sembrada
            // una sola vez en `Cliente0Seeder::upsertPlaceholderSlider()`,
            // no repetida por cada slide.
            'slide_background_color' => null,
            'slide_background_brightness' => 100,
            'slide_background_opacity' => 100,
            'slide_background_blend_mode' => 'normal',
            'slide_background_filter_saturate' => 100,
            'slide_background_filter_grayscale' => 0,
            'slide_background_filter_sepia' => 0,
            'slide_background_filter_contrast' => 100,
            'slide_background_filter_hue_rotate' => 0,
            'slide_background_filter_blur' => 0,
        ];
    }

    /**
     * Seed the application's database.
     *
     * Requiere que `Cliente0Seeder` haya corrido antes (necesita el tenant
     * CICA360 y su slider placeholder `home` ya creados). Para que los CTA
     * resuelvan a páginas reales, `Cliente0ContentSeeder` debe correr antes
     * que este. Para que las 3 slides traigan fondo, `Cliente0MediaSeeder`
     * debe correr antes que este también (ver orden en `DatabaseSeeder`).
     */
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'cica360')->first();

        if (! $tenant) {
            return;
        }

        $slider = Slider::where('tenant_id', $tenant->id)
            ->where('slug', 'home')
            ->first();

        if (! $slider) {
            return;
        }

        foreach (self::SLIDES as $sortOrder => $slide) {
            $targetPage = Page::where('tenant_id', $tenant->id)
                ->where('slug', $slide['cta_target_slug'])
                ->first();

            $link = $targetPage
                ? ['type' => 'primary', 'label' => $slide['cta_label'], 'source_type' => 'page', 'source_id' => $targetPage->id, 'url' => null, 'target' => '_self']
                : ['type' => 'primary', 'label' => $slide['cta_label'], 'source_type' => 'custom', 'source_id' => null, 'url' => '#', 'target' => '_self'];

            $attributes = [
                'lang_iso' => LanguageEnum::Spanish->value,
                'title' => $slide['title'],
                'subtitle' => $slide['subtitle'],
                'background_type' => SlideBackgroundTypeEnum::Image->value,
                'has_presentation_video' => false,
                'links' => [$link],
                'properties' => $this->baseProperties($slide['position_container'], $slide['align_content']),
                'is_active' => true,
            ];

            // Fondo desde `media` (2026-08-31, patrón nuevo — ver
            // `Cliente0MediaSeeder`). Solo se agrega la FK si el `Media`
            // correspondiente ya fue sembrado: si `Cliente0MediaSeeder` no
            // corrió antes (o el archivo no existe en disco) la clave se
            // omite del todo, así una corrida de `db:seed` sin ese seeder
            // NUNCA pisa con `null` una imagen que el Tech Lead haya
            // elegido a mano en Studio para esta slide.
            $mediaId = Cliente0MediaSeeder::mediaId($tenant, $slide['background_file']);

            if ($mediaId !== null) {
                // Mismo fondo para los 3 breakpoints por ahora (2026-08-31,
                // pedido explícito: "se repetiría el mismo fondo para
                // mobile/tablet/desktop por ahora") — no hay todavía
                // variantes recortadas por dispositivo.
                $attributes['image_desktop_id'] = $mediaId;
                $attributes['image_tablet_id'] = $mediaId;
                $attributes['image_mobile_id'] = $mediaId;
            }

            $slider->slides()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'sort_order' => $sortOrder,
                ],
                $attributes
            );
        }
    }
}
