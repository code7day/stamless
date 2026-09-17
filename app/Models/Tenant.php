<?php

namespace App\Models;

use App\Enums\PageTypeEnum;
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
    ];

    /**
     * `deploy_token` (PAT de GitHub, ver migración de deploy webhook) queda
     * fuera de `$fillable` a propósito — es una credencial de infraestructura
     * que solo el operador de la plataforma setea (`forceFill()` vía tinker
     * o seeder), nunca un campo que un formulario de Studio deba poder
     * llenar en masa. `$hidden` es la segunda defensa: aunque el modelo
     * `Tenant` no se expone hoy en ninguna API Resource, cualquier futuro
     * `toArray()`/`toJson()` accidental no lo filtra.
     */
    protected $hidden = [
        'deploy_token',
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
    public function isFreeTier(): bool
    {
        return in_array($this->plan, ['free', 'freemium', 'sponsorship'], true);
    }

    /**
     * Plan "Auspicio/Convenio" (2026-09-02, ADR-043) — mismas
     * características que Free/Freemium (`isFreeTier()` ya lo incluye)
     * salvo el copyright del footer: este plan SÍ puede personalizarlo,
     * pero de forma acotada (solo año + nombre, dentro de una plantilla
     * fija con "Powered by Stamless" — nunca queda blanco total). CICA360
     * (Cliente 0) usa este plan desde ADR-043, en vez de Free Forever puro
     * (ADR-006).
     */
    public function isSponsorshipTier(): bool
    {
        return $this->plan === 'sponsorship';
    }

    /**
     * ¿Puede este tenant escribir ALGO en `footer_bottom.content.copyright_text`,
     * aunque sea de forma acotada (Auspicio/Convenio) o total (plan pago
     * blanco)? 2026-09-02, ADR-043 — antes esto era simplemente
     * `! isFreeTier()`, pero ya no alcanza: Auspicio/Convenio SÍ puede
     * editar y a la vez SIGUE siendo free-tier para cualquier otro gate.
     * Equivalente a "no es Free ni Freemium puro" — ver `isSponsorshipTier()`
     * para decidir QUÉ plantilla aplica cuando esto da `true`.
     */
    public function canEditCopyright(): bool
    {
        return ! in_array($this->plan, ['free', 'freemium'], true);
    }

    /**
     * Gate de personalización del brand del panel Studio (2026-09-13,
     * pedido del Tech Lead: "los free, solo dirán Stamless Studio" — el
     * nombre del tenant en el brand de `PanelCmsProvider` — ej. "CICA360
     * Studio" — es un beneficio de plan pago, igual que `canEditCopyright()`
     * (mismo criterio: Free/Freemium NO, Auspicio/Convenio y cualquier plan
     * pago SÍ), pero es un gate conceptualmente distinto — un método propio
     * en vez de reusar `canEditCopyright()` para no acoplar dos decisiones
     * de negocio que hoy coinciden mas mañana podrian no hacerlo (mismo
     * criterio ya aplicado en este modelo para `maxPosts()`/`maxServices()`/
     * etc., métodos separados aunque hoy den el mismo número).
     */
    public function canPersonalizeStudioBrand(): bool
    {
        return ! in_array($this->plan, ['free', 'freemium'], true);
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
     * tiene configurado el disparo automático de rebuild+deploy de su front
     * headless al guardar contenido (`deploy_repo` + `deploy_token`, ambos
     * seteados solo por el operador de la plataforma vía tinker/seeder, ver
     * migración `add_deploy_webhook_fields_to_tenants_table`).
     *
     * `false` por defecto para CUALQUIER tenant (ambos campos nulos) — el
     * mecanismo es opt-in explícito, no un comportamiento nuevo silencioso
     * para tenants que no tengan un pipeline de CI propio conectado todavía.
     * `DeployTriggerObserver`/`TriggerFrontendDeploy` consultan este gate
     * antes de encolar o disparar nada.
     */
    public function hasDeployWebhookConfigured(): bool
    {
        return filled($this->deploy_repo) && filled($this->deploy_token);
    }

    /**
     * Etiqueta legible del plan (2026-09-13) — usada en el bloque de
     * "info del proyecto" del sidebar de Studio (ver
     * `resources/views/filament/cms/sidebar-project-info.blade.php`), que
     * solo se muestra para tenants Free/Freemium (`! canPersonalizeStudioBrand()`)
     * como recordatorio de plan — los tenants de pago ya ven su nombre real
     * en el brand de arriba y no necesitan este recordatorio.
     */
    public function planLabel(): string
    {
        return match ($this->plan) {
            'free', 'freemium' => 'Free',
            'sponsorship' => 'Auspicio/Convenio',
            default => ucfirst($this->plan),
        };
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
    public function maxContentsPerType(PageTypeEnum $type): ?int
    {
        return match ($this->plan) {
            'free', 'freemium' => match ($type) {
                PageTypeEnum::Page, PageTypeEnum::Landing => 5,
                PageTypeEnum::Legal => 3,
                PageTypeEnum::Footer => 3,
            },
            'sponsorship' => match ($type) {
                // 2026-09-13: Páginas sube de 7 a 10 (pedido del Tech
                // Lead) — Legales/Secciones sin cambios.
                PageTypeEnum::Page, PageTypeEnum::Landing => 10,
                PageTypeEnum::Legal => 5,
                PageTypeEnum::Footer => 5,
            },
            default => null,
        };
    }

    /**
     * Topes por plan para los demás recursos con límite freemium
     * (2026-09-11, pedido explícito del Tech Lead, mismo criterio que
     * `maxContentsPerType()`: `null` = sin límite, ninguno de estos modelos
     * usa `SoftDeletes` — a diferencia de `Page`, acá "activo" es
     * simplemente "la fila existe"). Cada uno es un método aparte (no una
     * tabla/array genérico) a propósito: son números totalmente
     * independientes entre sí, pedidos uno por uno, y así queda igual de
     * legible/greppable que `maxContentsPerType()`.
     */
    public function maxPosts(): ?int
    {
        return match ($this->plan) {
            'free', 'freemium' => 10,
            'sponsorship' => 20,
            default => null,
        };
    }

    public function maxServices(): ?int
    {
        return match ($this->plan) {
            'free', 'freemium' => 10,
            'sponsorship' => 20,
            default => null,
        };
    }

    public function maxTestimonials(): ?int
    {
        return match ($this->plan) {
            'free', 'freemium' => 6,
            'sponsorship' => 20,
            default => null,
        };
    }

    public function maxSliders(): ?int
    {
        return match ($this->plan) {
            'free', 'freemium' => 2,
            'sponsorship' => 5,
            default => null,
        };
    }

    public function maxMedia(): ?int
    {
        return match ($this->plan) {
            'free', 'freemium' => 40,
            'sponsorship' => 60,
            default => null,
        };
    }

    /**
     * Límite de CANTIDAD de menús por tenant (2026-09-13, pedido del Tech
     * Lead: "a lo mejor falta el limite de menus, son 5 menus limite sea
     * free o auspicio") — a diferencia del resto de topes de este bloque,
     * acá Free/Freemium y Auspicio/Convenio comparten el MISMO número (5),
     * en vez de que Auspicio tenga un cupo mayor; no hay pedido de un
     * número distinto para Auspicio en este caso puntual. Distinto de
     * `maxMenuItems()` (de abajo), que limita items DENTRO de cada menú,
     * no la cantidad de menús en sí.
     */
    public function maxMenus(): ?int
    {
        return match ($this->plan) {
            'free', 'freemium', 'sponsorship' => 5,
            default => null,
        };
    }

    /**
     * A diferencia de los demás topes de este bloque, este no se compara
     * contra un `count()` de filas existentes en un modelo propio: los
     * items de menú se sincronizan en bloque desde un árbol plano
     * (`MenuTreeBuilder::make('itemsTree')`, ver `MenuResource::
     * syncMenuTree()`), así que quien llama a este método cuenta el array
     * `itemsTree` completo (todos los niveles de profundidad) ANTES de
     * sincronizar — ver `MenuResource::createAction()`/`EditAction`.
     */
    public function maxMenuItems(): ?int
    {
        return match ($this->plan) {
            'free', 'freemium' => 7,
            'sponsorship' => 12,
            default => null,
        };
    }

    /**
     * 2026-09-12, pedido del Tech Lead: "me falto ver para plan free /
     * auspiciador deberia permitir un limite de tokens, para free 5 y
     * para auspicio 10" — mismo patrón que el resto de topes de este
     * bloque. Lo consume `App\Filament\Pages\ApiTokens::
     * isTokenLimitReached()`, contando solo tokens ACTIVOS (no expirados)
     * del tenant — ver ese método para el detalle de por qué un token ya
     * expirado no ocupa un cupo.
     */
    public function maxApiTokens(): ?int
    {
        return match ($this->plan) {
            'free', 'freemium' => 5,
            'sponsorship' => 10,
            default => null,
        };
    }

    /**
     * Límite de usuarios por tenant según plan.
     * Free: 1 (solo el dueño)
     * Auspicio/Convenio: 3
     * Otros planes de pago: según configuración / sin límite por defecto
     */
    public function maxUsers(): ?int
    {
        return match ($this->plan) {
            'free', 'freemium' => 1,
            'sponsorship' => 3,
            default => null,
        };
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
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
}
