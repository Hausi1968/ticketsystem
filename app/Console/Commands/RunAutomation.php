<?php

namespace App\Console\Commands;

use App\Mail\Outbound\AcknowledgementSender;
use App\Mail\Outbound\ReplySender;
use App\Models\Mailbox;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Throwable;

/**
 * Läuft stündlich. Je Mandant einstellbar (Verwaltung → Mandanten, 0 = aus):
 *  - Erinnerung an den Kunden, wenn ein Ticket seit X Tagen auf ihn wartet (einmal je Wartezeit)
 *  - "Wartet auf Kunde" ohne Antwort nach X Tagen → "Gelöst"
 *  - "Gelöst" nach X Tagen → "Geschlossen" (danach legt eine Kundenantwort je nach Status ein neues Ticket an)
 * Gezählt wird ab der letzten Nachricht des Teams bzw. der Erinnerung, in Kalendertagen.
 */
class RunAutomation extends Command
{
    protected $signature = 'anliego:automation';

    protected $description = 'Erinnert Kunden und schließt wartende bzw. gelöste Tickets nach den Einstellungen je Mandant';

    public const DEFAULT_REMINDER = <<<'TXT'
        Guten Tag {name},

        wir warten noch auf Ihre Rückmeldung zu Ihrem Anliegen „{betreff}“ ({nummer}).

        Antworten Sie einfach auf diese E-Mail, dann geht es weiter. Ist alles erledigt, brauchen Sie nichts zu tun.

        Viele Grüße
        {absender}
        TXT;

    private int $reminded = 0;

    private int $solved = 0;

    private int $closed = 0;

    public function handle(TenantContext $tenancy, ReplySender $sender, AcknowledgementSender $guard): int
    {
        $tenants = $tenancy->runWithoutTenancy(fn () => Tenant::query()->where('status', 'active')->get());

        foreach ($tenants as $tenant) {
            if (! $tenant->days('reminder_days') && ! $tenant->days('auto_solve_days') && ! $tenant->days('auto_close_days')) {
                continue;
            }

            $tenancy->runFor($tenant, function () use ($tenant, $sender, $guard) {
                try {
                    $this->remind($tenant, $sender, $guard);
                    $this->solveWaiting($tenant);
                    $this->closeSolved($tenant);
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }

        $this->line("{$this->reminded} Erinnerung(en), {$this->solved} gelöst, {$this->closed} geschlossen.");

        return self::SUCCESS;
    }

    private function remind(Tenant $tenant, ReplySender $sender, AcknowledgementSender $guard): void
    {
        $days = $tenant->days('reminder_days');
        if (! $days) {
            return;
        }

        $this->waitingTickets(now()->subDays($days))->each(function (Ticket $ticket) use ($tenant, $days, $sender, $guard) {
            // Einmal je Wartezeit: nach einer neuen Nachricht des Teams darf wieder erinnert werden
            if ($ticket->reminded_at && (! $ticket->last_agent_message_at || $ticket->reminded_at >= $ticket->last_agent_message_at)) {
                return;
            }
            if ($this->waitingStart($ticket, withReminder: false) > now()->subDays($days)) {
                return;
            }

            $email = $ticket->contact?->email;
            if (! $email || $guard->skipReason($ticket, $email, null) === 'Absender erwartet keine Antwort') {
                return;
            }

            try {
                $sender->automatic($ticket, $this->render($ticket, $tenant, $days));
            } catch (Throwable $e) {
                report($e);

                return;
            }

            $ticket->forceFill(['reminded_at' => now()])->saveQuietly();
            $ticket->logEvent('reminder', data: ['days' => $days]);
            $this->reminded++;
        });
    }

    private function solveWaiting(Tenant $tenant): void
    {
        $days = $tenant->days('auto_solve_days');
        if (! $days) {
            return;
        }

        $this->waitingTickets(now()->subDays($days))->each(function (Ticket $ticket) use ($days) {
            if ($this->waitingStart($ticket, withReminder: true) > now()->subDays($days)) {
                return;
            }

            $ticket->changeStatus(TicketStatus::SOLVED, note: ['auto' => $days]);
            $this->solved++;
        });
    }

    private function closeSolved(Tenant $tenant): void
    {
        $days = $tenant->days('auto_close_days');
        $target = $this->closedStatus();
        if (! $days || ! $target) {
            return;
        }

        Ticket::query()->with('status')
            ->where('status_id', TicketStatus::system(TicketStatus::SOLVED)->getKey())
            ->where('closed_at', '<=', now()->subDays($days))
            ->limit(500)
            ->get()
            ->each(function (Ticket $ticket) use ($target, $days) {
                $ticket->changeStatus($target, note: ['auto' => $days]);
                $this->closed++;
            });
    }

    /** @return \Illuminate\Support\Collection<int, Ticket> */
    private function waitingTickets(CarbonInterface $cutoff)
    {
        return Ticket::query()->with(['status', 'contact', 'tenant', 'mailbox'])
            ->where('status_id', TicketStatus::system(TicketStatus::WAITING)->getKey())
            ->where(fn ($query) => $query->whereNull('waiting_since')->orWhere('waiting_since', '<=', $cutoff))
            ->limit(500)
            ->get();
    }

    /** Beginn der Wartezeit: Statuswechsel, letzte Nachricht des Teams oder (fürs Lösen) die Erinnerung */
    private function waitingStart(Ticket $ticket, bool $withReminder): CarbonInterface
    {
        $times = array_filter([$ticket->waiting_since, $ticket->last_agent_message_at, $withReminder ? $ticket->reminded_at : null]);

        return $times ? max($times) : $ticket->updated_at;
    }

    /** Ziel für gelöste Tickets: der erste aktive schließende Status außer "Gelöst" (vorbelegt: "Geschlossen") */
    private function closedStatus(): ?TicketStatus
    {
        return TicketStatus::query()
            ->where('active', true)->where('behaviour', 'closed')
            ->where(fn ($query) => $query->whereNull('system_key')->orWhere('system_key', '!=', TicketStatus::SOLVED))
            ->orderBy('sort')->first();
    }

    private function render(Ticket $ticket, Tenant $tenant, int $days): string
    {
        $template = trim((string) $tenant->setting('reminder_text')) ?: self::DEFAULT_REMINDER;
        $mailbox = $ticket->mailbox ?? Mailbox::query()->where('active', true)->orderBy('id')->first();

        $text = strtr($template, [
            '{name}' => (string) $ticket->contact?->name,
            '{nummer}' => $ticket->reference(),
            '{betreff}' => (string) $ticket->subject,
            '{mandant}' => (string) $tenant->name,
            '{tage}' => (string) $days,
            '{absender}' => (string) $mailbox?->displayName(),
        ]);

        return trim(preg_replace('/[ \t]+,/', ',', $text));
    }
}
