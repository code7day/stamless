<?php

namespace App\Models;

use App\Enums\LanguageEnum;
use App\Traits\HasTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id', 'uuid', 'lang_iso', 'name', 'slug', 'description',
    'notification_email', 'notification_subject', 'send_copy_to_submitter',
    'success_message', 'redirect_url', 'is_active', 'enable_honeypot',
    'enable_recaptcha', 'settings',
])]
class Form extends Model
{
    use HasTenant, HasUuid;

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'lang_iso' => LanguageEnum::class,
            'send_copy_to_submitter' => 'boolean',
            'is_active' => 'boolean',
            'enable_honeypot' => 'boolean',
            'enable_recaptcha' => 'boolean',
            'settings' => 'array',
        ];
    }

    /**
     * Campos configurados, listos para eager loading (`with('fields')`).
     */
    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class)->orderBy('sort_order');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }
}
