<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kurzlebiger Eintrag „Mitarbeiter X hat Ticket Y offen“. Wird nur über das (mandantengeprüfte)
 * Ticket abgefragt und nach einem Tag aufgeräumt.
 */
class TicketPresence extends Model
{
    public const VIEWING = 'viewing';

    public const WRITING = 'writing';

    public $timestamps = false;

    protected $fillable = ['ticket_id', 'user_id', 'activity', 'last_seen_at'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
