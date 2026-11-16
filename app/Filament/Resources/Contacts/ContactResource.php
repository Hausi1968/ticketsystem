<?php

namespace App\Filament\Resources\Contacts;

use App\Filament\Resources\Contacts\Pages\ManageContacts;
use App\Models\Contact;
use App\Models\Tenant;
use App\Privacy\ContactPrivacy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

/** Kontakte (Kunden) mit Datenschutz-Funktionen: Auskunft als Datei und Löschen auf Wunsch. */
class ContactResource extends Resource
{
    protected static ?string $model = Contact::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Verwaltung';

    protected static ?string $slug = 'kontakte';

    protected static ?int $navigationSort = 9;

    protected static ?string $modelLabel = 'Kontakt';

    protected static ?string $pluralModelLabel = 'Kontakte (Datenschutz)';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('tenant')->withCount('tickets'))
            ->defaultSort('email')
            ->columns([
                TextColumn::make('name')->label('Name')->searchable()->placeholder('–'),
                TextColumn::make('email')->label('E-Mail')->searchable(),
                TextColumn::make('external_user_id')->label('Kennung (App)')->placeholder('–')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('tenant.name')->label('Mandant'),
                TextColumn::make('tickets_count')->label('Tickets'),
                TextColumn::make('created_at')->label('Angelegt')->date('d.m.Y')->sortable(),
            ])
            ->filters([
                SelectFilter::make('tenant_id')->label('Mandant')
                    ->options(fn () => Tenant::query()->orderBy('name')->pluck('name', 'id')),
            ])
            ->recordActions([
                Action::make('export')->label('Auskunft')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                    ->tooltip('Alle gespeicherten Daten dieses Kontakts als Datei herunterladen (DSGVO Art. 15)')
                    ->action(function (Contact $record) {
                        $json = json_encode(app(ContactPrivacy::class)->export($record),
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                        return response()->streamDownload(fn () => print ($json),
                            'auskunft-'.Str::slug($record->email).'.json', ['Content-Type' => 'application/json']);
                    }),
                Action::make('anonymize')->label('Löschen')->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->hidden(fn (Contact $record) => str_ends_with($record->email, '@anonym.invalid'))
                    ->modalHeading('Kontakt löschen (DSGVO Art. 17)')
                    ->modalDescription('Name, E-Mail-Adresse, eigene Nachrichten, Anhänge und Original-E-Mails des Kontakts werden endgültig entfernt. '
                        .'Die Tickets bleiben ohne diese Daten für Verlauf und Statistik erhalten. Das lässt sich nicht rückgängig machen.')
                    ->modalSubmitActionLabel('Endgültig löschen')
                    ->schema([
                        TextInput::make('confirm')->label('Zur Bestätigung die E-Mail-Adresse eingeben')->required()
                            ->rule(fn (Contact $record) => function (string $attribute, $value, $fail) use ($record) {
                                if (mb_strtolower(trim((string) $value)) !== $record->email) {
                                    $fail('Die E-Mail-Adresse stimmt nicht überein.');
                                }
                            }),
                    ])
                    ->action(function (Contact $record) {
                        $count = app(ContactPrivacy::class)->anonymize($record, auth()->user());
                        Notification::make()->success()->title('Kontakt gelöscht')
                            ->body("Die Daten wurden aus {$count} Ticket(s) entfernt.")->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageContacts::route('/')];
    }
}
