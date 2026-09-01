<?php

namespace App\Models;

use App\Enums\PaymentMethodTypeEnum;
use App\Traits\HasTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id', 'uuid', 'type', 'provider', 'is_default', 'last_four',
    'brand', 'exp_month', 'exp_year', 'external_payment_method_id', 'meta',
])]
class PaymentMethod extends Model
{
    use HasTenant, HasUuid;

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'type' => PaymentMethodTypeEnum::class,
            'is_default' => 'boolean',
            'exp_month' => 'integer',
            'exp_year' => 'integer',
            'meta' => 'array',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
