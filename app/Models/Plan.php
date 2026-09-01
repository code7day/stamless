<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'uuid', 'name', 'slug', 'description', 'price_monthly', 'price_yearly',
    'currency', 'is_active', 'is_free', 'sort_order', 'max_users', 'max_pages',
    'max_posts', 'max_storage_mb',
])]
class Plan extends Model
{
    use HasUuid;

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:2',
            'price_yearly' => 'decimal:2',
            'is_active' => 'boolean',
            'is_free' => 'boolean',
            'sort_order' => 'integer',
            'max_users' => 'integer',
            'max_pages' => 'integer',
            'max_posts' => 'integer',
            'max_storage_mb' => 'integer',
        ];
    }

    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Módulos incluidos por defecto en este plan.
     */
    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'plan_module')
            ->withPivot('limits')
            ->withTimestamps();
    }
}
