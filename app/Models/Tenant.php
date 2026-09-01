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
