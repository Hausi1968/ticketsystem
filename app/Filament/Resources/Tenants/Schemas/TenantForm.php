<?php

namespace App\Filament\Resources\Tenants\Schemas;

use App\Console\Commands\RunAutomation;
use App\Models\Mailbox;
use App\Models\Tenant;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Mandant')->columns(2)->schema([
                TextInput::make('name')->label('Name')->required()->maxLength(255),
                TextInput::make('short_code')
                    ->label('Kürzel')
                    ->helperText('Präfix der Ticketnummern, z. B. ACME → ACME-1042')
                    ->required()
                    ->maxLength(10)
                    ->alphaNum()
                    ->unique(ignoreRecord: true)
                    ->disabledOn('edit'),
                ColorPicker::make('color')->label('Farbe in Listen')->default('#4f46e5')->required(),
                Select::make('status')->label('Status')->required()->default('active')->options([
                    'active' => 'Aktiv',
                    'suspended' => 'Gesperrt',
                ]),
                Select::make('timezone')->label('Zeitzone')->default('Europe/Berlin')->required()
                    ->options(fn () => array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                    ->searchable(),
                Select::make('locale')->label('Sprache')->default('de')->required()
                    ->options(['de' => 'Deutsch', 'en' => 'Englisch']),
            ]),

            Section::make('Automatisch erledigen')
                ->description('In Kalendertagen, gezählt ab der letzten Nachricht des Teams. 0 = aus.')
                ->columns(3)->schema([
                    TextInput::make('settings.reminder_days')->label('Kunden erinnern nach')
                        ->helperText('Bei „Wartet auf Kunde“ einmal eine Erinnerung per E-Mail schicken.')
                        ->numeric()->integer()->minValue(0)->maxValue(365)->default(0)->suffix('Tagen')->live(onBlur: true),
                    TextInput::make('settings.auto_solve_days')->label('Wartende Tickets lösen nach')
                        ->helperText('„Wartet auf Kunde“ ohne Antwort → „Gelöst“ (ab der Erinnerung gezählt, falls eine ging).')
                        ->numeric()->integer()->minValue(0)->maxValue(365)->default(0)->suffix('Tagen'),
                    TextInput::make('settings.auto_close_days')->label('Gelöste Tickets schließen nach')
                        ->helperText('„Gelöst“ → „Geschlossen“. Danach legt eine Kundenantwort je nach Status ein neues Ticket an.')
                        ->numeric()->integer()->minValue(0)->maxValue(365)->default(0)->suffix('Tagen'),
                    Textarea::make('settings.reminder_text')->label('Text der Erinnerung')->rows(8)->columnSpanFull()
                        ->placeholder(RunAutomation::DEFAULT_REMINDER)
                        ->helperText('Leer = Standardtext (siehe Platzhalter im Feld). Platzhalter: {name}, {nummer}, {betreff}, {tage}, {mandant}, {absender}.')
                        ->visible(fn (Get $get) => (int) $get('settings.reminder_days') > 0),
                ]),

            Section::make('Antworten verschicken über')
                ->description('Aus welchem Postfach Antworten, Eingangsbestätigungen und Erinnerungen an den Kunden gehen.')
                ->columns(2)->visibleOn('edit')->schema([
                    Select::make('settings.reply_mailbox_email')->label('Bei Tickets aus E-Mails')
                        ->placeholder('Postfach, in dem die E-Mail ankam')
                        ->options(fn (?Tenant $record) => self::mailboxOptions($record)),
                    Select::make('settings.reply_mailbox_api')->label('Bei Meldungen aus der App / Mitgliederverwaltung')
                        ->placeholder('Postfach des API-Schlüssels')
                        ->options(fn (?Tenant $record) => self::mailboxOptions($record)),
                ]),
        ]);
    }

    /** @return array<int, string> */
    private static function mailboxOptions(?Tenant $record): array
    {
        return $record ? Mailbox::query()->withoutGlobalScopes()->where('tenant_id', $record->getKey())
            ->where('active', true)->where('usage', '!=', 'fetch')->orderBy('email')->pluck('email', 'id')->all() : [];
    }
}
