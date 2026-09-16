<?php

namespace App\Filament\Pages;

use App\Enums\LanguageEnum;
use App\Filament\Schemas\MediaUpload;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantManager;
use BackedEnum;
use DateTimeZone;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
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
 * de ubicación.
 *
 * 2026-09-16 (pedido explícito del Tech Lead): se suma "Identidad del Proyecto (Tenant)"
 * permitiendo personalizar nombre y slug del tenant, restringido a 1 cambio
 * (o cuando se le habilite desde Platform Manager).
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

    protected ?string $subheading = 'Identidad del proyecto, cómo se muestran las fechas y textos en Console, valores de SEO/Open Graph por defecto e integraciones.';

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
        $tenant = Filament::getTenant() ?? $user?->tenant;

        if ($tenant instanceof Tenant && ! app(TenantManager::class)->hasTenant()) {
            app(TenantManager::class)->setTenant($tenant);
        }

        $this->form->fill([
            'tenant_name' => $tenant?->name,
            'tenant_slug' => $tenant?->slug,
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
        $tenant = Filament::getTenant() ?? auth()->user()?->tenant;

        return $schema
            ->statePath('data')
            ->columns(2)
            ->components([
                Section::make('Identidad del Proyecto (Tenant)')
                    ->description('Nombre visible e identificador web único (slug / permalink) de tu proyecto.')
                    ->collapsible()
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Forms\Components\Placeholder::make('slug_info_banner')
                            ->hiddenLabel()
                            ->content(function () use ($tenant): HtmlString {
                                if ($tenant?->canChangeSlug()) {
                                    $currentSlug = e($tenant->slug ?? '');

                                    return new HtmlString('
                                        <div class="rounded-lg border border-amber-200 bg-amber-50/80 p-3.5 text-xs text-amber-800 dark:border-amber-800/60 dark:bg-amber-950/40 dark:text-amber-300">
                                            <div class="flex items-start gap-2.5">
                                                <svg class="w-4 h-4 mt-0.5 text-amber-600 dark:text-amber-400 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                    <path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .244.304l-.459 2.066A1.75 1.75 0 0 0 10.747 15H11a.75.75 0 0 0 0-1.5h-.253a.25.25 0 0 1-.244-.304l.459-2.066A1.75 1.75 0 0 0 9.253 9H9Z" clip-rule="evenodd" />
                                                </svg>
                                                <div>
                                                    <span class="font-semibold">Personalización de Identificador (1 cambio disponible):</span>
                                                    <p class="mt-0.5 text-amber-700/90 dark:text-amber-300/90 leading-relaxed">
                                                        Puedes personalizar el nombre y slug de tu proyecto <strong>1 sola vez</strong>. Ten en cuenta que modificar el slug actualizará de inmediato la dirección de acceso a tu Studio (<code>/'.$currentSlug.'</code>) y las rutas de tu API pública. Al guardar serás redirigido a la nueva URL.
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    ');
                                }

                                return new HtmlString('
                                    <div class="rounded-lg border border-gray-200 bg-gray-50/80 p-3.5 text-xs text-gray-700 dark:border-gray-800 dark:bg-gray-900/40 dark:text-gray-300">
                                        <div class="flex items-start gap-2.5">
                                            <svg class="w-4 h-4 mt-0.5 text-gray-500 dark:text-gray-400 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M10 1a4.5 4.5 0 0 0-4.5 4.5V9H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-.5V5.5A4.5 4.5 0 0 0 10 1Zm3 8V5.5a3 3 0 1 0-6 0V9h6Z" clip-rule="evenodd" />
                                            </svg>
                                            <div>
                                                <span class="font-semibold">Identificador de proyecto permanente (1/1 cambios utilizados):</span>
                                                <p class="mt-0.5 text-gray-600 dark:text-gray-400 leading-relaxed">
                                                    El slug actual está fijado para proteger tus enlaces web y la integración con la API. Si necesitas una actualización adicional, puedes solicitar una habilitación especial a través de Platform Manager.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                ');
                            })
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('tenant_name')
                            ->label('Nombre del proyecto')
                            ->helperText('Nombre visible de tu proyecto en el encabezado y reportes.')
                            ->required()
                            ->maxLength(100)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, Get $get, ?string $state) use ($tenant) {
                                if ($tenant?->canChangeSlug()) {
                                    $set('tenant_slug', Str::slug($state ?? ''));
                                }
                            }),

                        Forms\Components\TextInput::make('tenant_slug')
                            ->label('Identificador (Slug / Permalink)')
                            ->helperText(fn () => $tenant?->canChangeSlug()
                                ? 'Identificador en minúsculas y guiones. Se usa en la URL de Studio y en la API.'
                                : '🔒 Modificación bloqueada (se requiere habilitación desde Platform Manager).')
                            ->prefix(rtrim(config('stamless.urls.studio'), '/').'/')
                            ->required()
                            ->maxLength(50)
                            ->disabled(fn () => ! ($tenant?->canChangeSlug() ?? false))
                            ->dehydrated(),
                    ]),

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
                    $tenant = Filament::getTenant() ?? $user?->tenant;
                    $oldSlug = $tenant?->slug;
                    $slugChanged = false;

                    if ($tenant instanceof Tenant && ! app(TenantManager::class)->hasTenant()) {
                        app(TenantManager::class)->setTenant($tenant);
                    }

                    // 1. Actualización de Tenant (Nombre y Slug)
                    if ($tenant instanceof Tenant) {
                        $newName = trim($data['tenant_name'] ?? '');
                        $newSlug = Str::slug(trim($data['tenant_slug'] ?? ''));

                        if (filled($newName) && $newName !== $tenant->name) {
                            $tenant->name = $newName;
                            setting(['site_name' => $newName]);
                        }

                        if ($tenant->canChangeSlug() && filled($newSlug) && $newSlug !== $oldSlug) {
                            // Validar unicidad
                            $exists = Tenant::where('slug', $newSlug)->where('id', '!=', $tenant->id)->exists();
                            if ($exists) {
                                Notification::make()
                                    ->title('El identificador (slug) ya está en uso')
                                    ->danger()
                                    ->body("El slug '{$newSlug}' ya pertenece a otro proyecto. Por favor elige otro.")
                                    ->send();

                                return;
                            }

                            $tenant->slug = $newSlug;
                            $tenant->slug_changes_count = ($tenant->slug_changes_count ?? 0) + 1;
                            $slugChanged = true;
                        }

                        $tenant->save();
                    }

                    // 2. Actualización de Usuario
                    $user->update([
                        'locale' => $data['locale'],
                        'timezone' => $data['timezone'],
                    ]);

                    // 3. Configuración del Sitio (Settings)
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

                    if ($slugChanged && $tenant instanceof Tenant) {
                        Notification::make()
                            ->title('Identificador y preferencias actualizados')
                            ->body("El proyecto ahora tiene el slug '{$tenant->slug}'. Redirigiendo a la nueva URL...")
                            ->success()
                            ->send();

                        $this->redirect(Filament::getPanel('cms')->getUrl($tenant));

                        return;
                    }

                    Notification::make()
                        ->title('Preferencias guardadas')
                        ->success()
                        ->send();
                }),
        ];
    }
}
