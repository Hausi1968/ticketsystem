<?php

namespace App\Filament\Resources\Mailboxes\Tables;

use App\Mail\Inbound\MailFetcher;
use App\Models\Mailbox;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class MailboxesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('tenant', 'team'))
            ->columns([
                TextColumn::make('tenant.short_code')->label('Mandant')->badge()
                    ->color(fn ($record) => Color::hex($record->tenant->color)),
                TextColumn::make('email')->label('Adresse')->description(fn (Mailbox $r) => $r->name)->searchable(),
                TextColumn::make('usage')->label('Verwendung')->badge()
                    ->formatStateUsing(fn (string $state) => ['both' => 'Abruf + Versand', 'fetch' => 'Nur Abruf', 'send' => 'Nur Versand'][$state] ?? $state)
                    ->color(fn (string $state) => $state === 'both' ? 'gray' : 'info'),
                TextColumn::make('team.name')->label('Team')->placeholder('–'),
                TextColumn::make('poll_interval_minutes')->label('Intervall')->suffix(' min')
                    ->formatStateUsing(fn ($state, Mailbox $record) => $record->canFetch() ? $state : '–'),
                IconColumn::make('active')->label('Aktiv')->boolean(),
                TextColumn::make('last_polled_at')->label('Letzter Abruf')->since()->placeholder('noch nie')
                    ->description(fn (Mailbox $r) => $r->last_error ? 'Fehler: '.str($r->last_error)->limit(60) : null)
                    ->color(fn (Mailbox $r) => $r->last_error ? 'danger' : null),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('test')->label('Verbindung testen')->icon(Heroicon::OutlinedSignal)
                        ->visible(fn (Mailbox $record) => (bool) $record->imap_host)
                        ->action(function (Mailbox $record) {
                            try {
                                $message = app(MailFetcher::class)->test($record);
                                Notification::make()->success()->title('IMAP ok')->body($message)->send();
                            } catch (Throwable $e) {
                                Notification::make()->danger()->title('IMAP-Verbindung fehlgeschlagen')->body($e->getMessage())->persistent()->send();
                            }
                        }),
                    Action::make('poll')->label('Jetzt abrufen')->icon(Heroicon::OutlinedArrowPath)
                        ->visible(fn (Mailbox $record) => $record->canFetch())
                        ->action(function (Mailbox $record) {
                            $exit = Artisan::call('anliego:poll-mailboxes', ['--mailbox' => $record->id, '--force' => true]);
                            $output = trim(Artisan::output());
                            Notification::make()->{$exit === 0 ? 'success' : 'danger'}()
                                ->title($exit === 0 ? 'Abgerufen' : 'Abruf fehlgeschlagen')->body($output)->send();
                        }),
                    EditAction::make(),
                ]),
            ]);
    }
}
