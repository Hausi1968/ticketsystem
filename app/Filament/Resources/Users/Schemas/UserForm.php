<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\MembershipRole;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use Filament\Forms\Components\CheckboxList;
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
            Section::make('Teams')
                ->description('Ein Mitarbeiter kann mehreren Teams angehören, auch bei verschiedenen Mandanten. Es zählen nur Teams der oben eingetragenen Mandanten.')
                ->schema([
                    CheckboxList::make('team_ids')->hiddenLabel()
                        ->options(fn () => Team::query()->with('tenant')->orderBy('name')->get()
                            ->mapWithKeys(fn (Team $t) => [$t->id => "{$t->name} ({$t->tenant->short_code})"]))
                        ->columns(2)
                        ->afterStateHydrated(fn (CheckboxList $component, ?User $record) => $component->state(
                            $record ? $record->teams()->pluck('teams.id')->map(fn ($id) => (string) $id)->all() : []))
                        ->dehydrated(false)
                        ->saveRelationshipsUsing(fn (User $record, ?array $state) => self::syncTeams($record, $state ?? [])),
                ]),
        ]);
    }

    /** Teams speichern; Zuweisungs-Einstellung bestehender Mitgliedschaften bleibt erhalten */
    public static function syncTeams(User $user, array $teamIds): void
    {
        $tenantIds = $user->memberships()->pluck('tenant_id')->map(fn ($id) => (int) $id)->all();
        $teams = Team::query()->withoutGlobalScopes()->whereIn('id', $teamIds)->whereIn('tenant_id', $tenantIds)->get();
        $existing = $user->teams()->withoutGlobalScopes()->get()->keyBy('id');

        $user->teams()->sync($teams->mapWithKeys(fn (Team $t) => [$t->getKey() => [
            'tenant_id' => $t->tenant_id,
            'receives_assignments' => (bool) ($existing->get($t->getKey())?->pivot->receives_assignments ?? true),
        ]])->all());
    }
}
