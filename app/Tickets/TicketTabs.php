<?php

namespace App\Tickets;

use App\Enums\StatusBehaviour;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Die Reiter der Ticketliste – gemeinsam für Weboberfläche und App. */
class TicketTabs
{
    public const LABELS = [
        'mine' => 'Meine',
        'unassigned' => 'Unzugewiesen',
        'teams' => 'Meine Teams',
        'open' => 'Alle offenen',
        'pending' => 'Wartend',
        'closed' => 'Geschlossen',
        'all' => 'Alle',
    ];

    public static function apply(Builder $query, string $tab, User|int|null $user): Builder
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        return match ($tab) {
            'mine' => $query->where('assignee_id', $userId)->unresolved(),
            'unassigned' => $query->whereNull('assignee_id')->unresolved(),
            // Offene Tickets der Teams, in denen der Mitarbeiter ist (egal, wer sie bearbeitet)
            'teams' => $query->whereIn('team_id', DB::table('team_user')->where('user_id', $userId)->select('team_id'))->unresolved(),
            'open' => $query->withBehaviour(StatusBehaviour::Open),
            'pending' => $query->withBehaviour(StatusBehaviour::Pending),
            'closed' => $query->withBehaviour(StatusBehaviour::Closed),
            default => $query,
        };
    }
}
