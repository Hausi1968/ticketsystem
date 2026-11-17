<?php

namespace App\Models;

use App\Enums\MessageType;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketMessage extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'ticket_id', 'type', 'user_id', 'contact_id', 'from_email', 'from_name', 'to', 'cc',
        'subject', 'body_text', 'body_html', 'message_id', 'in_reply_to', 'references', 'raw_path',
        'delivery_status', 'delivery_error', 'sent_at', 'signature_team_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => MessageType::class,
            'to' => 'array',
            'cc' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function authorName(): string
    {
        return $this->user?->name ?? $this->from_name ?? $this->from_email ?? 'System';
    }
}
