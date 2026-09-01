<?php

namespace App\Models;

use App\Enums\ModuleTypeEnum;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['uuid', 'name', 'slug', 'description', 'type', 'is_core', 'is_active', 'sort_order', 'icon', 'version'])]
class Module extends Model
{
    use HasUuid;

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'type' => ModuleTypeEnum::class,
            'is_core' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Planes que incluyen este módulo por defecto.
     */
    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_module')
            ->withPivot('limits')
            ->withTimestamps();
    }

    /**
     * Activaciones del módulo por tenant.
     */
    public function tenantModules(): HasMany
    {
        return $this->hasMany(TenantModule::class);
    }
}
