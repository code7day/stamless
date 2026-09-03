<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Tenant extends Model
{
    use HasUuid;

    protected $fillable = ['name', 'slug', 'uuid', 'short_hash', 'is_active', 'plan'];

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
        ];
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
