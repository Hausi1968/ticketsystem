<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailReceipt extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'mailbox_id', 'message_hash', 'message_id', 'from_email', 'subject', 'outcome', 'ticket_id', 'note', 'raw_path'];

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
