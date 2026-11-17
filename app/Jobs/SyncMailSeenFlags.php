<?php

namespace App\Jobs;

use App\Enums\MessageType;
use App\Mail\Inbound\MailFetcher;
use App\Models\Mailbox;
use App\Models\MailReceipt;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Für Postfächer mit „als gelesen markieren: erst wenn das Ticket gelöst oder geschlossen ist“:
 * Gleicht das Gelesen-Kennzeichen der E-Mails eines Tickets im IMAP-Postfach mit seinem Status ab.
 * Ticket gelöst/geschlossen → gelesen, wieder geöffnet → ungelesen. Maßgeblich ist der Status beim
 * Ausführen, deshalb schadet es nicht, wenn sich der Status inzwischen noch einmal geändert hat.
 */
class SyncMailSeenFlags implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $tenantId, public int $ticketId) {}

    public function backoff(): array
    {
        return [120, 900];
    }

    public function handle(MailFetcher $fetcher, TenantContext $tenancy): void
    {
        $tenancy->runFor($this->tenantId, function () use ($fetcher) {
            $ticket = Ticket::query()->with('status')->find($this->ticketId);
            if (! $ticket || $ticket->merged_into_id) {
                return; // Nachrichten zusammengeführter Tickets gehören zum Ziel-Ticket
            }

            $messageIds = TicketMessage::query()
                ->where('ticket_id', $ticket->getKey())
                ->where('type', MessageType::Customer)
                ->whereNotNull('message_id')
                ->pluck('message_id')->unique()->values();

            if ($messageIds->isEmpty()) {
                return;
            }

            // Über welche Postfächer kamen diese E-Mails? (auch als Kopie an ein zweites Postfach)
            $byMailbox = MailReceipt::query()
                ->whereIn('message_id', $messageIds)
                ->get(['mailbox_id', 'message_id'])
                ->groupBy('mailbox_id');

            $seen = $ticket->status?->closes() ?? false;

            foreach (Mailbox::query()->whereKey($byMailbox->keys())->get() as $mailbox) {
                if (! $mailbox->marksSeenOnResolve()) {
                    continue;
                }

                $fetcher->setSeen($mailbox, $byMailbox[$mailbox->getKey()]->pluck('message_id')->unique()->values()->all(), $seen);
            }
        });
    }

    /** Nach einem Statuswechsel einplanen, wenn der Mandant ein Postfach mit dieser Einstellung hat. */
    public static function dispatchIfNeeded(Ticket $ticket): void
    {
        $needed = Mailbox::query()->where('tenant_id', $ticket->tenant_id)
            ->where('mark_seen', 'resolved')->where('usage', '!=', 'send')->exists();

        if ($needed) {
            static::dispatch((int) $ticket->tenant_id, (int) $ticket->getKey())->afterCommit();
        }
    }
}
