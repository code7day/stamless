<?php

namespace App\Models;

use App\Enums\LanguageEnum;
use App\Enums\PageTypeEnum;
use App\Enums\PublishStatusEnum;
use App\Traits\HasTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id', 'uuid', 'parent_id', 'lang_iso', 'pretitle', 'title',
    'subtitle', 'slug', 'type', 'is_home', 'status', 'meta', 'links',
    'properties', 'published_at',
])]
class Page extends Model
{
    use HasTenant, HasUuid;

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'lang_iso' => LanguageEnum::class,
            'type' => PageTypeEnum::class,
            'status' => PublishStatusEnum::class,
            'is_home' => 'boolean',
            'meta' => 'array',
            'links' => 'array',
            'properties' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Blocks ordenados de la página, listos para eager loading (`with('blocks')`).
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class)->orderBy('sort_order');
    }

    /**
     * Árbol de páginas hasta 3 niveles (2026-08-31 — organización interna
     * en Studio, NO afecta la URL pública de cada página, que sigue siendo
     * su `slug` plano). `parent()`/`children()` mismo patrón ya usado en
     * `MenuItem`.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('title');
    }

    /**
     * 0 = página de primer nivel, 1 = hija, 2 = nieta (el máximo permitido
     * — ver `PageResource::eligibleParentOptions()`). Sube por `parent`
     * como máximo 2 veces; el `$guard` es puramente defensivo por si algún
     * dato viejo/corrupto formara un ciclo (no debería poder pasar, la
     * elegibilidad de `parent_id` ya lo previene en el form).
     */
    public function depth(): int
    {
        $depth = 0;
        $current = $this;
        $guard = 0;

        while ($current->parent_id && $guard < 5) {
            $depth++;
            $current = $current->parent;
            $guard++;

            if (! $current) {
                break;
            }
        }

        return $depth;
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', PublishStatusEnum::Published->value);
    }

    public function scopeOfType(Builder $query, PageTypeEnum $type): Builder
    {
        return $query->where('type', $type->value);
    }

    public function scopeForLanguage(Builder $query, LanguageEnum $lang): Builder
    {
        return $query->where('lang_iso', $lang->value);
    }
}
