<?php

namespace App\Tickets;

use App\Enums\MembershipRole;
use App\Enums\MessageType;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketRule;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Wendet die Regeln auf ein neu angelegtes Ticket an (nach der ersten Nachricht, vor den Hinweisen ans Team). */
class RuleEngine
{
    public function apply(Ticket $ticket): void
    {
        $rules = TicketRule::query()->forTenant((int) $ticket->tenant_id)->where('active', true)
            ->orderBy('sort')->orderBy('id')->get();

        if ($rules->isEmpty()) {
            return;
        }

        $values = $this->values($ticket);

        foreach ($rules as $rule) {
            if (! $rule->matches($values)) {
                continue;
            }

            try {
                $this->run($rule, $ticket);
            } catch (Throwable $e) {
                // Eine fehlerhafte Regel darf das Anlegen des Tickets nie verhindern
                Log::warning('Regel konnte nicht angewendet werden', ['rule' => $rule->getKey(), 'error' => $e->getMessage()]);
            }

            if ($rule->stop_processing) {
                break;
            }
        }
    }

    /** @return array<string, string> */
    private function values(Ticket $ticket): array
    {
        $first = $ticket->messages()->where('type', MessageType::Customer)->first();

        return [
            'subject' => (string) $ticket->subject,
            'body' => (string) $first?->body_text,
            'from' => (string) ($first?->from_email ?? $ticket->contact?->email),
            'to' => (string) $ticket->mailbox?->email,
            'channel' => (string) $ticket->channel,
        ];
    }

    private function run(TicketRule $rule, Ticket $ticket): void
    {
        $changes = [];

        $team = $rule->team_id ? Team::query()->where('tenant_id', $ticket->tenant_id)->find($rule->team_id) : null;
        if ($team && $ticket->team_id !== $team->getKey()) {
            $ticket->forceFill(['team_id' => $team->getKey()])->save();
            $changes['team'] = $team->name;
        }

        if ($rule->category_id && ($category = TicketCategory::query()->find($rule->category_id)) && $category->appliesTo((int) $ticket->tenant_id)) {
            $ticket->changeCategory($category);
        }

        if ($rule->priority) {
            $ticket->changePriority($rule->priority);
        }

        $assignee = match ($rule->assign) {
            'user' => $this->eligible($ticket)->whereKey($rule->assignee_id)->first(),
            'round_robin' => $this->nextInTeam($ticket),
            default => null,
        };
        if ($assignee && ! $ticket->assignee_id) {
            $ticket->assignTo($assignee);
        }

        $ticket->logEvent('rule', data: ['rule' => $rule->name] + $changes);
    }

    /** Aktive Mitarbeiter, die Tickets dieses Mandanten bearbeiten dürfen */
    private function eligible(Ticket $ticket)
    {
        return User::query()->where('active', true)
            ->where(fn ($q) => $q->where('is_admin', true)
                ->orWhereHas('memberships', fn ($m) => $m->where('tenant_id', $ticket->tenant_id)->where('role', '!=', MembershipRole::LightAgent->value)));
    }

    /** Reihum: das Teammitglied mit den wenigsten offenen Tickets dieses Mandanten */
    private function nextInTeam(Ticket $ticket): ?User
    {
        $team = $ticket->team_id ? Team::query()->find($ticket->team_id) : null;
        if (! $team) {
            return null;
        }

        $ids = $team->users()->wherePivot('receives_assignments', true)->pluck('users.id');

        return $this->eligible($ticket)->whereIn('id', $ids)
            ->withCount(['assignedTickets as open_count' => fn ($q) => $q->where('tenant_id', $ticket->tenant_id)->unresolved()])
            ->orderBy('open_count')->orderBy('id')->first();
    }
}
