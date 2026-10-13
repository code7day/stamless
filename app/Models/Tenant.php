<?php

namespace App\Models;

use App\Enums\PageTypeEnum;
use App\Enums\PlanEnum;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Tenant extends Model
{
    use HasUuid;

    protected $fillable = [
        'name',
        'slug',
        'uuid',
        'short_hash',
        'is_active',
        'plan',
        'slug_changes_count',
        'slug_changes_allowed',
        'deploy_repo',
        // 2026-09-18 (2da actualización): `deploy_token` pasa a `$fillable`
        // — el propio tenant lo vincula desde la sección "Despliegue
        // automático (Git)" de `Preferences.php`, ya no es exclusivo del
        // operador vía tinker (ver docblock de `$hidden` más abajo, que
        // sigue aplicando: nunca se expone en un `toArray()`/`toJson()`
        // accidental, aunque sí sea mass-assignable). `deploy_enabled`
        // (el checkbox "Automatización activa") viaja acá también — es un
        // booleano de negocio, no una credencial, no necesita `$hidden`.
        'deploy_token',
        'deploy_enabled',
        // El SMTP propio sigue el mismo criterio de auto-gestión por el
        // tenant desde `Preferences.php` — por eso vive en `$fillable`.
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_password',
        'smtp_encryption',
        'smtp_from_address',
        'smtp_from_name',
    ];

    /**
     * `deploy_token` (PAT de GitHub, ver migración de deploy webhook) es
     * `$fillable` desde 2026-09-18 (2da actualización) — el propio tenant lo
     * carga desde `Preferences.php` — pero se mantiene en `$hidden` porque
     * sigue siendo una credencial, no un dato de negocio: aunque el modelo
     * `Tenant` no se expone hoy en ninguna API Resource, cualquier futuro
     * `toArray()`/`toJson()` accidental no debe filtrarlo. Mismo criterio
     * que `smtp_password` (mass-assignable, pero nunca serializable).
     */
    protected $hidden = [
        'deploy_token',
        'smtp_password',
    ];

    /**
     * The "booted" method of the model.
     *
     * `short_hash` tiene además un default calculado a nivel de Postgres
     * (ver migración), pero se asigna aquí explícitamente para que el
     * atributo esté disponible en memoria inmediatamente después de crear
     * el modelo (mismo patrón que `HasUuid::bootHasUuid`).
     */
    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant) {
            if (empty($tenant->short_hash)) {
                $tenant->short_hash = Str::lower(Str::random(12));
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'slug_changes_count' => 'integer',
            'slug_changes_allowed' => 'integer',
            // Mismo criterio que `Contact::email/phone/company` — el PAT de
            // GitHub es un secreto, no un dato de negocio, se cifra at-rest.
            'deploy_token' => 'encrypted',
            'deploy_enabled' => 'boolean',
            'smtp_port' => 'integer',
            // Mismo criterio: la contraseña SMTP se cifra at-rest.
            'smtp_password' => 'encrypted',
        ];
    }

    /**
     * Indica si el tenant tiene disponible el permiso para modificar su slug.
     * Se permite 1 cambio inicial por defecto, o los cambios que se habiliten
     * desde Platform Manager en el futuro.
     */
    public function canChangeSlug(): bool
    {
        return ($this->slug_changes_count ?? 0) < ($this->slug_changes_allowed ?? 1);
    }

    /**
     * Cantidad de cambios de slug restantes.
     */
    public function remainingSlugChanges(): int
    {
        return max(0, ($this->slug_changes_allowed ?? 1) - ($this->slug_changes_count ?? 0));
    }

    /**
     * Resolve route binding by slug instead of uuid.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    /**
     * Dominio público del tenant, para armar links "ver en vivo" desde
     * Filament (2026-08-31, pedido del Tech Lead para `PostResource`:
     * "la url completa se saca del dominio de la app [tenant] + /blog/ +
     * slug"). Prioriza el marcado `is_primary`; si el tenant todavía no
     * tiene ninguno marcado como primario, cae al primero que tenga
     * cargado — así no rompe si `is_primary` no se seteó todavía.
     */
    public function primaryDomain(): ?Domain
    {
        return $this->domains->firstWhere('is_primary', true)
            ?? $this->domains->first();
    }

    /**
     * URL pública completa para una ruta del sitio de este tenant (ej.
     * `publicUrl('blog/mi-slug')` → `https://cica360.com/blog/mi-slug`).
     * `null` si el tenant todavía no tiene ningún dominio cargado — a
     * propósito NO cae a un dominio hardcodeado (ver ARCHITECTURE.md §4,
     * "regla de oro": nada de dominios concretos en código ejecutable) ni
     * a la URL del panel de Studio, que no sirve contenido público.
     */
    public function publicUrl(string $path = ''): ?string
    {
        $domain = $this->primaryDomain()?->domain;

        if (! $domain) {
            return null;
        }

        return 'https://'.$domain.'/'.ltrim($path, '/');
    }

    /**
     * Gate de white-label (2026-09-02, pedido del Tech Lead para el bloque
     * `footer_bottom`: "sólo para los que no son de plan freemium o free,
     * si es otro plan de pago se le libera la marca blanca"). `plan` es un
     * string plano en `tenants` (`default('free')`, ver migración) — NO
     * está enlazado al sub-sistema `Plan`/`Subscription` (billing, fuera de
     * alcance del MVP). Se acepta `'freemium'` además de `'free'` a
     * propósito, aunque hoy `PlanSeeder` solo crea el slug `'free'` — para
     * no tener que tocar este método el día que exista un plan freemium
     * real con otro slug.
     *
     * 2026-09-02, ADR-043: se agrega `'sponsorship'` (plan "Auspicio/
     * Convenio") a la lista — el Tech Lead lo definió explícitamente como
     * "tendrá las mismas características del Freemium/Free", así que
     * cualquier otro gate del sistema que consulte `isFreeTier()` debe
     * tratarlo igual que Free/Freemium. La ÚNICA excepción es el copyright
     * del footer, que Auspicio/Convenio SÍ puede editar (de forma acotada)
     * — ver `isSponsorshipTier()`/`canEditCopyright()`, que existen
     * justamente porque `isFreeTier()` ya no alcanza para esa decisión.
     */
    /**
     * Resuelve la instancia de PlanEnum tipada para este tenant.
     */
    public function planEnum(): ?PlanEnum
    {
        return PlanEnum::tryFromKey($this->plan);
    }

    /**
     * Gate de white-label (2026-09-02, pedido del Tech Lead para el bloque
     * `footer_bottom`: "sólo para los que no son de plan freemium o free,
     * si es otro plan de pago se le libera la marca blanca").
     */
    public function isFreeTier(): bool
    {
        return $this->planEnum()?->isFreeTier() ?? in_array($this->plan, ['free', 'freemium', 'sponsorship'], true);
    }

    /**
     * Plan "Auspicio/Convenio" (2026-09-02, ADR-043).
     */
    public function isSponsorshipTier(): bool
    {
        return $this->planEnum()?->isSponsorshipTier() ?? ($this->plan === 'sponsorship');
    }

    /**
     * ¿Puede este tenant escribir en copyright del footer?
     */
    public function canEditCopyright(): bool
    {
        return $this->planEnum()?->canEditCopyright() ?? (! in_array($this->plan, ['free', 'freemium'], true));
    }

    /**
     * Gate de personalización del brand del panel Studio.
     */
    public function canPersonalizeStudioBrand(): bool
    {
        return $this->planEnum()?->canPersonalizeStudioBrand() ?? (! in_array($this->plan, ['free', 'freemium'], true));
    }

    /**
     * Gate de acceso a la Biblioteca de Medios (`MediaResource`, 2026-09-14,
     * ADR-067) — pedido del Tech Lead: "creo que multimedia lo ocultaremos
     * para free y auspicios", motivado por una limitación real todavía sin
     * resolver (los campos de imagen de Páginas/Posts/Servicios/etc. —
     * `App\Filament\Schemas\MediaUpload` — no tienen forma de REUTILIZAR un
     * archivo ya subido, solo subir uno nuevo o editar/borrar el actual: sin
     * esa reutilización, exponer una galería completa a Free/Auspicio no
     * suma valor real hoy). Mismo criterio de plan que `isFreeTier()`
     * (Free/Freemium Y Auspicio/Convenio quedan afuera, cualquier plan pago
     * adentro) — coinciden hoy a propósito, pero es un método PROPIO (no se
     * reusa `isFreeTier()` directo en `MediaResource`) por el mismo criterio
     * ya documentado en `canPersonalizeStudioBrand()`: son decisiones de
     * negocio distintas aunque hoy den el mismo resultado.
     *
     * El límite de `maxMedia()` SIGUE aplicando para estos planes — no
     * desaparece, solo cambia DÓNDE se hace visible: ya no hay una página
     * "Multimedia" para verlo, pero el conteo/tope real sigue mostrándose en
     * `PlanUsageWidget` (Escritorio) y se sigue haciendo cumplir en el campo
     * de subida inline (`MediaUpload::make()`) cuando se llega al tope.
     */
    public function canAccessMediaLibrary(): bool
    {
        return ! $this->isFreeTier();
    }

    /**
     * Gate del mecanismo de "deploy webhook" (2026-09-17, Fase 6 post-MVP
     * adelantada — ver ADR nuevo en DECISIONS.md): indica si este tenant
     * tiene CREDENCIALES cargadas (`deploy_repo` + `deploy_token`) para
     * disparar el rebuild+deploy de su front headless. Puramente sobre
     * presencia de datos — NO indica si el disparo automático al guardar
     * contenido debe estar activo; para eso ver `hasAutoDeployActive()`
     * (2026-09-18, 2da actualización).
     *
     * Hasta 2026-09-18 este método era el ÚNICO gate del mecanismo completo
     * (ambos campos los seteaba solo el operador vía tinker) — al pasar a
     * que el propio tenant los cargue desde `Preferences.php`, dejó de
     * alcanzar por sí solo: el tenant puede querer completar sus
     * credenciales sin activar todavía el disparo automático. Se mantiene
     * sin cambios de comportamiento porque `FrontendDeployService::dispatch()`
     * lo sigue usando tal cual — la capacidad TÉCNICA de disparar (¿hay con
     * qué autenticar contra GitHub?) es una pregunta distinta de la
     * política de automatización (¿debe hacerse en cada guardado?),
     * pensando en un futuro botón manual "Publicar ahora" que funcione
     * aunque el checkbox de automatización esté apagado.
     */
    public function hasDeployWebhookConfigured(): bool
    {
        return filled($this->deploy_repo) && filled($this->deploy_token);
    }

    /**
     * Gate REAL del disparo automático al guardar contenido (2026-09-18,
     * pedido del Tech Lead: dejar que el tenant vincule su propio repo/token
     * desde Preferencias, con un checkbox "Automatización activa" que solo
     * se pueda tildar cuando el repo y el token ya estén completos, y que
     * NO quede activo por default solo porque las credenciales están
     * cargadas). `DeployTriggerObserver`/`TriggerFrontendDeploy` consultan
     * ESTE método (no `hasDeployWebhookConfigured()`) antes de encolar o
     * disparar nada — combina ambas condiciones: credenciales presentes Y
     * el tenant tildó el checkbox. `deploy_enabled` en `false` (default de
     * la columna) para cualquier tenant nuevo o que recién cargó sus
     * credenciales sin activar todavía el toggle.
     */
    public function hasAutoDeployActive(): bool
    {
        return (bool) $this->deploy_enabled && $this->hasDeployWebhookConfigured();
    }

    /**
     * SMTP propio del tenant (2026-09-18, pedido del Tech Lead: "falta la
     * sección de configuración para ingresar los datos para configurar su
     * SMTP favorito y será mejor para evitar usar mi smtp general para
     * todo"). `host`+`username` como mínimo — `port`/`encryption`/`from_*`
     * tienen fallback razonable (ver `smtpMailerConfig()`), pero sin host+
     * usuario no hay nada real que conectar. Igual que
     * `hasDeployWebhookConfigured()`: `false` por defecto para cualquier
     * tenant nuevo, mecanismo 100% opt-in — sin esto configurado, los
     * emails de `ContactSubmissionService` siguen usando el `MAIL_MAILER`
     * global de la plataforma, sin cambio de comportamiento.
     */
    public function hasCustomSmtpConfigured(): bool
    {
        return filled($this->smtp_host) && filled($this->smtp_username);
    }

    /**
     * Config de mailer SMTP lista para registrar en `config('mail.mailers.*')`
     * en runtime (ver `App\Mail\Concerns\UsesTenantSmtp`) — centraliza los
     * fallbacks acá en vez de repetirlos en cada Mailable. `port` cae a 587
     * si el tenant no lo especifica.
     *
     * `scheme` (no `encryption`) es la llave real que entiende
     * `Illuminate\Mail\MailManager::createSmtpTransport()` — Symfony
     * Mailer (`EsmtpTransportFactory::create()`) decide TLS/SSL SOLO por el
     * scheme de la Dsn ('smtps' = TLS implícito desde el inicio de la
     * conexión) o, si no hay scheme explícito, por el puerto (465 → TLS
     * implícito). NO existe una opción `encryption` que Symfony lea — a
     * diferencia de versiones viejas de Laravel (`MAIL_ENCRYPTION`, ya
     * deprecado). Por eso `smtp_encryption` (el select "Cifrado" de
     * Preferencias) se traduce acá a `scheme`: `'ssl'` → fuerza `'smtps'`
     * (TLS implícito, cualquiera sea el puerto); `'tls'` o vacío → `'smtp'`,
     * que negocia STARTTLS automáticamente si el servidor lo ofrece (el
     * comportamiento correcto para el puerto 587, el más común).
     *
     * @return array<string, mixed>
     */
    public function smtpMailerConfig(): array
    {
        return [
            'transport' => 'smtp',
            'scheme' => $this->smtp_encryption === 'ssl' ? 'smtps' : 'smtp',
            'host' => $this->smtp_host,
            'port' => $this->smtp_port ?: 587,
            'username' => $this->smtp_username,
            'password' => $this->smtp_password,
        ];
    }

    /**
     * Dirección/nombre "From" a usar en los emails de este tenant cuando
     * tiene SMTP propio configurado — cae al nombre del tenant si no
     * definió `smtp_from_name`, y al `MAIL_FROM_ADDRESS` global si no
     * definió `smtp_from_address` (evita un "From" vacío o inválido si el
     * tenant completó host/usuario/contraseña pero se olvidó de esto).
     *
     * @return array{address: string, name: string}
     */
    public function smtpFromAddress(): array
    {
        return [
            'address' => $this->smtp_from_address ?: config('mail.from.address'),
            'name' => $this->smtp_from_name ?: $this->name,
        ];
    }

    /**
     * Etiqueta legible del plan (2026-09-13).
     */
    public function planLabel(): string
    {
        return $this->planEnum()?->getLabel() ?? ucfirst((string) $this->plan);
    }

    /**
     * Tope de contenidos ACTIVOS (no soft-deleted) por CADA tipo de
     * `PageTypeEnum` — Página, Landing, Legal y Footer se cuentan por
     * separado, NO un total combinado. Originalmente (2026-09-11) un único
     * número por plan aplicado a los 3 tipos por igual ("5 por cada tipo"
     * Free, "7 por tipo" Auspicio); actualizado el mismo día 2026-09-13 a
     * pedido explícito del Tech Lead con números DISTINTOS por tipo:
     * "limite maximo de 7 paginas, 5 legales y 5 secciones para supicios...
     * y para free que sea 3 legales y 3 secciones" — Páginas de Free queda
     * en 5 (no se pidió cambiarla, valor original sin tocar). `Landing`
     * reusa el mismo tope que `Page` por ahora: no tiene acción de "Crear"
     * habilitada todavía (comentada para MVP en `ManagePages`), así que es
     * un valor inerte hasta que se habilite. A diferencia del resto de
     * gates de este modelo (`isFreeTier()`, que trata a `sponsorship`
     * igual que `free`/`freemium`), acá SÍ hay números distintos por plan
     * — por eso este método no reusa `isFreeTier()`.
     *
     * `null` = sin límite. Cualquier plan pago/blanco todavía no
     * contemplado acá (el MVP solo define Free/Freemium y Sponsorship)
     * queda sin tope hasta que exista una decisión explícita para ese
     * tier — evita bloquear por accidente a un tenant de pago futuro con
     * un límite pensado para el tier gratuito.
     *
     * Nota: existe también `plans.max_pages` (`Plan::class`), pero ese es
     * un límite GLOBAL de páginas sembrado para un catálogo de planes que
     * hoy no está enlazado a `Tenant` (`Tenant::plan` es un string suelto,
     * no una FK a `plans`) — no se reutiliza acá para no acoplar esta
     * decisión a una tabla que todavía no participa del flujo real.
     */
    /**
     * Obtiene el límite configurado para una característica del plan del tenant.
     * Consulta primero la tabla `plan_features` del plan vinculado y luego
     * utiliza la escala predeterminada de `PlanEnum`.
     */
    public function getFeatureLimit(string $featureKey, ?int $default = null): ?int
    {
        $plan = $this->planModel() ?? Plan::where('slug', $this->plan)->first();

        if ($plan) {
            $featureValue = PlanFeature::where('plan_id', $plan->id)
                ->where('key', $featureKey)
                ->value('value');

            if ($featureValue !== null && is_numeric($featureValue)) {
                return (int) $featureValue;
            }
        }

        $planEnum = $this->planEnum();

        if ($planEnum) {
            return match ($featureKey) {
                'max_posts' => match ($planEnum) {
                    PlanEnum::Trial => 10,
                    PlanEnum::Starter => 50,
                    PlanEnum::Sponsorship => 100,
                    PlanEnum::Pro => 250,
                    PlanEnum::Premium => 1000,
                },
                'max_services' => match ($planEnum) {
                    PlanEnum::Trial => 5,
                    PlanEnum::Starter => 15,
                    PlanEnum::Sponsorship => 25,
                    PlanEnum::Pro => 50,
                    PlanEnum::Premium => 150,
                },
                'max_testimonials' => match ($planEnum) {
                    PlanEnum::Trial => 3,
                    PlanEnum::Starter => 15,
                    PlanEnum::Sponsorship => 25,
                    PlanEnum::Pro => 50,
                    PlanEnum::Premium => 150,
                },
                'max_sliders' => match ($planEnum) {
                    PlanEnum::Trial => 1,
                    PlanEnum::Starter => 3,
                    PlanEnum::Sponsorship => 5,
                    PlanEnum::Pro => 15,
                    PlanEnum::Premium => 50,
                },
                'max_media' => match ($planEnum) {
                    PlanEnum::Trial => 20,
                    PlanEnum::Starter => 100,
                    PlanEnum::Sponsorship => 150,
                    PlanEnum::Pro => 500,
                    PlanEnum::Premium => 2000,
                },
                'max_menus' => match ($planEnum) {
                    PlanEnum::Trial => 3,
                    PlanEnum::Starter => 5,
                    PlanEnum::Sponsorship => 5,
                    PlanEnum::Pro => 10,
                    PlanEnum::Premium => 30,
                },
                'max_menu_items' => match ($planEnum) {
                    PlanEnum::Trial => 5,
                    PlanEnum::Starter => 10,
                    PlanEnum::Sponsorship => 15,
                    PlanEnum::Pro => 30,
                    PlanEnum::Premium => 50,
                },
                'max_api_tokens' => match ($planEnum) {
                    PlanEnum::Trial => 5,
                    PlanEnum::Starter => 5,
                    PlanEnum::Sponsorship => 10,
                    PlanEnum::Pro => 25,
                    PlanEnum::Premium => 100,
                },
                'max_users' => match ($planEnum) {
                    PlanEnum::Trial => 1,
                    PlanEnum::Starter => 2,
                    PlanEnum::Sponsorship => 3,
                    PlanEnum::Pro => 10,
                    PlanEnum::Premium => 50,
                },
                'max_pages' => match ($planEnum) {
                    PlanEnum::Trial => 10,
                    PlanEnum::Starter => 30,
                    PlanEnum::Sponsorship => 50,
                    PlanEnum::Pro => 150,
                    PlanEnum::Premium => 1000,
                },
                default => $default,
            };
        }

        return $default;
    }

    /**
     * Tope de contenidos ACTIVOS (no soft-deleted) por CADA tipo de
     * `PageTypeEnum` — Página, Landing, Legal y Footer se cuentan por
     * separado, NO un total combinado.
     */
    public function maxContentsPerType(PageTypeEnum $type): ?int
    {
        $planEnum = $this->planEnum();

        if (! $planEnum) {
            return null;
        }

        return match ($planEnum) {
            PlanEnum::Trial => match ($type) {
                PageTypeEnum::Page, PageTypeEnum::Landing => 5,
                PageTypeEnum::Legal => 3,
                PageTypeEnum::Footer => 2,
            },
            PlanEnum::Starter => match ($type) {
                PageTypeEnum::Page, PageTypeEnum::Landing => 20,
                PageTypeEnum::Legal => 5,
                PageTypeEnum::Footer => 5,
            },
            PlanEnum::Sponsorship => match ($type) {
                PageTypeEnum::Page, PageTypeEnum::Landing => 30,
                PageTypeEnum::Legal => 10,
                PageTypeEnum::Footer => 10,
            },
            PlanEnum::Pro => match ($type) {
                PageTypeEnum::Page, PageTypeEnum::Landing => 100,
                PageTypeEnum::Legal => 25,
                PageTypeEnum::Footer => 25,
            },
            PlanEnum::Premium => match ($type) {
                PageTypeEnum::Page, PageTypeEnum::Landing => 800,
                PageTypeEnum::Legal => 100,
                PageTypeEnum::Footer => 100,
            },
        };
    }

    public function maxPosts(): ?int
    {
        return $this->getFeatureLimit('max_posts');
    }

    public function maxServices(): ?int
    {
        return $this->getFeatureLimit('max_services');
    }

    public function maxTestimonials(): ?int
    {
        return $this->getFeatureLimit('max_testimonials');
    }

    public function maxSliders(): ?int
    {
        return $this->getFeatureLimit('max_sliders');
    }

    public function maxMedia(): ?int
    {
        return $this->getFeatureLimit('max_media');
    }

    public function maxForms(): ?int
    {
        return $this->getFeatureLimit('max_forms', match ($this->planEnum()) {
            PlanEnum::Trial => 2,
            PlanEnum::Starter => 5,
            PlanEnum::Sponsorship => 5,
            PlanEnum::Pro => 15,
            PlanEnum::Premium => 50,
            default => null,
        });
    }

    public function maxMenus(): ?int
    {
        return $this->getFeatureLimit('max_menus');
    }

    public function maxMenuItems(): ?int
    {
        return $this->getFeatureLimit('max_menu_items');
    }

    public function maxApiTokens(): ?int
    {
        return $this->getFeatureLimit('max_api_tokens');
    }

    public function maxUsers(): ?int
    {
        return $this->getFeatureLimit('max_users');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * "Dueño de la cuenta" (2026-09-18, pedido del Tech Lead): distinción
     * puramente VISUAL en `WelcomeWidget` entre el `Admin` que creó el
     * tenant y cualquier otro colaborador al que se le asignó rol `Admin`
     * después — ambos tienen el MISMO nivel de acceso real (esto no es una
     * Policy ni afecta ningún `canAccess()`), solo cambia qué badge se
     * muestra ("Propietario" vs "Administrador"). No existe un campo
     * `owner_id`/`is_owner` en el esquema (fuera de alcance agregar uno
     * para un badge puramente visual) — se usa el mismo heurístico que ya
     * documenta `TenantRolePolicy::hasAnyRole()`/`RestrictsPageToRoles`
     * para el caso legado "usuario sin rol": el PRIMER usuario creado del
     * tenant (`created_at` asc, `id` como desempate) es, en la práctica,
     * siempre quien registró/inició la cuenta.
     */
    public function isOwnedBy(User $user): bool
    {
        if ($user->tenant_id !== $this->id) {
            return false;
        }

        $ownerId = $this->users()->orderBy('created_at')->orderBy('id')->value('id');

        return $ownerId === $user->id;
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function sliders(): HasMany
    {
        return $this->hasMany(Slider::class);
    }

    public function menus(): HasMany
    {
        return $this->hasMany(Menu::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Suscripción activa o más reciente del tenant.
     */
    public function currentSubscription(): ?Subscription
    {
        return $this->subscriptions()->latest('id')->first();
    }

    /**
     * Modelo `Plan` correspondiente al slug del tenant en la tabla `plans`.
     */
    public function planModel(): ?Plan
    {
        return Plan::where('slug', $this->plan)->first();
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function tenantModules(): HasMany
    {
        return $this->hasMany(TenantModule::class);
    }

    public function forms(): HasMany
    {
        return $this->hasMany(Form::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }
}
