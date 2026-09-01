<?php

namespace App\Models;

use App\Enums\LanguageEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catálogo global (no tenant-aware): describe qué incluye cada `Plan`.
 * Sin columna `uuid`: no se expone individualmente, se consulta siempre
 * como parte de `Plan::features`.
 */
#[Fillable(['plan_id', 'lang_iso', 'key', 'value', 'label'])]
class PlanFeature extends Model
{
    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'lang_iso' => LanguageEnum::class,
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
