<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Enums\TicketStatus;
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
        $unresolved = TicketStatus::unresolved();
        $active = [TicketStatus::New, TicketStatus::Open];

        return [
            'mine' => Tab::make('Meine')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('assignee_id', auth()->id())->whereIn('status', $unresolved))
                ->badge(fn () => Ticket::query()->where('assignee_id', auth()->id())->whereIn('status', $active)->count() ?: null),
            'unassigned' => Tab::make('Unzugewiesen')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('assignee_id')->whereIn('status', $unresolved))
                ->badge(fn () => Ticket::query()->whereNull('assignee_id')->whereIn('status', $unresolved)->count() ?: null)
                ->badgeColor('warning'),
            'open' => Tab::make('Alle offenen')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', $active)),
            'pending' => Tab::make('Wartend')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', TicketStatus::pending())),
            'solved' => Tab::make('Gelöst')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [TicketStatus::Solved, TicketStatus::Closed])),
            'all' => Tab::make('Alle'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        // Wer nichts zugewiesen hat, startet in der Eingangs-Queue
        return Ticket::query()->where('assignee_id', auth()->id())->whereIn('status', TicketStatus::unresolved())->exists()
            ? 'mine' : 'unassigned';
    }
}
