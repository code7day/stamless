<?php

namespace App\Models;

use App\Observers\SettingObserver;
use App\Traits\HasTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'uuid', 'key', 'value', 'type', 'description'])]
class Setting extends Model
{
    use HasTenant, HasUuid;

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::observe(SettingObserver::class);
    }
}
