<?php

namespace App\Tickets;

use App\Models\Ticket;
use App\Models\TicketPresence;
use App\Models\TicketRead;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Anwesenheit in Tickets ohne WebSockets: Die Ticketansicht meldet sich alle paar Sekunden,
 * wer länger als STALE_SECONDS nichts gemeldet hat, gilt als weg.
 */
class Presence
{
    public const STALE_SECONDS = 30;

    public function touch(Ticket $ticket, User $user, string $activity = TicketPresence::VIEWING): void
    {
        TicketPresence::query()->updateOrCreate(
            ['ticket_id' => $ticket->getKey(), 'user_id' => $user->getKey()],
            ['activity' => $activity, 'last_seen_at' => now()],
        );

        // Wer das Ticket offen hat, hat die Kundenantworten gesehen (Markierung „NEU“ entfällt)
        TicketRead::query()->updateOrCreate(
            ['ticket_id' => $ticket->getKey(), 'user_id' => $user->getKey()],
            ['read_at' => now()],
        );

        if (random_int(1, 100) === 1) {
            TicketPresence::query()->where('last_seen_at', '<', now()->subDay())->delete();
        }
    }

    public function leave(Ticket $ticket, User $user): void
    {
        TicketPresence::query()->where('ticket_id', $ticket->getKey())->where('user_id', $user->getKey())->delete();
    }

    /** Andere Mitarbeiter, die das Ticket gerade offen haben – Schreibende zuerst. */
    public function others(Ticket $ticket, User $user): Collection
    {
        return TicketPresence::query()->with('user')
            ->where('ticket_id', $ticket->getKey())
            ->where('user_id', '!=', $user->getKey())
            ->where('last_seen_at', '>=', now()->subSeconds(self::STALE_SECONDS))
            ->get()
            ->filter(fn (TicketPresence $p) => $p->user !== null)
            ->sortBy(fn (TicketPresence $p) => [$p->activity === TicketPresence::WRITING ? 0 : 1, $p->user->name])
            ->values();
    }
}
