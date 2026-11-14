<?php

namespace App\Filament\Resources\Tickets\Tables;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\Ticket;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TicketsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->poll('30s')
            ->recordUrl(fn (Ticket $record) => route('filament.app.resources.tickets.view', $record))
            ->columns([
                TextColumn::make('number')->label('Ticket')->badge()
                    ->formatStateUsing(fn (Ticket $record) => $record->reference())
                    ->color(fn (Ticket $record) => Color::hex($record->tenant->color))
                    ->tooltip(fn (Ticket $record) => $record->tenant->name)
                    ->searchable(),
                TextColumn::make('subject')->label('Betreff')->searchable()->wrap()->limit(80)
                    ->description(fn (Ticket $record) => $record->contact?->label()),
                TextColumn::make('status')->label('Status')->badge()->sortable(),
                TextColumn::make('priority')->label('Priorität')->badge()->sortable(),
                TextColumn::make('assignee.name')->label('Bearbeiter')->placeholder('– niemand –'),
                TextColumn::make('updated_at')->label('Aktualisiert')->since()->sortable()
                    ->tooltip(fn (Ticket $record) => $record->updated_at?->timezone('Europe/Berlin')->format('d.m.Y H:i')),
            ])
            ->filters([
                SelectFilter::make('tenant_id')->label('Mandant')
                    ->options(fn () => Tenant::query()->accessible()->orderBy('name')->pluck('name', 'id')),
                SelectFilter::make('team_id')->label('Team')
                    ->options(fn () => Team::query()->with('tenant')->orderBy('name')->get()
                        ->mapWithKeys(fn (Team $t) => [$t->id => "{$t->name} ({$t->tenant->short_code})"])),
                SelectFilter::make('priority')->label('Priorität')->options(TicketPriority::class),
                SelectFilter::make('status')->label('Status')->options(TicketStatus::class)->multiple(),
            ])
            ->recordActions([
                self::takeAction(),
            ]);
    }

    public static function takeAction(): Action
    {
        return Action::make('take')->label('Übernehmen')->icon(Heroicon::OutlinedHandRaised)
            ->visible(fn (Ticket $record) => $record->assignee_id !== auth()->id()
                && in_array($record->status, TicketStatus::unresolved(), true))
            ->requiresConfirmation(fn (Ticket $record) => $record->assignee_id !== null)
            ->modalDescription(fn (Ticket $record) => $record->assignee
                ? "Das Ticket ist {$record->assignee->name} zugewiesen. Trotzdem übernehmen?" : null)
            ->action(function (Ticket $record) {
                $record->assignTo(auth()->user(), auth()->user());
                Notification::make()->success()->title("{$record->reference()} übernommen")->send();
            });
    }
}
