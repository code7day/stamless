<?php

namespace App\Models;

use App\Enums\LanguageEnum;
use App\Observers\DeployTriggerObserver;
use App\Traits\HasTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'uuid', 'lang_iso', 'name', 'slug'])]
class Menu extends Model
{
    use HasTenant, HasUuid;

    /**
     * Fase 6 (post-MVP) adelantada, 2026-09-17 — ver `Page::booted()` para
     * el docblock completo de por qué existe este observer. `MenuItem`
     * tiene su PROPIO `booted()`/observer — cubre el caso más común
     * (agregar/reordenar items sin tocar el `Menu` en sí).
     */
    protected static function booted(): void
    {
        static::observe(DeployTriggerObserver::class);
    }

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'lang_iso' => LanguageEnum::class,
        ];
    }

    /**
     * Todos los items del menú (incluye anidados vía `parent_id`).
     */
    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class)->orderBy('sort_order');
    }

    /**
     * Items de primer nivel, con sus hijos precargados para evitar N+1.
     */
    public function rootItems(): HasMany
    {
        return $this->items()->whereNull('parent_id');
    }
}
