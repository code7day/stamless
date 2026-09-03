<?php

namespace App\Models;

use App\Enums\LanguageEnum;
use App\Enums\LinkTargetEnum;
use App\Enums\MenuItemTypeEnum;
use App\Traits\HasTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id', 'uuid', 'menu_id', 'parent_id', 'lang_iso', 'title', 'type',
    'reference_id', 'url', 'target', 'sort_order', 'is_active',
])]
class MenuItem extends Model
{
    use HasTenant, HasUuid;

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'lang_iso' => LanguageEnum::class,
            'type' => MenuItemTypeEnum::class,
            'target' => LinkTargetEnum::class,
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'parent_id')->orderBy('sort_order');
    }

    /**
     * Autocompleta `menu_id` en sub-items (nivel 2/3) creados vía el
     * Repeater anidado `children` de `MenuResource`.
     *
     * 2026-09-02 — bug real en vivo, 2da causa del mismo síntoma ("no se
     * pueden crear más submenús"): `children()` es un `HasMany` SOBRE
     * `MenuItem` (self-relation por `parent_id`), no sobre `Menu` — cuando
     * Filament guarda un item anidado vía esa relación, Eloquent completa
     * automáticamente `parent_id` (la FK de ESA relación) pero no tiene
     * ninguna noción de `menu_id`, que pertenece a una relación distinta
     * (`Menu::items()`). Los items de nivel 1 (`rootItems`, un `HasMany`
     * directo de `Menu`) sí lo reciben gratis por el mismo mecanismo, por
     * eso el bug solo aparecía en submenús. Fix centralizado acá (no en
     * `MenuResource`) para que valga sin importar el camino de creación.
     */
    protected static function booted(): void
    {
        static::creating(function (self $item): void {
            if ($item->menu_id || ! $item->parent_id) {
                return;
            }

            $item->menu_id = static::query()->whereKey($item->parent_id)->value('menu_id');
        });
    }
}
