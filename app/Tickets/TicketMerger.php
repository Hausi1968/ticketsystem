<?php

namespace App\Tickets;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Führt ein Ticket in ein anderes zusammen (z. B. wenn ein Kunde zweimal zum selben Anliegen schreibt).
 * Alle Nachrichten samt Anhängen wandern ins Ziel, der Absender wird dort beteiligt, das Quell-Ticket
 * wird geschlossen und verweist auf das Ziel. Spätere Antworten auf das Quell-Ticket landen im Ziel.
 */
class TicketMerger
{
    public function merge(Ticket $source, Ticket $target, ?User $actor = null): Ticket
    {
        $target = $target->mergeTarget();

        if ($source->is($target)) {
            throw new InvalidArgumentException('Ein Ticket kann nicht mit sich selbst zusammengeführt werden.');
        }
        if ((int) $source->tenant_id !== (int) $target->tenant_id) {
            throw new InvalidArgumentException('Nur Tickets desselben Mandanten lassen sich zusammenführen.');
        }
        if ($source->merged_into_id) {
            throw new InvalidArgumentException('Dieses Ticket wurde bereits zusammengeführt.');
        }

        return DB::transaction(function () use ($source, $target, $actor) {
            $moved = TicketMessage::query()->where('ticket_id', $source->getKey())->update(['ticket_id' => $target->getKey()]);

            if ($source->contact) {
                $target->addParticipant($source->contact);
            }
            foreach ($source->participants as $participant) {
                $target->addParticipant($participant);
            }

            $target->forceFill([
                'last_customer_message_at' => max($target->last_customer_message_at, $source->last_customer_message_at),
                'last_agent_message_at' => max($target->last_agent_message_at, $source->last_agent_message_at),
            ])->save();

            $source->forceFill(['merged_into_id' => $target->getKey()])->save();
            if (! $source->isClosed() && ($closed = TicketStatus::finalClosed())) {
                $source->changeStatus($closed, $actor, ['merged' => true]);
            }

            $source->logEvent('merged_into', $actor, ['ticket' => $target->reference(), 'messages' => $moved]);
            $target->logEvent('merged_from', $actor, ['ticket' => $source->reference(), 'messages' => $moved]);

            return $target;
        });
    }
}
