<?php

namespace App\Models;

use App\Enums\ContactActivityTypeEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log inmutable de actividad sobre un `Contact`: solo `created_at`, sin
 * `updated_at` (las entradas no se editan) y sin `uuid`/`tenant_id`
 * propios (se resuelve vía `contact.tenant_id`).
 */
#[Fillable(['contact_id', 'user_id', 'type', 'description', 'meta'])]
class ContactActivity extends Model
{
    /**
     * The name of the "updated at" column.
     *
     * @var string|null
     */
    const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'type' => ContactActivityTypeEnum::class,
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
