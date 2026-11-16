<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/** Läuft jede Minute: Tickets, deren Wiedervorlage fällig ist, kommen zurück in die Arbeitslisten. */
class WakeSnoozed extends Command
{
    protected $signature = 'anliego:wake-snoozed';

    protected $description = 'Holt zurückgestellte Tickets zur Wiedervorlage zurück und benachrichtigt';

    public function handle(TenantContext $tenancy): int
    {
        $due = $tenancy->runWithoutTenancy(fn () => Ticket::query()
            ->whereNotNull('snoozed_until')->where('snoozed_until', '<=', now())->limit(500)->get(['id', 'tenant_id']));

        $count = 0;
        foreach ($due as $row) {
            try {
                $tenancy->runFor((int) $row->tenant_id, function () use ($row, &$count) {
                    $ticket = Ticket::query()->find($row->id);
                    if ($ticket && $ticket->snoozed_until?->isPast()) {
                        $ticket->unsnooze(null, 'due');
                        $count++;
                    }
                });
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->line("{$count} Ticket(s) zur Wiedervorlage.");

        return self::SUCCESS;
    }
}
