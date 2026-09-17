<?php

namespace App\Models;

use App\Observers\DeployTriggerObserver;
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
     *
     * `DeployTriggerObserver` (2026-09-17, Fase 6 adelantada — ver
     * `Page::booted()`) se suma acá porque `Setting` alimenta contenido
     * público real (copyright de `footer_bottom`, fallbacks de SEO/OG,
     * ver ADR-065/ADR-066) — un cambio ahí también tiene que reconstruir
     * el front, no solo invalidar el caché de `SettingService`.
     */
    protected static function booted(): void
    {
        static::observe(SettingObserver::class);
        static::observe(DeployTriggerObserver::class);
    }
}
