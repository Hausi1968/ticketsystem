<?php

namespace App\Filament\Resources\Teams\Schemas;

use App\Models\Team;
use App\Models\Tenant;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class TeamForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('tenant_id')->label('Mandant')
                ->options(fn () => Tenant::query()->accessible()->orderBy('name')->pluck('name', 'id'))
                ->required()
                ->disabledOn('edit'),
            TextInput::make('name')->label('Name')->required()->maxLength(255)
                ->placeholder('z. B. Auftragsbearbeitung'),
            Select::make('assignment_mode')->label('Zuweisung neuer Tickets')->required()->default(Team::MANUAL)
                ->options(Team::ASSIGNMENT_MODES)
                ->helperText('Automatisch: Neue Tickets dieses Teams ohne Bearbeiter gehen an die Mitglieder mit „Bekommt Zuweisungen“. Regeln mit eigener Zuweisung haben Vorrang.'),
            Toggle::make('notify_on_assignment')->label('Team benachrichtigen, wenn ihm ein Ticket zugeordnet wird')
                ->helperText('Alle Mitglieder bekommen einen Hinweis (Glocke, App und je nach Profil E-Mail), wenn jemand ein Ticket von Hand diesem Team zuordnet und es dabei niemandem zugewiesen wird.')
                ->columnSpanFull(),
        ]);
    }
}
