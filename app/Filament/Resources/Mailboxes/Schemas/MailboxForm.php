<?php

namespace App\Filament\Resources\Mailboxes\Schemas;

use App\Mail\Outbound\AcknowledgementSender;
use App\Models\Mailbox;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\TicketCategory;
use Filament\Forms\Components\DatePicker;
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
                Select::make('usage')->label('Verwendung')->options(Mailbox::USAGES)->default('both')->required()->live()
                    ->columnSpanFull()
                    ->helperText('Welches Postfach Antworten verschickt, legst du je Mandant fest (Verwaltung → Mandanten → „Antworten verschicken über“).'),
                Select::make('default_category_id')->label('Kategorie für neue Tickets')->placeholder('– keine –')
                    ->helperText('z. B. „Allgemeine Anfrage“, um normale E-Mails von Fehlermeldungen zu trennen.')
                    ->options(fn (Get $get) => $get('tenant_id')
                        ? TicketCategory::query()->where('active', true)->forTenant((int) $get('tenant_id'))->ordered()->pluck('name', 'id')
                        : [])
                    ->visible(fn (Get $get) => $get('usage') !== 'send'),
                TextInput::make('from_name')->label('Absendername')->maxLength(255)
                    ->helperText('Leer = Name des Mandanten'),
                TextInput::make('poll_interval_minutes')->label('Abruf alle … Minuten')->numeric()
                    ->minValue(1)->maxValue(1440)->default(5)->required()
                    ->visible(fn (Get $get) => $get('usage') !== 'send'),
                Toggle::make('active')->label('Aktiv')->default(true),
            ]),

            Section::make('Eingangsbestätigung')
                ->visible(fn (Get $get) => $get('usage') !== 'send')
                ->description('Automatische Antwort an den Kunden, sobald aus seiner E-Mail oder App-Meldung ein neues Ticket entsteht.')
                ->schema([
                    Toggle::make('send_acknowledgement')->label('Eingangsbestätigung senden')->default(true)->live()
                        ->helperText('Nicht an noreply-Adressen, Newsletter, eigene Postfächer und höchstens einmal in 15 Minuten je Kunde.'),
                    Textarea::make('acknowledgement_text')->label('Text')->rows(10)
                        ->placeholder(AcknowledgementSender::DEFAULT_TEXT)
                        ->helperText('Leer = Standardtext (siehe Platzhalter). Platzhalter: {name}, {nummer}, {betreff}, {mandant}, {absender}')
                        ->visible(fn (Get $get) => (bool) $get('send_acknowledgement')),
                ]),

            Section::make('Spam und Sperrliste')
                ->visible(fn (Get $get) => $get('usage') !== 'send')
                ->description('Aus diesen E-Mails entsteht kein Ticket. Abwesenheitsnotizen und Unzustellbarkeitsmeldungen werden immer ignoriert.')
                ->collapsible()
                ->schema([
                    Textarea::make('blocked_senders')->label('Gesperrte Absender')->rows(5)
                        ->placeholder("werbung@shop.de\n@spam-domain.de")
                        ->helperText('Eine Adresse oder Domain je Zeile. „@firma.de“ sperrt alle Absender dieser Domain (auch Subdomains). Im Ticket gibt es dafür auch „Absender sperren“.'),
                    Toggle::make('ignore_newsletters')->label('Newsletter und Massen-Mails ignorieren')
                        ->helperText('Erkennt E-Mails mit Abmelde-Link (List-Unsubscribe) oder „Precedence: bulk“. Achtung: Manche Webshops verschicken Bestellungen genauso.'),
                ]),

            Section::make('Eingang (IMAP)')->columns(3)
                ->description(fn (Get $get) => $get('usage') === 'send'
                    ? 'Nur nötig, wenn Kopien der Antworten in einen Gesendet-Ordner sollen.' : null)
                ->schema([
                    TextInput::make('imap_host')->label('Server')->placeholder('imap.ionos.de')
                        ->required(fn (Get $get) => self::needsImap($get)),
                    TextInput::make('imap_port')->label('Port')->numeric()->default(993)->required(),
                    Select::make('imap_encryption')->label('Verschlüsselung')->options(self::ENCRYPTION)->default('ssl')->required(),
                    TextInput::make('imap_username')->label('Benutzername')->required(fn (Get $get) => self::needsImap($get)),
                    TextInput::make('imap_password')->label('Passwort')->password()->revealable()
                        ->required(fn (string $operation, Get $get) => $operation === 'create' && self::needsImap($get))
                        ->dehydrated(fn (?string $state) => filled($state))
                        ->helperText(fn (string $operation) => $operation === 'edit' ? 'Leer lassen, um das gespeicherte Passwort zu behalten.' : null),
                    TextInput::make('imap_folder')->label('Ordner')->default('INBOX')->required()
                        ->visible(fn (Get $get) => $get('usage') !== 'send'),
                    TextInput::make('processed_folder')->label('Verarbeitete E-Mails verschieben nach')
                        ->placeholder('leer = nur als gelesen markieren')->columnSpan(2)
                        ->visible(fn (Get $get) => $get('usage') !== 'send'),
                    DatePicker::make('import_since')->label('Nur E-Mails ab')->native(false)->displayFormat('d.m.Y')
                        ->default(fn () => today())->columnSpanFull()
                        ->helperText('Ältere E-Mails im Postfach werden nicht zu Tickets und bleiben unverändert (z. B. der Altbestand beim Einrichten). Leer = alle ungelesenen E-Mails abholen.')
                        ->visible(fn (Get $get) => $get('usage') !== 'send'),
                    Select::make('mark_seen')->label('Im Postfach als gelesen markieren')
                        ->options(Mailbox::MARK_SEEN)->default('fetch')->required()->columnSpanFull()
                        ->helperText('„Erst wenn das Ticket gelöst oder geschlossen ist“: Die E-Mails bleiben im Postfach (z. B. in Outlook) ungelesen, auch wenn das Ticket schon zugewiesen ist. Wird das Ticket gelöst oder geschlossen, markiert Anliego sie als gelesen (und verschiebt sie, falls oben ein Ordner steht); wird es wieder geöffnet, wieder als ungelesen. Anliego holt dann alle neuen E-Mails ab, auch solche, die jemand in Outlook schon geöffnet hat.')
                        ->visible(fn (Get $get) => $get('usage') !== 'send'),
                ]),

            Section::make('Ausgang (SMTP)')->columns(3)
                ->visible(fn (Get $get) => $get('usage') !== 'fetch')
                ->schema([
                    TextInput::make('smtp_host')->label('Server')->required()->placeholder('smtp.ionos.de'),
                    TextInput::make('smtp_port')->label('Port')->numeric()->default(465)->required(),
                    Select::make('smtp_encryption')->label('Verschlüsselung')->options(self::ENCRYPTION)->default('ssl')->required(),
                    TextInput::make('smtp_username')->label('Benutzername')->required(),
                    TextInput::make('smtp_password')->label('Passwort')->password()->revealable()
                        ->required(fn (string $operation) => $operation === 'create')
                        ->dehydrated(fn (?string $state) => filled($state))
                        ->helperText(fn (string $operation) => $operation === 'edit' ? 'Leer lassen, um das gespeicherte Passwort zu behalten.' : null),
                ]),

            Section::make('Gesendete Antworten in Outlook')
                ->description('Antworten aus dem Ticketsystem landen sonst nicht im Gesendet-Ordner deines E-Mail-Programms.')
                ->visible(fn (Get $get) => $get('usage') !== 'fetch')
                ->columns(2)->schema([
                    TextInput::make('sent_folder')->label('Kopie ablegen im IMAP-Ordner')->live(onBlur: true)
                        ->placeholder('z. B. Gesendete Objekte oder Sent')
                        ->helperText('Legt jede Antwort über IMAP in diesen Ordner dieses Postfachs. Der Name steht in Outlook links in der Ordnerliste. Braucht die IMAP-Zugangsdaten oben.'),
                    TextInput::make('sent_copy_bcc')->label('Oder Kopie (BCC) an')->email()
                        ->placeholder('z. B. eigene-adresse@outlook.de')
                        ->helperText('Schickt jede Antwort zusätzlich unsichtbar an diese Adresse, z. B. wenn das Konto kein IMAP erlaubt.'),
                ]),
        ]);
    }

    /** IMAP-Zugang ist nötig zum Abrufen oder für Kopien in den Gesendet-Ordner. */
    private static function needsImap(Get $get): bool
    {
        return $get('usage') !== 'send' || filled($get('sent_folder'));
    }
}
