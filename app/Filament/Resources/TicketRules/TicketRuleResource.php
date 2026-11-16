<?php

namespace App\Filament\Resources\TicketRules;

use App\Enums\MembershipRole;
use App\Enums\TicketPriority;
use App\Filament\Resources\TicketRules\Pages\ManageTicketRules;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\TicketCategory;
use App\Models\TicketRule;
use App\Models\User;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class TicketRuleResource extends Resource
{
    protected static ?string $model = TicketRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFunnel;

    protected static string|UnitEnum|null $navigationGroup = 'Verwaltung';

    protected static ?string $slug = 'regeln';

    protected static ?int $navigationSort = 7;

    protected static ?string $modelLabel = 'Regel';

    protected static ?string $pluralModelLabel = 'Regeln';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Regel')->columns(2)->schema([
                TextInput::make('name')->label('Name')->required()->maxLength(120)->placeholder('z. B. Bestellungen an Auftragsbearbeitung'),
                Select::make('tenant_id')->label('Mandant')->placeholder('Alle Mandanten')->live()
                    ->options(fn () => Tenant::query()->orderBy('name')->pluck('name', 'id'))
                    ->helperText('Team und feste Bearbeiter gibt es nur bei einem bestimmten Mandanten.'),
            ]),

            Section::make('Wenn ein neues Ticket …')->schema([
                Radio::make('match')->hiddenLabel()->inline()->default('all')->required()
                    ->options(['all' => 'alle Bedingungen erfüllt', 'any' => 'mindestens eine Bedingung erfüllt']),
                Repeater::make('conditions')->hiddenLabel()->required()->minItems(1)->columns(3)
                    ->addActionLabel('Bedingung hinzufügen')->defaultItems(1)
                    ->schema([
                        Select::make('field')->hiddenLabel()->options(TicketRule::FIELDS)->default('subject')->required(),
                        Select::make('operator')->hiddenLabel()->options(TicketRule::OPERATORS)->default('contains')->required(),
                        TextInput::make('value')->hiddenLabel()->required()->placeholder('z. B. Bestellung'),
                    ]),
            ]),

            Section::make('… dann')->columns(2)->schema([
                Select::make('team_id')->label('Team setzen')->placeholder('– unverändert –')
                    ->options(fn (Get $get) => $get('tenant_id')
                        ? Team::query()->withoutGlobalScopes()->where('tenant_id', $get('tenant_id'))->orderBy('name')->pluck('name', 'id')
                        : [])
                    ->disabled(fn (Get $get) => ! $get('tenant_id')),
                Select::make('assign')->label('Zuweisen')->placeholder('– niemandem –')->live()
                    ->options(fn (Get $get) => $get('tenant_id')
                        ? ['user' => 'an einen bestimmten Mitarbeiter', 'round_robin' => 'reihum im Team (wer am wenigsten offen hat)']
                        : ['round_robin' => 'reihum im Team (wer am wenigsten offen hat)'])
                    ->helperText('Reihum berücksichtigt alle Teammitglieder mit „bekommt Zuweisungen“.'),
                Select::make('assignee_id')->label('Mitarbeiter')->searchable()
                    ->visible(fn (Get $get) => $get('assign') === 'user')->required(fn (Get $get) => $get('assign') === 'user')
                    ->options(fn (Get $get) => User::query()->where('active', true)
                        ->where(fn ($query) => $query->where('is_admin', true)
                            ->orWhereHas('memberships', fn ($m) => $m->where('tenant_id', $get('tenant_id'))
                                ->where('role', '!=', MembershipRole::LightAgent->value)))
                        ->orderBy('name')->pluck('name', 'id')),
                Select::make('category_id')->label('Kategorie setzen')->placeholder('– unverändert –')
                    ->options(fn (Get $get) => TicketCategory::query()->where('active', true)
                        ->when($get('tenant_id'), fn ($query, $id) => $query->forTenant((int) $id), fn ($query) => $query->whereNull('tenant_id'))
                        ->ordered()->pluck('name', 'id')),
                Select::make('priority')->label('Priorität setzen')->placeholder('– unverändert –')->options(TicketPriority::class),
            ]),

            Section::make()->columns(3)->schema([
                Toggle::make('stop_processing')->label('Danach keine weiteren Regeln prüfen')->default(true),
                TextInput::make('sort')->label('Reihenfolge')->numeric()->default(100),
                Toggle::make('active')->label('Aktiv')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('tenant', 'team', 'assignee', 'category'))
            ->defaultSort('sort')
            ->reorderable('sort')
            ->description('Regeln wirken auf neue Tickets aus E-Mails und aus der App, von oben nach unten.')
            ->columns([
                TextColumn::make('name')->label('Regel')->searchable()
                    ->description(fn (TicketRule $record) => self::describe($record)),
                TextColumn::make('tenant.name')->label('Mandant')->placeholder('Alle Mandanten'),
                IconColumn::make('active')->label('Aktiv')->boolean(),
            ])
            ->filters([
                SelectFilter::make('tenant_id')->label('Mandant')
                    ->options(fn () => Tenant::query()->orderBy('name')->pluck('name', 'id')),
            ])
            ->recordActions([EditAction::make()->modalWidth('4xl'), DeleteAction::make()]);
    }

    private static function describe(TicketRule $rule): string
    {
        $conditions = collect($rule->conditions ?? [])->map(fn (array $c) => (TicketRule::FIELDS[$c['field'] ?? ''] ?? '?').' '
            .(TicketRule::OPERATORS[$c['operator'] ?? ''] ?? '?').' „'.($c['value'] ?? '').'“')
            ->implode($rule->match === 'any' ? ' oder ' : ' und ');

        $actions = array_filter([
            $rule->team ? 'Team '.$rule->team->name : null,
            $rule->assign === 'user' ? 'an '.$rule->assignee?->name : null,
            $rule->assign === 'round_robin' ? 'reihum zuweisen' : null,
            $rule->category ? 'Kategorie '.$rule->category->name : null,
            $rule->priority ? 'Priorität '.$rule->priority->getLabel() : null,
        ]);

        return 'Wenn '.$conditions.' → '.($actions ? implode(', ', $actions) : 'nichts');
    }

    public static function getPages(): array
    {
        return ['index' => ManageTicketRules::route('/')];
    }
}
