<?php

namespace App\Console\Commands;

use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Sla\SlaClock;
use App\Tenancy\TenantContext;
use App\Tickets\TicketNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Läuft jede Minute: meldet Fristen, die bald ablaufen (Warnung an den Bearbeiter) oder
 * überschritten sind (an Bearbeiter und Supervisor). Jede Meldung geht je Frist nur einmal raus.
 */
class CheckSla extends Command
{
    protected $signature = 'anliego:sla-check';

    protected $description = 'Warnt vor ablaufenden und meldet überschrittene SLA-Fristen';

    public function handle(TenantContext $tenancy, SlaClock $clock, TicketNotifier $notifier): int
    {
        $now = CarbonImmutable::now();

        $tenantIds = $tenancy->runWithoutTenancy(fn () => Ticket::query()
            ->whereNotNull('sla_next_check_at')->where('sla_next_check_at', '<=', $now)
            ->distinct()->pluck('tenant_id'));

        $warnings = 0;
        $breaches = 0;

        foreach ($tenantIds as $tenantId) {
            $tenancy->runFor((int) $tenantId, function () use ($now, $clock, $notifier, &$warnings, &$breaches) {
                Ticket::query()->with(['tenant', 'slaPolicy'])
                    ->whereNotNull('sla_next_check_at')->where('sla_next_check_at', '<=', $now)
                    ->orderBy('sla_next_check_at')->limit(500)
                    ->each(function (Ticket $ticket) use ($now, $clock, $notifier, &$warnings, &$breaches) {
                        try {
                            [$w, $b] = $this->check($ticket, $now, $clock, $notifier);
                            $warnings += $w;
                            $breaches += $b;
                        } catch (Throwable $e) {
                            report($e);
                        }
                    });
            });
        }

        $this->line("{$warnings} Warnung(en), {$breaches} Überschreitung(en) gemeldet.");

        return self::SUCCESS;
    }

    /** @return array{0: int, 1: int} */
    private function check(Ticket $ticket, CarbonImmutable $now, SlaClock $clock, TicketNotifier $notifier): array
    {
        $state = $ticket->sla ?? [];
        $events = [];

        foreach ($state as $metric => $m) {
            if (empty($m['since'])) {
                continue;
            }

            $due = CarbonImmutable::parse($m['due_at']);

            if (empty($m['breached']) && $due <= $now) {
                $state[$metric]['breached'] = true;
                $state[$metric]['warned'] = true;
                $events[] = ['sla_breach', $metric, $due];
            } elseif (empty($m['warned']) && CarbonImmutable::parse($m['warn_at']) <= $now) {
                $state[$metric]['warned'] = true;
                $events[] = ['sla_warning', $metric, $due];
            }
        }

        $ticket->sla = $state;
        $clock->refreshColumns($ticket);
        $ticket->timestamps = false; // Prüfen ist keine Bearbeitung ("Aktualisiert" bleibt)
        $ticket->saveQuietly();
        $ticket->timestamps = true;

        foreach ($events as [$event, $metric, $due]) {
            if ($event === 'sla_breach') {
                $ticket->logEvent('sla_breach', data: ['metric' => $metric, 'due_at' => $due->toIso8601String()]);
            }
            $notifier->slaAlert($ticket, $event, SlaPolicy::METRICS[$metric] ?? $metric, $due);
        }

        $warnings = count(array_filter($events, fn ($e) => $e[0] === 'sla_warning'));

        return [$warnings, count($events) - $warnings];
    }
}
