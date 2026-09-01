<?php

namespace App\Models;

use App\Enums\LanguageEnum;
use App\Enums\PublishStatusEnum;
use App\Traits\HasTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id', 'uuid', 'lang_iso', 'pretitle', 'title', 'subtitle', 'slug',
    'excerpt', 'content', 'status', 'featured_image_id', 'meta', 'links',
    'properties', 'published_at',
])]
class Post extends Model
{
    use HasTenant, HasUuid;

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'lang_iso' => LanguageEnum::class,
            'status' => PublishStatusEnum::class,
            'meta' => 'array',
            'links' => 'array',
            'properties' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function featuredImage(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'featured_image_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', PublishStatusEnum::Published->value);
    }

    public function scopeForLanguage(Builder $query, LanguageEnum $lang): Builder
    {
        return $query->where('lang_iso', $lang->value);
    }
}
