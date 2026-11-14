<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketEvent extends Model
{
    use BelongsToTenant;

    const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'ticket_id', 'user_id', 'type', 'data'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
