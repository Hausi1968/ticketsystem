<?php

namespace App\Tickets;

use App\Enums\MessageType;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketActivity;
use App\Support\QuoteStripper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Wer erfährt wovon?
 * - Neues Ticket: die Mitglieder des Teams (falls gesetzt), sonst alle Mitarbeiter des Mandanten.
 * - Zuweisung: der neue Bearbeiter, außer er hat sich das Ticket selbst genommen.
 * - Kundenantwort: der Bearbeiter; ohne Bearbeiter wie bei einem neuen Ticket.
 * Versand erst nach erfolgreichem Speichern (afterCommit), Fehler blockieren nie den Ticketablauf.
 */
class TicketNotifier
{
    public function ticketCreated(Ticket $ticket): void
    {
        $this->later(fn () => $this->send($this->teamOrTenantMembers($ticket), 'new_ticket', $ticket));
    }

    public function customerReplied(Ticket $ticket): void
    {
        $this->later(function () use ($ticket) {
            $recipients = $ticket->assignee_id
                ? User::query()->whereKey($ticket->assignee_id)->where('active', true)->get()
                : $this->teamOrTenantMembers($ticket);

            $this->send($recipients, 'customer_reply', $ticket);
        });
    }

    public function assigned(Ticket $ticket, User $assignee, ?User $actor): void
    {
        if ($actor && $actor->is($assignee)) {
            return;
        }

        $this->later(fn () => $this->send(collect([$assignee])->where('active', true), 'assigned', $ticket, $actor));
    }

    /** @return Collection<int, User> */
    private function teamOrTenantMembers(Ticket $ticket): Collection
    {
        $query = User::query()->where('active', true);

        if ($ticket->team_id) {
            $query->whereIn('id', DB::table('team_user')->where('team_id', $ticket->team_id)->select('user_id'));
        } else {
            $query->whereIn('id', DB::table('tenant_user')->where('tenant_id', $ticket->tenant_id)->select('user_id'));
        }

        return $query->get();
    }

    private function send(Collection $recipients, string $event, Ticket $ticket, ?User $actor = null): void
    {
        $actor ??= auth()->user();
        $recipients = $recipients->reject(fn (User $u) => $actor && $u->is($actor))->values();

        if ($recipients->isEmpty()) {
            return;
        }

        try {
            Notification::send($recipients, new TicketActivity($event, $this->describe($ticket, $actor)));
        } catch (Throwable $e) {
            Log::warning('Benachrichtigung fehlgeschlagen', ['ticket' => $ticket->getKey(), 'event' => $event, 'error' => $e->getMessage()]);
        }
    }

    private function describe(Ticket $ticket, ?User $actor): array
    {
        $ticket->loadMissing('tenant', 'contact');
        $latest = $ticket->messages()->where('type', MessageType::Customer)->reorder()->latest('id')->first();

        return [
            'reference' => $ticket->reference(),
            'subject' => Str::limit((string) $ticket->subject, 120),
            'tenant' => (string) $ticket->tenant?->name,
            'from' => (string) ($ticket->contact?->name ?: $ticket->contact?->email ?: 'Unbekannt'),
            'excerpt' => $latest ? Str::limit(trim(preg_replace('/\s+/', ' ', QuoteStripper::strip((string) $latest->body_text))), 240) ?: null : null,
            'url' => TicketResource::getUrl('view', ['record' => $ticket->getKey()], panel: 'app'),
            'actor' => $actor?->name,
        ];
    }

    private function later(callable $callback): void
    {
        DB::afterCommit($callback);
    }
}
