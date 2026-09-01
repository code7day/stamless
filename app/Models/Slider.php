<?php

namespace App\Models;

use App\Enums\LanguageEnum;
use App\Traits\HasTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'uuid', 'lang_iso', 'title', 'slug', 'is_active', 'properties'])]
class Slider extends Model
{
    use HasTenant, HasUuid;

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'lang_iso' => LanguageEnum::class,
            'is_active' => 'boolean',
            // Property a nivel Slider (no Slide) — 2026-08-31: solo
            // `show_scroll_indicator` por ahora (flecha sobrepuesta al
            // decorador inferior, aplica a todas las slides detrás), pero
            // jsonb en vez de columna dedicada para no necesitar otra
            // migración si el Tech Lead pide más config a este nivel.
            'properties' => 'array',
        ];
    }

    /**
     * Slides ordenados, listos para eager loading (`with('slides')`).
     */
    public function slides(): HasMany
    {
        return $this->hasMany(Slide::class)->orderBy('sort_order');
    }
}
