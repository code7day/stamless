<?php

namespace App\Models;

use App\Enums\LanguageEnum;
use App\Observers\DeployTriggerObserver;
use App\Traits\HasTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id', 'uuid', 'lang_iso', 'name', 'slug', 'description',
    'notification_email', 'notification_subject', 'notification_intro',
    'logo_id', 'send_copy_to_submitter',
    'success_message', 'redirect_url', 'is_active', 'enable_honeypot',
    'enable_recaptcha', 'settings',
    'thank_you_title', 'thank_you_description', 'thank_you_alert_title',
    'thank_you_alert_description', 'thank_you_button_label',
])]
class Form extends Model
{
    use HasTenant, HasUuid;

    /**
     * Fase 6 (post-MVP) adelantada, 2026-09-18 — expuesto vía API pública
     * (`forms/{slug}`) y consumido en build time por el front, igual que
     * Page/Post/Service — ver `Page::booted()` para el docblock completo.
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
            'lang_iso' => LanguageEnum::class,
            'send_copy_to_submitter' => 'boolean',
            'is_active' => 'boolean',
            'enable_honeypot' => 'boolean',
            'enable_recaptcha' => 'boolean',
            'settings' => 'array',
            // `array`, no `string`: el `RichEditor` de Filament guarda su
            // estado como documento JSON (no HTML plano) — el cast decodifica
            // a array en lectura y json_encode en escritura sin importar si
            // el valor es un array real (editado vía Filament) o el string
            // literal por defecto (json_encode de un string sigue siendo
            // JSON válido, round-trip correcto en ambos casos). Ver ADR-074.
            'thank_you_description' => 'array',
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

    /**
     * Logo propio de ESTE formulario (2026-09-24), override opcional del
     * logo tenant-wide de `Setting` `branding.logo_id` (`Preferences.php`,
     * ADR-075). Nullable a propósito, mismo patrón que
     * `Service::imageDetail()` — el fallback "si no hay logo propio, usar
     * el del tenant" NO vive acá: es responsabilidad de
     * `ContactSubmissionService::resolveBrandLogoUrl()`, que consulta este
     * campo primero.
     */
    public function logo(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'logo_id');
    }
}
