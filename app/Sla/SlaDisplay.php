<?php

namespace App\Sla;

use App\Models\SlaPolicy;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Texte zu den Fristen eines Tickets für Liste und Ticketansicht */
class SlaDisplay
{
    /**
     * @return list<array{label: string, text: string, color: string}>
     */
    public static function rows(Ticket $ticket): array
    {
        $tz = $ticket->tenant?->timezone ?: 'Europe/Berlin';
        $now = CarbonImmutable::now();
        $rows = [];

        foreach (SlaPolicy::METRICS as $metric => $label) {
            $m = $ticket->sla[$metric] ?? null;
            if (! $m) {
                continue;
            }

            $breached = ! empty($m['breached']);

            if (! empty($m['met_at'])) {
                $at = CarbonImmutable::parse($m['met_at'])->setTimezone($tz)->format('d.m.Y H:i');
                $rows[] = ['label' => $label, 'text' => ($breached ? 'verspätet erledigt ' : 'erledigt ').$at, 'color' => $breached ? 'danger' : 'success'];
            } elseif (! empty($m['paused'])) {
                $left = max(0, (int) $m['target'] - (int) $m['used']);
                $rows[] = ['label' => $label, 'text' => 'pausiert (wartet), Rest '.self::duration($left), 'color' => 'gray'];
            } elseif (! empty($m['due_at'])) {
                $due = CarbonImmutable::parse($m['due_at']);
                $text = $due < $now
                    ? 'überschritten seit '.self::relative($due, $now)
                    : 'fällig in '.self::relative($now, $due);
                $rows[] = ['label' => $label, 'text' => $text.' ('.$due->setTimezone($tz)->format('d.m. H:i').')', 'color' => self::color($m, $now)];
            }
        }

        return $rows;
    }

    /** Kurzform für die Ticketliste: nächste laufende Frist */
    public static function short(Ticket $ticket): ?string
    {
        if (! $ticket->sla_due_at) {
            return null;
        }

        $now = CarbonImmutable::now();
        $due = CarbonImmutable::instance($ticket->sla_due_at);

        return $due < $now ? 'überfällig '.self::relative($due, $now) : 'in '.self::relative($now, $due);
    }

    public static function shortColor(Ticket $ticket): string
    {
        $now = CarbonImmutable::now();
        $colors = collect($ticket->sla ?? [])->filter(fn ($m) => ! empty($m['since']))->map(fn ($m) => self::color($m, $now));

        return $colors->contains('danger') ? 'danger' : ($colors->contains('warning') ? 'warning' : 'gray');
    }

    private static function color(array $m, CarbonImmutable $now): string
    {
        if (! empty($m['breached']) || CarbonImmutable::parse($m['due_at']) < $now) {
            return 'danger';
        }

        return ! empty($m['warned']) || CarbonImmutable::parse($m['warn_at']) <= $now ? 'warning' : 'gray';
    }

    /** Wanduhr-Abstand, z. B. "2 Std. 15 Min." oder "3 Tg." */
    private static function relative(CarbonInterface $from, CarbonInterface $to): string
    {
        return self::duration(intdiv(max(0, $to->getTimestamp() - $from->getTimestamp()), 60));
    }

    public static function duration(int $minutes): string
    {
        if ($minutes >= 2 * 24 * 60) {
            return intdiv($minutes, 24 * 60).' Tg.';
        }
        if ($minutes >= 60) {
            $h = intdiv($minutes, 60);
            $m = $minutes % 60;

            return $h.' Std.'.($m ? " {$m} Min." : '');
        }

        return max(0, $minutes).' Min.';
    }
}
