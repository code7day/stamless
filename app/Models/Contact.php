<?php

namespace App\Models;

use App\Enums\ContactStatusEnum;
use App\Traits\HasTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `email`/`phone`/`company` viajan cifrados (cast `encrypted`) y ocultos
 * por defecto en la serialización del modelo: ninguna API debe exponerlos
 * sin pasar explícitamente por un Resource/policy que los autorice.
 *
 * `data` guarda las respuestas dinámicas del formulario. El cifrado
 * selectivo de claves marcadas `FormField::is_encrypted` dentro de ese
 * jsonb es responsabilidad de la capa de servicio que procesa el envío del
 * formulario, no de este modelo (evitar lógica de negocio pesada aquí).
 */
#[Fillable([
    'tenant_id', 'uuid', 'form_id', 'name', 'email', 'phone', 'company',
    'data', 'source', 'page_url', 'ip_address', 'user_agent', 'status',
    'notes', 'assigned_to', 'tags', 'last_contacted_at',
])]
#[Hidden(['email', 'phone', 'company'])]
class Contact extends Model
{
    use HasTenant, HasUuid;

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'email' => 'encrypted',
            'phone' => 'encrypted',
            'company' => 'encrypted',
            'data' => 'array',
            'status' => ContactStatusEnum::class,
            'tags' => 'array',
            'last_contacted_at' => 'datetime',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ContactActivity::class)->latest('created_at');
    }
}
