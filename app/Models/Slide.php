<?php

namespace App\Models;

use App\Enums\LanguageEnum;
use App\Enums\SlideBackgroundTypeEnum;
use App\Traits\HasTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id', 'uuid', 'slider_id', 'lang_iso', 'pretitle', 'title', 'subtitle',
    'background_type', 'image_desktop_id', 'image_tablet_id',
    'image_mobile_id', 'video_desktop_id', 'video_mobile_id',
    'has_presentation_video', 'presentation_youtube_id', 'links', 'properties',
    'is_active', 'sort_order',
])]
class Slide extends Model
{
    use HasTenant, HasUuid;

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'lang_iso' => LanguageEnum::class,
            'background_type' => SlideBackgroundTypeEnum::class,
            'has_presentation_video' => 'boolean',
            'links' => 'array',
            'properties' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function slider(): BelongsTo
    {
        return $this->belongsTo(Slider::class);
    }

    public function imageDesktop(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_desktop_id');
    }

    public function imageTablet(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_tablet_id');
    }

    public function imageMobile(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_mobile_id');
    }

    public function videoDesktop(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'video_desktop_id');
    }

    public function videoMobile(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'video_mobile_id');
    }
}
