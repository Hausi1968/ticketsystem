<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class TenantCounter extends Model
{
    protected $primaryKey = 'tenant_id';

    public $incrementing = false;

    protected $fillable = ['tenant_id', 'next_ticket_number'];

    /** Vergibt die nächste Ticketnummer eines Mandanten lückenlos (mit Zeilensperre). */
    public static function nextTicketNumber(int $tenantId): int
    {
        return DB::transaction(function () use ($tenantId) {
            $counter = static::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $number = $counter->next_ticket_number;
            $counter->increment('next_ticket_number');

            return $number;
        });
    }
}
