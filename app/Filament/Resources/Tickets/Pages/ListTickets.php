<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Enums\StatusBehaviour;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Ticket;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListTickets extends ListRecords
{
    protected static string $resource = TicketResource::class;

    public function getTabs(): array
    {
        return [
            'mine' => Tab::make('Meine')
                ->modifyQueryUsing(fn (Builder $query) => $this->searching() ? $query : $query->where('assignee_id', auth()->id())->unresolved())
                ->badge(fn () => Ticket::query()->where('assignee_id', auth()->id())->withBehaviour(StatusBehaviour::Open)->count() ?: null),
            'unassigned' => Tab::make('Unzugewiesen')
                ->modifyQueryUsing(fn (Builder $query) => $this->searching() ? $query : $query->whereNull('assignee_id')->unresolved())
                ->badge(fn () => Ticket::query()->whereNull('assignee_id')->unresolved()->count() ?: null)
                ->badgeColor('warning'),
            'open' => Tab::make('Alle offenen')
                ->modifyQueryUsing(fn (Builder $query) => $this->searching() ? $query : $query->withBehaviour(StatusBehaviour::Open)),
            'pending' => Tab::make('Wartend')
                ->modifyQueryUsing(fn (Builder $query) => $this->searching() ? $query : $query->withBehaviour(StatusBehaviour::Pending)),
            'closed' => Tab::make('Geschlossen')
                ->modifyQueryUsing(fn (Builder $query) => $this->searching() ? $query : $query->withBehaviour(StatusBehaviour::Closed)),
            'all' => Tab::make('Alle'),
        ];
    }

    /** Bei einer Suche gilt der Reiter nicht: gesucht wird in allen Tickets, auch wartenden und geschlossenen. */
    protected function searching(): bool
    {
        return filled(trim((string) $this->tableSearch));
    }

    public function getDefaultActiveTab(): string|int|null
    {
        // Wer nichts zugewiesen hat, startet in der Eingangs-Queue
        return Ticket::query()->where('assignee_id', auth()->id())->unresolved()->exists()
            ? 'mine' : 'unassigned';
    }
}
