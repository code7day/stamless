<?php

namespace App\Models;

use App\Enums\BlockTypeEnum;
use App\Enums\LanguageEnum;
use App\Traits\HasTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id', 'uuid', 'page_id', 'lang_iso', 'type', 'pretitle', 'title',
    'subtitle', 'content', 'links', 'properties', 'sort_order', 'is_visible',
])]
class Block extends Model
{
    use HasTenant, HasUuid;

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'lang_iso' => LanguageEnum::class,
            'type' => BlockTypeEnum::class,
            'content' => 'array',
            'links' => 'array',
            'properties' => 'array',
            'sort_order' => 'integer',
            'is_visible' => 'boolean',
        ];
    }

    protected static function booted()
    {
        static::saving(function (Block $block) {
            $block->content = $block->content ?? [];
            $block->links = $block->links ?? [];
            $block->properties = $block->properties ?? [];
        });
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
