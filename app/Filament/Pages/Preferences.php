<?php

namespace App\Filament\Pages;

use App\Enums\LanguageEnum;
use App\Filament\Schemas\MediaUpload;
use App\Models\User;
use BackedEnum;
use DateTimeZone;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * Preferencias de visualización del usuario admin (idioma + zona horaria)
 * — ver ADR-021. Afecta cómo `App\Support\FriendlyDate` formatea TODAS
 * las fechas de Console para este usuario. No tiene nada que ver con
 * `lang_iso` del contenido del tenant (eso sigue siendo siempre `es` en
 * el MVP, ver ADR-008/LanguageEnum) — son dos cosas distintas que
 * comparten el mismo catálogo de idiomas por conveniencia.
 *
 * 2026-09-13 (pedido explícito del Tech Lead, ver ADR-065): esta página
 * también aloja "Metadata SEO" y "Open Graph (Redes Sociales)" — a
 * diferencia de `locale`/`timezone` de arriba (por-USUARIO, columnas en
 * `User`), esos 7 campos son TENANT-WIDE (guardados vía `Setting`/
 * `setting()`, ver `SettingService`) y sirven de FALLBACK a nivel de API
 * para cualquier Page/Legal/Service/Post que no defina su propio
 * `meta.seo_*`/`meta.og_*` (ver `ResolvesPublicLinks::attachResolvedSeoMeta()`).
 * Mezclar un scope por-usuario con uno por-tenant en la misma página es a
 * propósito una concesión de UX (un solo lugar en el menú del perfil,
 * ver `PanelCmsProvider::userMenuItems()`) sobre la separación de
 * responsabilidades "ideal" (dos páginas distintas) — el Tech Lead pidió
 * expresamente ubicarlas acá, no en una página nueva.
 *
 * 2026-09-13, misma vuelta: se suma "Integraciones" (Meta Pixel ID / Google
 * Tag Manager ID) — mismo mecanismo (`Setting`, tenant-wide), mismo criterio
 * de ubicación. A diferencia de SEO/OG (fallback de CONTENIDO por página),
 * estos 2 IDs son de configuración de SITIO completo (se inyectan en el
 * `<head>`/`<body>` de TODAS las páginas del frontend) — no hay noción de
 * "por página" que pueda pisarlos, así que no aplica el patrón fallback de
 * `attachResolvedSeoMeta()`. **Guardado únicamente en esta vuelta**: la
 * exposición pública (dónde/cómo los consume `cica360`, si vía un endpoint
 * nuevo `GET /v1/{tenant}/site` o embebido en otro recurso existente) queda
 * fuera de alcance — no pedida todavía, evitar inventar un endpoint sin
 * consumidor confirmado.
 */
class Preferences extends Page implements HasForms
{
    use InteractsWithForms;

    /**
     * Ya no vive en el sidebar (grupo "Cuenta"): desde que se agregó al
     * menú del avatar (`PanelCmsProvider::userMenuItems()`), mostrarla en
     * ambos lados era redundante — se pidió sacarla del sidebar.
     */
    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Cuenta';

    protected static ?string $navigationLabel = 'Preferencias';

    protected static ?string $title = 'Preferencias';

    protected ?string $subheading = 'Cómo se muestran las fechas y los textos en Console, los valores de SEO/Open Graph por defecto del sitio y las integraciones de analytics.';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?int $navigationSort = -10;

    protected string $view = 'filament.pages.preferences';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();

        $this->form->fill([
            'locale' => $user->locale,
            'timezone' => $user->timezone,
            // Tenant-wide, vía `setting()` — no columnas de `User` (ver
            // docblock de la clase).
            'seo_default_title' => setting('seo.default_title'),
            'seo_default_keywords' => setting('seo.default_keywords'),
            'seo_default_description' => setting('seo.default_description'),
            'og_default_title' => setting('og.default_title'),
            'og_default_description' => setting('og.default_description'),
            'og_default_image_rect_id' => setting('og.default_image_rect_id'),
            'og_default_image_square_id' => setting('og.default_image_square_id'),
            // Integraciones de analytics — mismo mecanismo, ver docblock.
            'tracking_meta_pixel_id' => setting('tracking.meta_pixel_id'),
            'tracking_gtm_id' => setting('tracking.gtm_id'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            // 2026-09-13 (ADR-065): antes esta página completa vivía metida a
            // mano dentro de un `<div class="gnss-card" style="max-width:
            // 32rem">` (ver `preferences.blade.php`) — tenía sentido cuando
            // solo eran 2 campos sueltos (idioma/zona horaria), pero con las
            // Sections nuevas (bastante más contenido: SEO + OG con
            // imágenes, integraciones) todo quedaba apretado en una sola
            // columna angosta, en vez de leer como tarjetas independientes
            // tipo el resto de Studio (`PageResource`, etc.). El wrapper
            // angosto se sacó del blade; acá se arma el layout real: 4
            // `Section` en un `Grid` de 2 columnas — "Cuenta" e
            // "Integraciones" lado a lado arriba, "Metadata SEO" y "Open
            // Graph" lado a lado abajo (se apilan solas en mobile, el `Grid`
            // de Filament ya es responsive).
            ->columns(2)
            ->components([
                Section::make('Cuenta')
                    ->description('Cómo se muestran las fechas y los textos en Console.')
                    ->collapsible()
                    ->schema([
                        Forms\Components\Select::make('locale')
                            ->label('Idioma')
                            ->options(collect(LanguageEnum::cases())->mapWithKeys(
                                fn (LanguageEnum $case): array => [$case->value => $case->getLabel()],
                            ))
                            ->required(),

                        Forms\Components\Select::make('timezone')
                            ->label('Zona horaria')
                            ->helperText('Todas las fechas de Console (tokens, contenido, etc.) se muestran convertidas a esta zona.')
                            ->options(collect(DateTimeZone::listIdentifiers())->mapWithKeys(
                                fn (string $tz): array => [$tz => $tz],
                            ))
                            ->searchable()
                            ->required(),
                    ]),

                // 2026-09-13, misma vuelta que SEO/OG (ver docblock de la
                // clase): configuración de SITIO completo, no fallback de
                // contenido — sin `->required()`, un tenant puede no usar
                // ninguna de las 2 integraciones.
                Section::make('Integraciones')
                    ->description('IDs de medición para el sitio público. Se dejan vacíos si el tenant no usa Meta Pixel o Google Tag Manager.')
                    ->collapsible()
                    ->schema([
                        Forms\Components\TextInput::make('tracking_meta_pixel_id')
                            ->label('Meta Pixel ID')
                            ->helperText('ID numérico del píxel de Meta/Facebook Ads (Events Manager).')
                            ->placeholder('1234567890123456')
                            ->maxLength(32),

                        Forms\Components\TextInput::make('tracking_gtm_id')
                            ->label('Google Tag Manager ID')
                            ->helperText('Formato GTM-XXXXXXX (ID del contenedor).')
                            ->placeholder('GTM-XXXXXXX')
                            ->maxLength(20),
                    ]),

                // 2026-09-13 (ADR-065): mismos campos/labels que el tab "SEO /
                // Enlaces" de Page/Post/Service (ver `PageResource::form()`) —
                // acá son el valor POR DEFECTO del tenant, no de una página
                // puntual. Sin `->required()`: es válido no definir ningún
                // default (la página/publicación/servicio simplemente no
                // recibe fallback, `meta.seo_*` sale `null` como hasta ahora).
                Section::make('Metadata SEO (por defecto del sitio)')
                    ->description('Se usa cuando una página, publicación o servicio no define su propio título/descripción SEO.')
                    ->collapsible()
                    ->schema([
                        Forms\Components\TextInput::make('seo_default_title')
                            ->label('Título SEO')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('seo_default_keywords')
                            ->label('Palabras Clave (Separadas por comas)')
                            ->maxLength(255),

                        Forms\Components\Textarea::make('seo_default_description')
                            ->label('Descripción SEO')
                            ->maxLength(500),
                    ]),

                Section::make('Open Graph (Redes Sociales) — por defecto del sitio')
                    ->description('Se usa cuando una página, publicación o servicio no define su propio título/descripción/imagen para compartir en redes.')
                    ->collapsible()
                    ->schema([
                        Forms\Components\TextInput::make('og_default_title')
                            ->label('Título OG')
                            ->maxLength(255),

                        Forms\Components\Textarea::make('og_default_description')
                            ->label('Descripción OG')
                            ->maxLength(500),

                        MediaUpload::make('og_default_image_rect_id', 'Imagen OG Rectangular (1200x630)'),
                        MediaUpload::make('og_default_image_square_id', 'Imagen OG Cuadrada (600x600)'),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('save')
                ->label('Guardar')
                ->icon('heroicon-o-check')
                ->action(function (): void {
                    $data = $this->form->getState();

                    /** @var User $user */
                    $user = auth()->user();

                    $user->update([
                        'locale' => $data['locale'],
                        'timezone' => $data['timezone'],
                    ]);

                    // `setting([...])` = `SettingService::setMany()` — un
                    // `updateOrCreate()` por clave, tenant-scoped por
                    // `HasTenant`, invalida el cache del tenant vía
                    // `SettingObserver` (ver docblock de la clase).
                    setting([
                        'seo.default_title' => $data['seo_default_title'] ?? null,
                        'seo.default_keywords' => $data['seo_default_keywords'] ?? null,
                        'seo.default_description' => $data['seo_default_description'] ?? null,
                        'og.default_title' => $data['og_default_title'] ?? null,
                        'og.default_description' => $data['og_default_description'] ?? null,
                        'og.default_image_rect_id' => $data['og_default_image_rect_id'] ?? null,
                        'og.default_image_square_id' => $data['og_default_image_square_id'] ?? null,
                        'tracking.meta_pixel_id' => $data['tracking_meta_pixel_id'] ?? null,
                        'tracking.gtm_id' => $data['tracking_gtm_id'] ?? null,
                    ]);

                    Notification::make()
                        ->title('Preferencias guardadas')
                        ->success()
                        ->send();
                }),
        ];
    }
}
