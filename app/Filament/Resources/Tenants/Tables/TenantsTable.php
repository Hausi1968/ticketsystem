<?php

namespace App\Filament\Resources\Tenants\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TenantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ColorColumn::make('color')->label(''),
                TextColumn::make('short_code')->label('Kürzel')->badge()->sortable(),
                TextColumn::make('name')->label('Name')->searchable()->sortable(),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'active' ? 'Aktiv' : 'Gesperrt')
                    ->color(fn (string $state) => $state === 'active' ? 'success' : 'danger'),
                TextColumn::make('memberships_count')->counts('memberships')->label('Mitarbeiter'),
                TextColumn::make('teams_count')->counts('teams')->label('Teams'),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()]);
    }
}
