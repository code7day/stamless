<?php

namespace App\Models\Scopes;

use App\Services\TenantManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $tenantManager = app(TenantManager::class);

        if ($tenantManager->hasTenant()) {
            $builder->where($model->getTable().'.tenant_id', $tenantManager->getTenantId());
        }
    }
}
