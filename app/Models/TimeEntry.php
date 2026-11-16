<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Erfasste Arbeitszeit an einem Ticket (z. B. für die Abrechnung je Mandant) */
class TimeEntry extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'ticket_id', 'user_id', 'ticket_message_id', 'minutes', 'worked_on', 'note'];

    protected function casts(): array
    {
        return ['minutes' => 'integer', 'worked_on' => 'date'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** z. B. 95 → "1:35 Std." */
    public static function format(int $minutes): string
    {
        return intdiv($minutes, 60).':'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).' Std.';
    }
}
