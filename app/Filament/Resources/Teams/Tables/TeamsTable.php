<?php

namespace App\Filament\Resources\Teams\Tables;

use App\Models\Tenant;
use Filament\Actions\EditAction;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TeamsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('tenant'))
            ->columns([
                TextColumn::make('tenant.short_code')->label('Mandant')->badge()
                    ->color(fn ($record) => Color::hex($record->tenant->color)),
                TextColumn::make('name')->label('Name')->searchable()->sortable(),
                TextColumn::make('users_count')->counts('users')->label('Mitglieder'),
            ])
            ->filters([
                SelectFilter::make('tenant_id')->label('Mandant')
                    ->options(fn () => Tenant::query()->accessible()->pluck('name', 'id')),
            ])
            ->recordActions([EditAction::make()]);
    }
}
