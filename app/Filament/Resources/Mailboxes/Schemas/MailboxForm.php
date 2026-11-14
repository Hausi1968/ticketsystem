<?php

namespace App\Filament\Resources\Mailboxes\Schemas;

use App\Mail\Outbound\AcknowledgementSender;
use App\Models\Team;
use App\Models\Tenant;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class MailboxForm
{
    private const ENCRYPTION = ['ssl' => 'SSL/TLS', 'tls' => 'STARTTLS', 'none' => 'Keine (nicht empfohlen)'];

    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Postfach')->columns(2)->schema([
                Select::make('tenant_id')->label('Mandant')
                    ->options(fn () => Tenant::query()->orderBy('name')->pluck('name', 'id'))
                    ->required()->live()->disabledOn('edit'),
                Select::make('team_id')->label('Team / Queue')
                    ->helperText('Neue Tickets aus diesem Postfach landen in diesem Team.')
                    ->options(fn (Get $get) => $get('tenant_id')
                        ? Team::query()->withoutGlobalScopes()->where('tenant_id', $get('tenant_id'))->orderBy('name')->pluck('name', 'id')
                        : []),
                TextInput::make('name')->label('Bezeichnung')->required()->maxLength(255)->placeholder('z. B. Auftragsbearbeitung'),
                TextInput::make('email')->label('E-Mail-Adresse')->email()->required()->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('from_name')->label('Absendername')->maxLength(255)
                    ->helperText('Leer = Name des Mandanten'),
                TextInput::make('poll_interval_minutes')->label('Abruf alle … Minuten')->numeric()
                    ->minValue(1)->maxValue(1440)->default(5)->required(),
                Toggle::make('active')->label('Aktiv')->default(true),
            ]),

            Section::make('Eingangsbestätigung')
                ->description('Automatische Antwort an den Kunden, sobald aus seiner E-Mail oder App-Meldung ein neues Ticket entsteht.')
                ->schema([
                    Toggle::make('send_acknowledgement')->label('Eingangsbestätigung senden')->default(true)->live()
                        ->helperText('Nicht an noreply-Adressen, Newsletter, eigene Postfächer und höchstens einmal in 15 Minuten je Kunde.'),
                    Textarea::make('acknowledgement_text')->label('Text')->rows(10)
                        ->placeholder(AcknowledgementSender::DEFAULT_TEXT)
                        ->helperText('Leer = Standardtext (siehe Platzhalter). Platzhalter: {name}, {nummer}, {betreff}, {mandant}, {absender}')
                        ->visible(fn (Get $get) => (bool) $get('send_acknowledgement')),
                ]),

            Section::make('Eingang (IMAP)')->columns(3)->schema([
                TextInput::make('imap_host')->label('Server')->required()->placeholder('imap.ionos.de'),
                TextInput::make('imap_port')->label('Port')->numeric()->default(993)->required(),
                Select::make('imap_encryption')->label('Verschlüsselung')->options(self::ENCRYPTION)->default('ssl')->required(),
                TextInput::make('imap_username')->label('Benutzername')->required(),
                TextInput::make('imap_password')->label('Passwort')->password()->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->helperText(fn (string $operation) => $operation === 'edit' ? 'Leer lassen, um das gespeicherte Passwort zu behalten.' : null),
                TextInput::make('imap_folder')->label('Ordner')->default('INBOX')->required(),
                TextInput::make('processed_folder')->label('Verarbeitete E-Mails verschieben nach')
                    ->placeholder('leer = nur als gelesen markieren')->columnSpan(2),
            ]),

            Section::make('Ausgang (SMTP)')->columns(3)->schema([
                TextInput::make('smtp_host')->label('Server')->required()->placeholder('smtp.ionos.de'),
                TextInput::make('smtp_port')->label('Port')->numeric()->default(465)->required(),
                Select::make('smtp_encryption')->label('Verschlüsselung')->options(self::ENCRYPTION)->default('ssl')->required(),
                TextInput::make('smtp_username')->label('Benutzername')->required(),
                TextInput::make('smtp_password')->label('Passwort')->password()->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->helperText(fn (string $operation) => $operation === 'edit' ? 'Leer lassen, um das gespeicherte Passwort zu behalten.' : null),
            ]),
        ]);
    }
}
