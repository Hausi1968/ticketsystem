<?php

namespace App\Filament\Resources\TicketStatuses;

use App\Enums\StatusBehaviour;
use App\Filament\Resources\TicketStatuses\Pages\ManageTicketStatuses;
use App\Models\TicketStatus;
use App\Support\BadgeColor;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class TicketStatusResource extends Resource
{
    protected static ?string $model = TicketStatus::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Verwaltung';

    protected static ?string $slug = 'status';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'Status';

    protected static ?string $pluralModelLabel = 'Status';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Name')->required()->maxLength(100)->placeholder('z. B. In Lieferung'),
            Select::make('color')->label('Farbe')->options(BadgeColor::OPTIONS)->default('gray')->required(),
            Radio::make('behaviour')->label('Was bewirkt der Status?')->options(StatusBehaviour::class)
                ->default(StatusBehaviour::Open->value)->required()->live()->columnSpanFull()
                ->disabled(fn (?TicketStatus $record) => $record?->isSystem())
                ->helperText(fn (?TicketStatus $record) => $record?->isSystem()
                    ? 'Diesen Status setzt Anliego selbst (z. B. bei neuen Tickets oder nach einer Antwort). Er kann umbenannt, aber in seinem Verhalten nicht geändert werden.'
                    : 'Geschlossene Tickets verschwinden aus den offenen Listen.'),
            Toggle::make('reply_creates_new_ticket')->label('Antwortet der Kunde danach, ein neues Ticket anlegen (statt dieses wieder zu öffnen)')
                ->visible(fn (Get $get) => in_array($get('behaviour'), [StatusBehaviour::Closed, StatusBehaviour::Closed->value], true))
                ->columnSpanFull(),
            TextInput::make('sort')->label('Reihenfolge')->numeric()->default(100),
            Toggle::make('active')->label('Aktiv (zur Auswahl angeboten)')->default(true)
                ->disabled(fn (?TicketStatus $record) => $record?->isSystem()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('name')->label('Status')->badge()
                    ->color(fn (TicketStatus $record) => BadgeColor::resolve($record->color)),
                TextColumn::make('behaviour')->label('Verhalten')->badge()
                    ->formatStateUsing(fn (StatusBehaviour $state) => match ($state) {
                        StatusBehaviour::Open => 'bleibt offen',
                        StatusBehaviour::Pending => 'wartet',
                        StatusBehaviour::Closed => 'schließt das Ticket',
                    })
                    ->color(fn (StatusBehaviour $state) => $state === StatusBehaviour::Closed ? 'success' : 'gray')
                    ->description(fn (TicketStatus $record) => $record->closes()
                        ? ($record->reply_creates_new_ticket ? 'Kundenantwort → neues Ticket' : 'Kundenantwort öffnet wieder')
                        : null),
                IconColumn::make('active')->label('Aktiv')->boolean(),
                TextColumn::make('system_key')->label('Hinweis')->placeholder('')
                    ->formatStateUsing(fn ($state) => $state ? 'vom System genutzt' : null)->color('gray'),
                TextColumn::make('tickets_count')->counts('tickets')->label('Tickets'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn (TicketStatus $record) => ! $record->isSystem())
                    ->before(function (TicketStatus $record, DeleteAction $action) {
                        if ($record->tickets()->exists()) {
                            Notification::make()->danger()->title('Status wird noch verwendet')
                                ->body('Bitte die Tickets umstellen oder den Status deaktivieren.')->send();
                            $action->cancel();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTicketStatuses::route('/')];
    }
}
