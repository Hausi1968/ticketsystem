<?php

namespace App\Filament\Pages;

use App\Backup\DatabaseBackup;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Number;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use UnitEnum;

/** Nächtliche Datenbanksicherungen: Liste, Download, jetzt sichern (nur Administratoren) */
class Backups extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Verwaltung';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Datensicherung';

    protected static ?string $title = 'Datensicherung';

    protected static ?string $slug = 'datensicherung';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make('Jede Nacht um 2:30 Uhr sichert Anliego die Datenbank (alle Mandanten, Tickets, Einstellungen). Aufbewahrt werden die letzten '
                .config('anliego.backup_keep').' Sicherungen. Wiederherstellen: Datei entpacken und z. B. in phpMyAdmin importieren. '
                .'Anhänge liegen als Dateien im Ordner storage/app/private und werden über die Sicherung des Webspace bzw. per SFTP gesichert.'),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => collect(app(DatabaseBackup::class)->list())->keyBy('name')->all())
            ->columns([
                TextColumn::make('time')->label('Erstellt')
                    ->formatStateUsing(fn ($state) => now()->setTimestamp((int) $state)->timezone('Europe/Berlin')->format('d.m.Y H:i')),
                TextColumn::make('name')->label('Datei'),
                TextColumn::make('size')->label('Größe')->formatStateUsing(fn ($state) => Number::fileSize((int) $state, precision: 1)),
            ])
            ->recordActions([
                Action::make('download')->label('Herunterladen')->icon(Heroicon::OutlinedArrowDownTray)
                    ->action(function (array $record): ?BinaryFileResponse {
                        $path = app(DatabaseBackup::class)->path($record['name']);

                        return $path ? response()->download($path) : null;
                    }),
            ])
            ->headerActions([
                Action::make('now')->label('Jetzt sichern')->icon(Heroicon::OutlinedPlay)
                    ->action(function () {
                        try {
                            @set_time_limit(300);
                            $path = app(DatabaseBackup::class)->create();
                            app(DatabaseBackup::class)->prune((int) config('anliego.backup_keep', 14));
                            Notification::make()->success()->title('Gesichert: '.basename($path))->send();
                        } catch (Throwable $e) {
                            report($e);
                            Notification::make()->danger()->title('Sicherung fehlgeschlagen')->body($e->getMessage())->persistent()->send();
                        }
                    }),
            ])
            ->emptyStateHeading('Noch keine Sicherung')
            ->emptyStateDescription('Die erste entsteht heute Nacht, oder jetzt über „Jetzt sichern“.')
            ->paginated(false);
    }
}
