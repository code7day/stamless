<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sin `uuid`: es un registro de activación interno, no un recurso
 * direccionable públicamente.
 */
#[Fillable(['tenant_id', 'module_id', 'is_active', 'activated_at', 'deactivated_at', 'settings', 'limits'])]
class TenantModule extends Model
{
    use HasTenant;

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'settings' => 'array',
            'limits' => 'array',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }
}
