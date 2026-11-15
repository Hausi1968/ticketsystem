<?php

namespace App\Filament\Resources\SlaPolicies;

use App\Enums\TicketPriority;
use App\Filament\Resources\SlaPolicies\Pages\CreateSlaPolicy;
use App\Filament\Resources\SlaPolicies\Pages\EditSlaPolicy;
use App\Filament\Resources\SlaPolicies\Pages\ListSlaPolicies;
use App\Models\SlaPolicy;
use App\Models\Team;
use App\Models\Tenant;
use App\Sla\GermanHolidays;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class SlaPolicyResource extends Resource
{
    protected static ?string $model = SlaPolicy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Verwaltung';

    protected static ?string $slug = 'sla';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Fristen (SLA)';

    protected static ?string $modelLabel = 'SLA-Richtlinie';

    protected static ?string $pluralModelLabel = 'SLA-Richtlinien';

    private const DAYS = [1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag'];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Gilt für')->columns(2)->schema([
                Select::make('tenant_id')->label('Mandant')
                    ->options(fn () => Tenant::query()->accessible()->orderBy('name')->pluck('name', 'id'))
                    ->required()->live()->disabledOn('edit'),
                TextInput::make('name')->label('Name')->required()->maxLength(255)->placeholder('z. B. Standard'),
                Select::make('team_id')->label('Nur für Team')->placeholder('alle Teams')
                    ->options(fn (Get $get) => $get('tenant_id')
                        ? Team::query()->where('tenant_id', $get('tenant_id'))->orderBy('name')->pluck('name', 'id')
                        : []),
                Select::make('channel')->label('Nur für Eingang')->placeholder('alle Eingänge')->options(SlaPolicy::CHANNELS),
                Toggle::make('active')->label('Aktiv')->default(true)
                    ->helperText('Änderungen gelten für neue Tickets. Laufende Fristen bleiben, bis sich Status oder Priorität ändern.'),
            ]),

            Section::make('Ziele')
                ->description('In Stunden Geschäftszeit, z. B. 0,5 für 30 Minuten. Bei 8–17 Uhr entspricht ein Arbeitstag 9 Stunden. Leer = wird nicht gemessen.')
                ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
                ->schema(collect(TicketPriority::cases())->reverse()->map(fn (TicketPriority $p) => Section::make($p->getLabel())
                    ->compact()
                    ->schema(collect(SlaPolicy::METRICS)->map(fn (string $label, string $metric) => TextInput::make("targets.{$p->value}.{$metric}")
                        ->label($label)->numeric()->minValue(0)->step(0.25)->suffix('Std.')
                        ->default(isset(SlaPolicy::DEFAULT_TARGETS[$p->value][$metric]) ? SlaPolicy::DEFAULT_TARGETS[$p->value][$metric] / 60 : null)
                    )->values()->all()))->values()->all()),

            Section::make('Geschäftszeiten')->schema([
                Toggle::make('all_day')->label('Rund um die Uhr zählen (auch nachts, am Wochenende und an Feiertagen)')->live(),
                Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])
                    ->hidden(fn (Get $get) => (bool) $get('all_day'))
                    ->schema(collect(self::DAYS)->map(fn (string $day, int $n) => Section::make($day)->compact()->columns(2)->schema([
                        Toggle::make("business_hours.{$n}.open")->label('geöffnet')->live()->columnSpanFull()
                            ->default(SlaPolicy::DEFAULT_HOURS[$n]['open']),
                        Select::make("business_hours.{$n}.from")->label('von')->options(self::times())->selectablePlaceholder(false)
                            ->default(SlaPolicy::DEFAULT_HOURS[$n]['from'])
                            ->visible(fn (Get $get) => (bool) $get("business_hours.{$n}.open"))
                            ->required(fn (Get $get) => (bool) $get("business_hours.{$n}.open")),
                        Select::make("business_hours.{$n}.to")->label('bis')->options(self::times())->selectablePlaceholder(false)
                            ->default(SlaPolicy::DEFAULT_HOURS[$n]['to'])
                            ->visible(fn (Get $get) => (bool) $get("business_hours.{$n}.open"))
                            ->required(fn (Get $get) => (bool) $get("business_hours.{$n}.open"))
                            ->rule(fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get, $n) {
                                if ((string) $value <= (string) $get("business_hours.{$n}.from")) {
                                    $fail('Muss nach dem Beginn liegen.');
                                }
                            }),
                    ]))->values()->all()),
                Grid::make(2)->hidden(fn (Get $get) => (bool) $get('all_day'))->schema([
                    Select::make('holiday_region')->label('Gesetzliche Feiertage')->placeholder('keine')
                        ->options(GermanHolidays::REGIONS)->default('NW')
                        ->helperText('An Feiertagen laufen die Fristen nicht.'),
                    TagsInput::make('extra_holidays')->label('Weitere freie Tage')
                        ->placeholder('z. B. 24.12. oder 02.01.2027')
                        ->nestedRecursiveRules(['regex:/^\s*\d{1,2}\.\d{1,2}\.(\d{4})?\s*$/'])
                        ->helperText('Ohne Jahr = jedes Jahr (z. B. 24.12. und 31.12.), mit Jahr = einmalig.'),
                ]),
            ]),

            Section::make('Erinnerung')->schema([
                TextInput::make('warn_percent')->label('Warnen, wenn so viel der Zeit verbraucht ist')
                    ->numeric()->minValue(10)->maxValue(95)->default(75)->suffix('%')->required()
                    ->helperText('Die Warnung geht an den Bearbeiter (ohne Bearbeiter an das Team). Ist die Frist überschritten, erfahren es zusätzlich die Supervisoren des Mandanten.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['tenant', 'team']))
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('tenant.short_code')->label('Mandant')->badge()
                    ->color(fn (SlaPolicy $record) => Color::hex($record->tenant->color)),
                TextColumn::make('name')->label('Name')->searchable()
                    ->description(fn (SlaPolicy $record) => collect([
                        $record->team ? 'Team '.$record->team->name : null,
                        $record->channel ? SlaPolicy::CHANNELS[$record->channel] ?? $record->channel : null,
                    ])->filter()->join(' · ') ?: 'alle Tickets'),
                TextColumn::make('targets')->label('Erstreaktion / Lösung (Normal)')
                    ->state(fn (SlaPolicy $record) => self::hours($record->target('normal', 'first_response'))
                        .' / '.self::hours($record->target('normal', 'resolution'))),
                TextColumn::make('all_day')->label('Zeiten')
                    ->state(fn (SlaPolicy $record) => $record->all_day ? 'rund um die Uhr' : 'Geschäftszeiten'
                        .($record->holiday_region ? ', Feiertage '.$record->holiday_region : '')),
                IconColumn::make('active')->label('Aktiv')->boolean(),
            ])
            ->filters([
                SelectFilter::make('tenant_id')->label('Mandant')
                    ->options(fn () => Tenant::query()->accessible()->pluck('name', 'id')),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSlaPolicies::route('/'),
            'create' => CreateSlaPolicy::route('/create'),
            'edit' => EditSlaPolicy::route('/{record}/edit'),
        ];
    }

    // ---------------------------------------------------------------- Umrechnung Formular ↔ Speicher

    /** Minuten → Stunden fürs Formular */
    public static function toForm(array $data): array
    {
        foreach ($data['targets'] ?? [] as $priority => $metrics) {
            foreach ((array) $metrics as $metric => $minutes) {
                $data['targets'][$priority][$metric] = is_numeric($minutes) ? round($minutes / 60, 2) : null;
            }
        }

        $data['business_hours'] = ($data['business_hours'] ?? null) ?: SlaPolicy::DEFAULT_HOURS;

        return $data;
    }

    /** Stunden → Minuten; leere Felder fallen weg */
    public static function toStorage(array $data): array
    {
        $targets = [];
        foreach (TicketPriority::cases() as $p) {
            foreach (array_keys(SlaPolicy::METRICS) as $metric) {
                $hours = $data['targets'][$p->value][$metric] ?? null;
                $hours = is_string($hours) ? str_replace(',', '.', trim($hours)) : $hours;
                if (is_numeric($hours) && $hours > 0) {
                    $targets[$p->value][$metric] = max(1, (int) round($hours * 60));
                }
            }
        }
        $data['targets'] = $targets;

        $hours = [];
        foreach (array_keys(self::DAYS) as $n) {
            $day = $data['business_hours'][$n] ?? [];
            $hours[$n] = [
                'open' => (bool) ($day['open'] ?? false),
                'from' => isset($day['from']) ? substr((string) $day['from'], 0, 5) : SlaPolicy::DEFAULT_HOURS[$n]['from'],
                'to' => isset($day['to']) ? substr((string) $day['to'], 0, 5) : SlaPolicy::DEFAULT_HOURS[$n]['to'],
            ];
        }
        $data['business_hours'] = $hours;
        $data['extra_holidays'] = array_values(array_map('trim', $data['extra_holidays'] ?? [])) ?: null;

        return $data;
    }

    /** Uhrzeiten im Halbstundentakt */
    public static function times(): array
    {
        $times = [];
        for ($m = 0; $m <= 24 * 60; $m += 30) {
            $t = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
            $times[$t] = $t;
        }

        return $times;
    }

    public static function hours(?int $minutes): string
    {
        if ($minutes === null) {
            return '–';
        }

        return $minutes < 60 ? "{$minutes} Min." : str_replace('.', ',', (string) round($minutes / 60, 2)).' Std.';
    }
}
