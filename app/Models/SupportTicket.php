<?php

namespace App\Models;

use App\Enums\SupportTicketCategoryEnum;
use App\Enums\SupportTicketPriorityEnum;
use App\Enums\SupportTicketStatusEnum;
use App\Traits\HasTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

#[Fillable([
    'tenant_id',
    'user_id',
    'uuid',
    'ticket_number',
    'subject',
    'category',
    'priority',
    'status',
    'source',
    'source_url',
    'message',
    'attachments',
    'closed_at',
])]
class SupportTicket extends Model
{
    use HasTenant, HasUuid;

    protected static function booted(): void
    {
        static::creating(function (SupportTicket $ticket) {
            if (empty($ticket->ticket_number)) {
                $ticket->ticket_number = 'ST-'.date('ym').'-'.strtoupper(Str::random(4));
            }

            if (empty($ticket->user_id) && Auth::check()) {
                $ticket->user_id = Auth::id();
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'category' => SupportTicketCategoryEnum::class,
            'priority' => SupportTicketPriorityEnum::class,
            'status' => SupportTicketStatusEnum::class,
            'attachments' => 'array',
            'closed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->oldest('created_at');
    }

    public function latestMessage(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->latest('created_at');
    }
}
