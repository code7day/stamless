<?php

namespace App\Traits;

use App\Models\Scopes\TenantScope;
use App\Services\TenantManager;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait HasTenant
{
    /**
     * Boot the HasTenant trait.
     */
    public static function bootHasTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function ($model) {
            $tenantManager = app(TenantManager::class);
            if ($tenantManager->hasTenant() && ! $model->tenant_id) {
                $model->tenant_id = $tenantManager->getTenantId();
            }
        });
    }

    /**
     * Get the tenant that owns the model.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
