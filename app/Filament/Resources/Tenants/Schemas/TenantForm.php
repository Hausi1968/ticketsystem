<?php

namespace App\Filament\Resources\Tenants\Schemas;

use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
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
        ]);
    }
}
