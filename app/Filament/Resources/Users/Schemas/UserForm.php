<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\MembershipRole;
use App\Models\Tenant;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Zugang')->columns(2)->schema([
                TextInput::make('name')->label('Name')->required()->maxLength(255),
                TextInput::make('email')->label('E-Mail')->email()->required()->unique(ignoreRecord: true),
                TextInput::make('password')
                    ->label('Passwort')
                    ->password()
                    ->revealable()
                    ->minLength(12)
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->dehydrateStateUsing(fn (string $state) => Hash::make($state))
                    ->helperText('Mindestens 12 Zeichen. Beim Bearbeiten leer lassen, um es nicht zu ändern.'),
                Toggle::make('active')->label('Aktiv')->default(true)
                    ->helperText('Inaktive Mitarbeiter können sich nicht anmelden.'),
                Toggle::make('is_admin')->label('Administrator')
                    ->helperText('Sieht alle Mandanten und darf Mandanten und Mitarbeiter verwalten.'),
            ]),
            Section::make('Betreute Mandanten')
                ->description('Der Mitarbeiter sieht nur Tickets der Mandanten, die hier eingetragen sind.')
                ->schema([
                    Repeater::make('memberships')
                        ->hiddenLabel()
                        ->relationship()
                        ->columns(2)
                        ->defaultItems(0)
                        ->addActionLabel('Mandant hinzufügen')
                        ->schema([
                            Select::make('tenant_id')->label('Mandant')
                                ->options(fn () => Tenant::query()->orderBy('name')->pluck('name', 'id'))
                                ->required()
                                ->distinct()
                                ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                            Select::make('role')->label('Rolle')
                                ->options(MembershipRole::class)
                                ->default(MembershipRole::Agent->value)
                                ->required(),
                        ]),
                ]),
        ]);
    }
}
