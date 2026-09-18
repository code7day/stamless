<?php

namespace App\Models;

use App\Enums\BlockTypeEnum;
use App\Enums\LanguageEnum;
use App\Observers\DeployTriggerObserver;
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

        // 2026-09-18 (bug real reportado por el Tech Lead: guardó cambios
        // en un bloque de footer/Colophon y el deploy automático nunca se
        // disparó) — este observer se había registrado en Page, Post,
        // Service, etc. (ver `Page::booted()`) pero se saltó `Block`, que es
        // justo donde vive el contenido de TODOS los bloques de una página
        // (colophon/footer incluido). Además, aunque estuviera en Page,
        // guardar SOLO un bloque desde el Repeater no necesariamente deja
        // "dirty" ningún atributo propio de la Page → Eloquent puede no
        // disparar su evento `updated` en absoluto. Por eso hace falta el
        // observer ACÁ, en el modelo que realmente cambió.
        static::observe(DeployTriggerObserver::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
