<?php

namespace App\Models;

use App\Observers\DeployTriggerObserver;
use App\Traits\HasTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'uuid', 'name', 'role', 'quote', 'avatar_id', 'is_visible', 'sort_order'])]
class Testimonial extends Model
{
    use HasTenant, HasUuid;

    /**
     * Fase 6 (post-MVP) adelantada, 2026-09-17 — ver `Page::booted()` para
     * el docblock completo de por qué existe este observer.
     */
    protected static function booted(): void
    {
        static::observe(DeployTriggerObserver::class);
    }

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function avatar(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'avatar_id');
    }
}
