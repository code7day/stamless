<?php

namespace App\Filament\Pages;

use App\Enums\LanguageEnum;
use App\Enums\UserRoleEnum;
use App\Filament\Concerns\RestrictsPageToRoles;
use App\Filament\Schemas\MediaUpload;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantManager;
use BackedEnum;
use DateTimeZone;
use Filament\Actions;
use Filament\Actions\Action;
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
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;
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
    use RestrictsPageToRoles;

    /**
     * Ya no vive en el sidebar (grupo "Cuenta"): desde que se agregó al
     * menú del avatar (`PanelCmsProvider::userMenuItems()`), mostrarla en
     * ambos lados era redundante — se pidió sacarla del sidebar.
     */
    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Cuenta';

    protected static ?string $navigationLabel = 'Preferencias';

    protected static ?string $title = 'Preferencias';

    protected ?string $subheading = 'Identidad del proyecto (nombre, slug y logo), cómo se muestran las fechas y textos en Console, valores de SEO/Open Graph por defecto e integraciones.';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?int $navigationSort = -10;

    protected string $view = 'filament.pages.preferences';

    /**
     * `Admin`/`Editor` — no `Author`. Esta página mezcla preferencias
     * personales (idioma/zona horaria) con ajustes tenant-wide (identidad
     * del proyecto, SEO/OG, integraciones, SMTP, despliegue — ver docblock
     * de la clase); ninguno de esos grupos está en la lista de recursos que
     * el Tech Lead definió para `Author` (contenidos/blog/servicios/
     * testimonios). Efecto secundario conocido y aceptado: un `Author`
     * tampoco puede cambiar su propio idioma/zona horaria desde acá en este
     * MVP — no hay hoy una página separada solo para eso. Ver
     * `RestrictsPageToRoles` y ADR-078.
     */
    public static function canAccess(): bool
    {
        return static::userHasAnyRole([
            UserRoleEnum::Admin->value,
            UserRoleEnum::Editor->value,
        ]);
    }

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
            // Logo del tenant (2026-09-18) — mismo mecanismo que las
            // imágenes OG de arriba: `Setting` guarda el `id` de un
            // `Media`, ver docblock de la sección "Marca" más abajo.
            'branding_logo_id' => setting('branding.logo_id'),
            // "Página de Agradecimiento (Formularios)" TRASLADADA a
            // `FormResource` (2026-09-18, ADR-074) — ya no vive acá.

            // SMTP propio del tenant (2026-09-18) — a diferencia de todo lo
            // demás de arriba, esto NO vive en `Setting` (que no cifra su
            // columna `value`): son columnas reales de `Tenant`, mismo
            // mecanismo que `deploy_token` (ver `Tenant::casts()`).
            // `smtp_password` queda A PROPÓSITO fuera de este `fill()` —
            // nunca se manda la contraseña ya guardada de vuelta al
            // navegador; el campo se muestra vacío y solo se sobreescribe
            // si el tenant escribe una nueva (ver `getHeaderActions()`).
            'smtp_host' => $tenant?->smtp_host,
            'smtp_port' => $tenant?->smtp_port,
            'smtp_username' => $tenant?->smtp_username,
            'smtp_encryption' => $tenant?->smtp_encryption,
            'smtp_from_address' => $tenant?->smtp_from_address,
            'smtp_from_name' => $tenant?->smtp_from_name,

            // Despliegue automático (Git) (2026-09-18, 2da actualización) —
            // antes solo lo cargaba el operador vía tinker; el propio
            // tenant lo vincula ahora desde acá. Mismo criterio de
            // enmascarado que `smtp_password`: `deploy_token` queda A
            // PROPÓSITO fuera de este `fill()`, nunca vuelve al navegador
            // (ver `getHeaderActions()` para el "dejar vacío = no cambiar").
            'deploy_repo' => $tenant?->deploy_repo,
            'deploy_enabled' => $tenant?->deploy_enabled ?? false,
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

                // 2026-09-18, pedido del Tech Lead (viendo el email de
                // notificación de un nuevo contacto sin marca propia,
                // genérico "Stamless"): "el logo debería personalizarse en
                // preferencias donde se personaliza el tenant, sería
                // bueno... así como el logo del sitio cliente esté en los
                // mailings". Mismo mecanismo que las imágenes OG de más
                // abajo (`Setting` guardando el `id` de un `Media`) — el
                // valor se lee en `ContactSubmissionService::resolveBrandLogoUrl()`
                // para el header de ambos emails (notificación al admin y
                // copia al usuario, ver ADR de este mismo día). A
                // propósito tenant-wide (no por `Form`): es identidad
                // visual del proyecto, no configuración puntual de un
                // formulario.
                Section::make('Marca')
                    ->description('Logo de tu proyecto — se usa en los emails de notificación de formularios.')
                    ->collapsible()
                    ->schema([
                        MediaUpload::make('branding_logo_id', 'Logo del proyecto', accept: 'logo', helperText: 'PNG, JPG, WEBP o SVG (recomendado para que se vea nítido a cualquier tamaño). Fondo transparente, máx. 5MB. Si no se sube uno, los emails muestran el nombre del proyecto en texto.'),
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
                    ->columnSpanFull()
                    ->columns(2)
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

                // 2026-09-18, pedido del Tech Lead: "falta la sección de
                // configuración para ingresar los datos para configurar su
                // SMTP favorito y será mejor para evitar usar mi smtp
                // general para todo". Opcional — sin completar, los emails
                // de este tenant (notificación de formularios, ver
                // `ContactSubmissionService`) siguen usando el `MAIL_MAILER`
                // de la plataforma, sin cambio de comportamiento (ver
                // `Tenant::hasCustomSmtpConfigured()`). A diferencia de todo
                // lo demás de esta página, esto NO se guarda vía `setting()`
                // — son columnas reales de `Tenant` con `smtp_password`
                // cifrada at-rest (mismo criterio que `deploy_token`), no un
                // valor plano en la tabla `settings`. Movida al final de la
                // página (2026-09-18, 4ta actualización, pedido del Tech
                // Lead) — sección técnica/de infraestructura, no de
                // contenido/negocio del sitio; queda agrupada junto a
                // "Despliegue automático (Git)", la otra sección de este
                // mismo tipo.
                Section::make('SMTP propio (opcional)')
                    ->description('Para que los emails de notificación de tus formularios salgan desde tu propio servidor de correo, con tu dominio, en vez del SMTP compartido de la plataforma. Dejar vacío para seguir usando el de Stamless.')
                    ->collapsible()
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('smtp_host')
                            ->label('Host')
                            ->placeholder('smtp.tudominio.com')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('smtp_port')
                            ->label('Puerto')
                            ->numeric()
                            ->placeholder('587')
                            ->minValue(1)
                            ->maxValue(65535),

                        Forms\Components\TextInput::make('smtp_username')
                            ->label('Usuario')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('smtp_password')
                            ->label('Contraseña')
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->helperText(fn (): ?string => filled(Filament::getTenant()?->smtp_password ?? null)
                                ? 'Ya hay una contraseña guardada — dejar vacío la mantiene sin cambios.'
                                : null),

                        Forms\Components\Select::make('smtp_encryption')
                            ->label('Cifrado')
                            ->options([
                                'tls' => 'TLS',
                                'ssl' => 'SSL',
                            ])
                            ->placeholder('Ninguno'),

                        Forms\Components\TextInput::make('smtp_from_address')
                            ->label('Email remitente (From)')
                            ->email()
                            ->placeholder('notificaciones@tudominio.com')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('smtp_from_name')
                            ->label('Nombre remitente')
                            ->placeholder('Se usa el nombre del proyecto si se deja vacío')
                            ->maxLength(255),
                    ])
                    ->headerActions([
                        Action::make('test_smtp_connection')
                            ->label('Probar conexión')
                            ->icon('heroicon-o-paper-airplane')
                            ->color('gray')
                            ->action(function (Get $get): void {
                                $this->testSmtpConnection($get);
                            }),
                    ]),

                // 2026-09-18 (2da actualización), pedido del Tech Lead: "no
                // debería poder el cliente, para su tenant, vincular a git
                // para que pueda hacer el envío si es necesario usar el
                // trigger a git para automatizar, si no no habilita el
                // check de automatización" — hasta ahora `deploy_repo`/
                // `deploy_token` los cargaba el operador de la plataforma a
                // mano vía tinker (ver ADR "Fase 6 post-MVP: deploy webhook
                // por tenant"). El propio tenant los vincula ahora desde
                // acá. El checkbox "Automatización activa" (`deploy_enabled`)
                // queda `disabled()` — no se puede tildar — hasta que AMBOS
                // campos (repo + token, ya sea recién tipeados en esta
                // sesión o ya guardados de antes) estén completos; ver
                // `Tenant::hasAutoDeployActive()`, el gate real que
                // consultan `DeployTriggerObserver`/`TriggerFrontendDeploy`
                // antes de disparar cualquier rebuild. Igual criterio de
                // "dejar vacío no borra" que `smtp_password` para el token,
                // que nunca vuelve al navegador (ver `mount()`/
                // `getHeaderActions()`). Movida al final de la página junto
                // a "SMTP propio" (2026-09-18, 4ta actualización, mismo
                // pedido del Tech Lead) — ambas son secciones técnicas/de
                // infraestructura, no de contenido/negocio del sitio.
                Section::make('Despliegue automático (Git)')
                    ->description('Vincular el repositorio de GitHub del sitio público para que se reconstruya y publique solo cada vez que se guarda contenido en Studio. Dejar vacío si el sitio no usa este mecanismo — nada cambia.')
                    ->collapsible()
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('deploy_repo')
                            ->label('Repositorio (owner/repo)')
                            ->placeholder('usuario-u-organizacion/nombre-del-repositorio')
                            ->helperText('Formato exacto de GitHub, ej. "usuario/repositorio".')
                            ->maxLength(255)
                            ->live(onBlur: true),

                        Forms\Components\TextInput::make('deploy_token')
                            ->label('Token de acceso (Personal Access Token)')
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->helperText(fn (): ?string => filled(Filament::getTenant()?->deploy_token ?? null)
                                ? 'Ya hay un token guardado — dejar vacío lo mantiene sin cambios.'
                                : 'Con permiso "repo" (o, más acotado, "contents:read" + "actions:write" si es un Fine-grained PAT). Nunca se muestra de vuelta ni se expone en la API pública.'),

                        Forms\Components\Toggle::make('deploy_enabled')
                            ->label('Automatización activa')
                            ->helperText(fn (Get $get): string => self::hasDeployCredentials($get)
                                ? 'Cada vez que se guarda contenido, el sitio se reconstruye y publica solo.'
                                : 'Completar repositorio y token para poder activarla.')
                            ->disabled(fn (Get $get): bool => ! self::hasDeployCredentials($get))
                            ->columnSpanFull(),
                    ]),

                // 2026-09-18: "Página de Agradecimiento (Formularios)" se
                // TRASLADÓ de acá a `FormResource` — pedido del Tech Lead
                // al ver que ahora los formularios son gestionables por
                // tenant (Fase 1, ADR-073): "trasladar el contenido de la
                // página gracias que sea dinámica... hay que trasladarlo
                // ahora al formulario para personalizar los textos ahí
                // mismo". Tenía sentido como setting único mientras solo
                // existía UN formulario real (`contacto`) por tenant, pero
                // deja de tenerlo con múltiples formularios por tenant: un
                // valor tenant-wide no puede dar una página de gracias
                // distinta por formulario. Los 5 campos (`thank_you_title`/
                // `thank_you_description`/`thank_you_alert_title`/
                // `thank_you_alert_description`/`thank_you_button_label`)
                // ahora viven como columnas de `Form` — ver
                // `FormResource::form()`, sección "Página de Agradecimiento".
                // Los `Setting` `thank_you.*` que ya existieran de esta
                // pantalla quedan huérfanos en la tabla `settings` (sin
                // lectura en ningún lado desde este cambio) — no se
                // migran/borran automáticamente, ver ADR-074.
            ]);
    }

    /**
     * "Probar conexión" del SMTP propio (ver sección homónima en `form()`)
     * — manda un email real de prueba con los datos que el tenant tiene
     * TIPEADOS en ese momento (`Get $get`, estado en vivo del formulario,
     * SIN guardar primero) al email del admin logueado. Se replica acá el
     * mismo mecanismo interno que usa Laravel para construir un transporte
     * SMTP (`Illuminate\Mail\MailManager::createSmtpTransport()` —
     * `Symfony\Mailer\Transport\Smtp\EsmtpTransportFactory` + `Dsn`) en vez
     * de armar una DSN a mano con `sprintf()`: evita bugs de escape si el
     * usuario/contraseña tienen caracteres especiales (`@`, `:`, etc.).
     *
     * Si el campo "Contraseña" quedó vacío (el tenant no lo tocó, ver
     * helper text del campo), se usa la ya guardada en `Tenant::smtp_password`
     * — mismo criterio de "dejar vacío no borra" que el resto de esta
     * sección.
     *
     * El scheme ('smtp' vs 'smtps') se deriva del select "Cifrado" con el
     * MISMO criterio que `Tenant::smtpMailerConfig()` (ver ese método —
     * Symfony Mailer no tiene una opción `encryption` suelta, decide
     * TLS/SSL por el scheme de la Dsn).
     */
    private function testSmtpConnection(Get $get): void
    {
        $host = trim((string) $get('smtp_host'));
        $username = trim((string) $get('smtp_username'));

        if (blank($host) || blank($username)) {
            Notification::make()
                ->title('Completar al menos Host y Usuario antes de probar.')
                ->warning()
                ->send();

            return;
        }

        $tenant = Filament::getTenant() ?? auth()->user()?->tenant;
        $password = trim((string) $get('smtp_password'));
        if (blank($password)) {
            $password = (string) ($tenant?->smtp_password ?? '');
        }

        $port = (int) ($get('smtp_port') ?: 587);
        $encryption = $get('smtp_encryption') ?: null;
        $fromAddress = trim((string) $get('smtp_from_address')) ?: $username;
        $fromName = trim((string) $get('smtp_from_name')) ?: ($tenant?->name ?? config('app.name'));

        /** @var User $adminUser */
        $adminUser = auth()->user();

        try {
            $scheme = $encryption === 'ssl' ? 'smtps' : 'smtp';

            $factory = new EsmtpTransportFactory;
            $transport = $factory->create(new Dsn(
                $scheme,
                $host,
                $username,
                $password,
                $port,
            ));

            $email = (new Email)
                ->from(new Address($fromAddress, $fromName))
                ->to($adminUser->email)
                ->subject('Prueba de SMTP — Stamless Studio')
                ->text('Si recibiste este correo, tu SMTP propio quedó bien configurado. Los emails de notificación de tus formularios ahora van a salir desde acá.');

            $transport->send($email);

            Notification::make()
                ->title('Conexión exitosa')
                ->body("Se envió un email de prueba a {$adminUser->email}. Revisar la bandeja de entrada (y spam).")
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title('No se pudo conectar')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    /**
     * ¿El tenant tiene repo Y token para el deploy automático? Combina el
     * estado EN VIVO del formulario (lo que el tenant tipeó recién, todavía
     * sin guardar) con lo YA GUARDADO en `Tenant::deploy_token` — necesario
     * porque `deploy_token` nunca se pre-carga en el formulario (ver
     * `mount()`, mismo enmascarado que `smtp_password`): sin este segundo
     * chequeo, reabrir esta página con un token ya guardado mostraría el
     * checkbox "Automatización activa" bloqueado por error, aunque el
     * tenant ya tenga todo configurado. `deploy_repo`, al no ser secreto,
     * SÍ se pre-carga — con leer `$get('deploy_repo')` alcanza.
     */
    private static function hasDeployCredentials(Get $get): bool
    {
        $tenant = Filament::getTenant() ?? auth()->user()?->tenant;

        $hasRepo = filled($get('deploy_repo'));
        $hasToken = filled($get('deploy_token')) || filled($tenant?->deploy_token);

        return $hasRepo && $hasToken;
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

                        // SMTP propio (2026-09-18) — columnas reales de
                        // `Tenant`, no `Setting` (ver docblock de la
                        // sección en `form()`). `smtp_password` es el único
                        // campo con lógica especial: si llegó vacío, el
                        // tenant no lo tocó — se conserva la contraseña que
                        // ya estaba guardada (cifrada) en vez de borrarla.
                        $tenant->smtp_host = filled($data['smtp_host'] ?? null) ? trim($data['smtp_host']) : null;
                        $tenant->smtp_port = filled($data['smtp_port'] ?? null) ? (int) $data['smtp_port'] : null;
                        $tenant->smtp_username = filled($data['smtp_username'] ?? null) ? trim($data['smtp_username']) : null;
                        $tenant->smtp_encryption = $data['smtp_encryption'] ?? null;
                        $tenant->smtp_from_address = filled($data['smtp_from_address'] ?? null) ? trim($data['smtp_from_address']) : null;
                        $tenant->smtp_from_name = filled($data['smtp_from_name'] ?? null) ? trim($data['smtp_from_name']) : null;

                        if (filled($data['smtp_password'] ?? null)) {
                            $tenant->smtp_password = $data['smtp_password'];
                        }

                        // Despliegue automático (Git) (2026-09-18, 2da
                        // actualización) — mismo criterio "dejar vacío no
                        // borra" que `smtp_password` para `deploy_token`.
                        // `deploy_enabled` se fuerza a `false` si, tras
                        // aplicar los cambios de este guardado, el tenant
                        // NO termina con repo+token completos — defensa en
                        // profundidad además del `disabled()` del checkbox
                        // en el formulario (ver `hasDeployCredentials()`):
                        // un campo `disabled()` en Filament puede seguir
                        // dehidratando su último valor conocido según el
                        // estado del navegador, así que la fuente de verdad
                        // real de "¿puede estar activo?" se revalida acá,
                        // contra el modelo ya actualizado.
                        $tenant->deploy_repo = filled($data['deploy_repo'] ?? null) ? trim($data['deploy_repo']) : null;

                        if (filled($data['deploy_token'] ?? null)) {
                            $tenant->deploy_token = $data['deploy_token'];
                        }

                        $tenant->deploy_enabled = (bool) ($data['deploy_enabled'] ?? false) && $tenant->hasDeployWebhookConfigured();

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
                        'branding.logo_id' => $data['branding_logo_id'] ?? null,
                        // `thank_you.*` TRASLADADO a `Form::thank_you_*`
                        // (2026-09-18, ADR-074) — ya no se escribe acá.
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
