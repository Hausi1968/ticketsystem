<?php

namespace App\Filament\Pages;

use App\Models\Tenant;
use App\Reports\MonthlyReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/** Monatsbericht je Mandant mit CSV-Export (nur Administratoren) */
class Reports extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Verwaltung';

    protected static ?int $navigationSort = 19;

    protected static ?string $navigationLabel = 'Berichte';

    protected static ?string $slug = 'berichte';

    #[Url(as: 'monat')]
    public string $month = '';

    #[Url(as: 'mandant')]
    public ?int $tenant = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public function mount(): void
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = now()->format('Y-m');
        }
    }

    public function getTitle(): string
    {
        [$from] = MonthlyReport::period($this->month);

        return 'Bericht '.$from->locale('de')->translatedFormat('F Y');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('previous')->label('Vormonat')->icon(Heroicon::OutlinedChevronLeft)->color('gray')
                ->action(fn () => $this->shift(-1)),
            Action::make('next')->label('Nächster Monat')->icon(Heroicon::OutlinedChevronRight)->color('gray')
                ->visible(fn () => $this->month < now()->format('Y-m'))
                ->action(fn () => $this->shift(1)),
            Action::make('filter')->label(fn () => $this->tenant ? Tenant::query()->find($this->tenant)?->name ?? 'Mandant' : 'Alle Mandanten')
                ->icon(Heroicon::OutlinedFunnel)->color('gray')
                ->schema([
                    Select::make('tenant')->label('Mandant')->placeholder('Alle Mandanten')
                        ->options(fn () => Tenant::query()->orderBy('name')->pluck('name', 'id'))
                        ->default(fn () => $this->tenant),
                ])
                ->action(function (array $data) {
                    $this->tenant = $data['tenant'] ? (int) $data['tenant'] : null;
                    $this->resetTable();
                }),
            Action::make('timeCsv')->label('Zeiten als CSV')->icon(Heroicon::OutlinedClock)->color('gray')
                ->action(function (): StreamedResponse {
                    $csv = app(MonthlyReport::class)->timeCsv($this->month, $this->tenant);

                    return response()->streamDownload(fn () => print ($csv), "anliego-zeiten-{$this->month}.csv",
                        ['Content-Type' => 'text/csv; charset=UTF-8']);
                }),
            Action::make('csv')->label('CSV herunterladen')->icon(Heroicon::OutlinedArrowDownTray)
                ->action(function (): StreamedResponse {
                    $report = app(MonthlyReport::class);
                    $csv = $report->csv($report->rows($this->month, $this->tenant));

                    return response()->streamDownload(fn () => print ($csv), "anliego-bericht-{$this->month}.csv",
                        ['Content-Type' => 'text/csv; charset=UTF-8']);
                }),
        ];
    }

    private function shift(int $months): void
    {
        [$from] = MonthlyReport::period($this->month);
        $this->month = $from->addMonthsNoOverflow($months)->format('Y-m');
        $this->resetTable();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make('Neue Tickets und Fristen zählen nach Eingangsdatum, „Erledigt“ und Lösungszeit nach Abschlussdatum im Monat. '
                .'Zeiten sind Kalenderstunden. Zusammengeführte Tickets zählen nicht doppelt.'),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        $columns = [];
        foreach (MonthlyReport::COLUMNS as $key => $label) {
            $column = TextColumn::make($key)->label($label)->placeholder('–');
            if (str_starts_with($key, 'avg_') || $key === 'time_h') {
                $column->numeric(decimalPlaces: 1, locale: 'de');
            }
            if ($key === 'tenant') {
                $column->weight(fn (array $record) => $record['tenant'] === 'Gesamt' ? 'bold' : null);
            }
            $columns[] = $column;
        }

        return $table
            ->records(fn () => collect(app(MonthlyReport::class)->rows($this->month, $this->tenant))
                ->keyBy(fn ($row, $i) => (string) $i)->all())
            ->columns($columns)
            ->paginated(false)
            ->emptyStateHeading('Keine Mandanten');
    }
}
