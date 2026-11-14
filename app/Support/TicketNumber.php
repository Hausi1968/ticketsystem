<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Vergibt Ticketnummern fortlaufend und über alle Mandanten eindeutig. */
class TicketNumber
{
    public static function next(): int
    {
        return DB::transaction(function () {
            $number = (int) DB::table('ticket_sequence')->where('id', 1)->lockForUpdate()->value('next_number');
            DB::table('ticket_sequence')->where('id', 1)->update(['next_number' => $number + 1]);

            return $number;
        });
    }

    /** Liest die Nummer aus einer Eingabe wie "MUS-1000", "#1000" oder "1000". */
    public static function parse(string $input): ?int
    {
        return preg_match('/(?:^|[^0-9])(\d{1,9})\s*\]?\s*$/', trim($input), $m) ? (int) $m[1] : null;
    }
}
