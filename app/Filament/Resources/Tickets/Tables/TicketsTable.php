<?php

namespace App\Filament\Resources\Tickets\Tables;

use App\Enums\TicketPriority;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketStatus;
use App\Support\BadgeColor;
use App\Support\TicketNumber;
use Illuminate\Database\Eloquent\Builder;
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
                    // Suche nach "MUS-1000", "#1000" oder "1000"
                    ->searchable(query: fn (Builder $query, string $search) => ($number = TicketNumber::parse($search)) !== null
                        ? $query->orWhere('number', $number)
                        : $query),
                TextColumn::make('subject')->label('Betreff')->searchable()->wrap()->limit(80)
                    ->description(fn (Ticket $record) => $record->contact?->label()),
                TextColumn::make('status.name')->label('Status')->badge()
                    ->color(fn (Ticket $record) => BadgeColor::resolve($record->status?->color)),
                TextColumn::make('category.name')->label('Kategorie')->badge()->placeholder('–')
                    ->color(fn (Ticket $record) => BadgeColor::resolve($record->category?->color))
                    ->toggleable(),
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
                SelectFilter::make('status_id')->label('Status')->multiple()
                    ->options(fn () => TicketStatus::query()->ordered()->pluck('name', 'id')),
                SelectFilter::make('category_id')->label('Kategorie')->multiple()
                    ->options(fn () => TicketCategory::query()->with('tenant')->ordered()->get()
                        ->mapWithKeys(fn (TicketCategory $c) => [$c->id => $c->name.($c->tenant ? " ({$c->tenant->short_code})" : '')])),
            ])
            ->recordActions([
                self::takeAction(),
            ]);
    }

    public static function takeAction(): Action
    {
        return Action::make('take')->label('Übernehmen')->icon(Heroicon::OutlinedHandRaised)
            ->visible(fn (Ticket $record) => $record->assignee_id !== auth()->id()
                && ! $record->isClosed())
            ->requiresConfirmation(fn (Ticket $record) => $record->assignee_id !== null)
            ->modalDescription(fn (Ticket $record) => $record->assignee
                ? "Das Ticket ist {$record->assignee->name} zugewiesen. Trotzdem übernehmen?" : null)
            ->action(function (Ticket $record) {
                $record->assignTo(auth()->user(), auth()->user());
                Notification::make()->success()->title("{$record->reference()} übernommen")->send();
            });
    }
}
