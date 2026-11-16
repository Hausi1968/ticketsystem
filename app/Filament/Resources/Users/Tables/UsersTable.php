<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('tenants'))
            ->columns([
                TextColumn::make('name')->label('Name')->searchable()->sortable(),
                TextColumn::make('email')->label('E-Mail')->searchable(),
                TextColumn::make('tenants.short_code')->label('Mandanten')->badge(),
                TextColumn::make('teams.name')->label('Teams')->badge()->color('gray')->placeholder('–')->toggleable(),
                IconColumn::make('is_admin')->label('Admin')->boolean(),
                IconColumn::make('active')->label('Aktiv')->boolean(),
                IconColumn::make('app_authentication_secret')->label('2FA')
                    ->getStateUsing(fn ($record) => filled($record->app_authentication_secret))->boolean(),
                TextColumn::make('last_login_at')->label('Letzte Anmeldung')->dateTime('d.m.Y H:i')->placeholder('–'),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()]);
    }
}
