<?php

namespace App\Filament\Resources\Tickets;

use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Tickets\Tables\TicketsTable;
use App\Models\Ticket;
use App\Support\TicketNumber;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TicketResource extends Resource
{
    protected static ?string $model = Ticket::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $slug = 'tickets';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Ticket';

    protected static ?string $pluralModelLabel = 'Tickets';

    protected static ?string $recordTitleAttribute = 'subject';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['tenant', 'contact', 'assignee']);
    }

    public static function canCreate(): bool
    {
        return false; // Tickets entstehen aus E-Mails (später auch per API und manuell)
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Ticket::query()->whereNull('assignee_id')->whereIn('status', TicketStatus::unresolved())->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Unzugewiesene offene Tickets';
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['subject', 'contact.email'];
    }

    /** Suche oben rechts: "MUS-1000", "#1000" oder "1000" öffnet das Ticket direkt. */
    protected static function applyGlobalSearchAttributeConstraints(Builder $query, string $search): void
    {
        $number = TicketNumber::parse($search);

        $query->where(function (Builder $query) use ($search, $number) {
            $query->where(fn (Builder $inner) => parent::applyGlobalSearchAttributeConstraints($inner, $search));

            if ($number !== null) {
                $query->orWhere('number', $number);
            }
        });
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record->reference().' · '.$record->subject;
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            'Mandant' => $record->tenant?->name,
            'Status' => $record->status?->getLabel(),
            'Von' => $record->contact?->email,
        ]);
    }

    public static function table(Table $table): Table
    {
        return TicketsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTickets::route('/'),
            'view' => ViewTicket::route('/{record}'),
        ];
    }
}
