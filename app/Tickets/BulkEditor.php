<?php

namespace App\Tickets;

use App\Enums\MembershipRole;
use App\Enums\TicketPriority;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Mehrere Tickets auf einmal ändern. Tickets, die der Mitarbeiter nicht bearbeiten darf, die
 * zusammengeführt sind oder zu denen die Auswahl nicht passt, werden übersprungen und gezählt.
 */
class BulkEditor
{
    public static function canWrite(User $user, Ticket $ticket): bool
    {
        return $user->is_admin || ! in_array($user->roleIn($ticket->tenant_id), [null, MembershipRole::LightAgent], true);
    }

    /** Darf $assignee Tickets dieses Mandanten bearbeiten? */
    public static function canBeAssigned(User $assignee, Ticket $ticket): bool
    {
        return $assignee->active && self::canWrite($assignee, $ticket);
    }

    /** @return array{0:int,1:int} [geändert, übersprungen] */
    public function assign(Collection $tickets, ?User $assignee, User $actor): array
    {
        return $this->each($tickets, $actor, function (Ticket $ticket) use ($assignee, $actor) {
            if ($assignee && ! self::canBeAssigned($assignee, $ticket)) {
                return false;
            }
            $ticket->assignTo($assignee, $actor);

            return true;
        });
    }

    public function status(Collection $tickets, TicketStatus $status, User $actor): array
    {
        return $this->each($tickets, $actor, fn (Ticket $ticket) => $ticket->changeStatus($status, $actor) ?? true);
    }

    public function priority(Collection $tickets, TicketPriority $priority, User $actor): array
    {
        return $this->each($tickets, $actor, fn (Ticket $ticket) => $ticket->changePriority($priority, $actor) ?? true);
    }

    public function category(Collection $tickets, ?TicketCategory $category, User $actor): array
    {
        return $this->each($tickets, $actor, function (Ticket $ticket) use ($category, $actor) {
            if ($category && ! $category->appliesTo((int) $ticket->tenant_id)) {
                return false;
            }
            $ticket->changeCategory($category, $actor);

            return true;
        });
    }

    private function each(Collection $tickets, User $actor, callable $change): array
    {
        $done = $skipped = 0;

        foreach ($tickets as $ticket) {
            if ($ticket->merged_into_id || ! self::canWrite($actor, $ticket) || $change($ticket) === false) {
                $skipped++;

                continue;
            }
            $done++;
        }

        return [$done, $skipped];
    }
}
