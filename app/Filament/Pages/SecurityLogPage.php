<?php

namespace App\Filament\Pages;

use App\Models\SecurityEvent;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Sicherheitsprotokoll: Anmeldungen, App-Geräte, Änderungen an Mitarbeitern (nur Administratoren) */
class SecurityLogPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Verwaltung';

    protected static ?int $navigationSort = 21;

    protected static ?string $navigationLabel = 'Sicherheitsprotokoll';

    protected static ?string $title = 'Sicherheitsprotokoll';

    protected static ?string $slug = 'sicherheitsprotokoll';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make('Anmeldungen an Weboberfläche und App, fehlgeschlagene Versuche, an- und abgemeldete App-Geräte sowie Änderungen an '
                .'Mitarbeitern, Rollen und Passwörtern. Einträge werden '.config('anliego.security_log_days').' Tage aufbewahrt.'),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(SecurityEvent::query()->with(['user', 'actor']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Zeit')->dateTime('d.m.Y H:i:s', 'Europe/Berlin')->sortable(),
                TextColumn::make('type')->label('Ereignis')->badge()
                    ->formatStateUsing(fn (SecurityEvent $record) => $record->label())
                    ->color(fn (string $state) => in_array($state, SecurityEvent::FAILURES, true) ? 'danger'
                        : (in_array($state, ['login', 'app_login', 'logout', 'app_logout'], true) ? 'gray' : 'warning')),
                TextColumn::make('user.name')->label('Betrifft')->placeholder('–')
                    ->description(fn (SecurityEvent $record) => $record->user ? null : $record->email)
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $q) => $q
                        ->where('email', 'like', "%{$search}%")
                        ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', "%{$search}%")))),
                TextColumn::make('actor.name')->label('Ausgelöst von')->placeholder('–')->toggleable(),
                TextColumn::make('data')->label('Einzelheiten')->wrap()
                    ->state(fn (SecurityEvent $record) => collect($record->data ?? [])
                        ->map(fn ($value, $key) => $key.': '.(is_bool($value) ? ($value ? 'ja' : 'nein') : (is_scalar($value) ? $value : json_encode($value))))
                        ->implode(' · ') ?: null)
                    ->placeholder('–'),
                TextColumn::make('ip')->label('IP-Adresse')->placeholder('–')->searchable()->toggleable(),
                TextColumn::make('user_agent')->label('Browser / Gerät')->limit(40)->placeholder('–')
                    ->tooltip(fn (SecurityEvent $record) => $record->user_agent)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')->label('Ereignis')->options(SecurityEvent::TYPES)->multiple(),
                TernaryFilter::make('failures')->label('Nur Fehlversuche')
                    ->queries(
                        true: fn (Builder $query) => $query->whereIn('type', SecurityEvent::FAILURES),
                        false: fn (Builder $query) => $query->whereNotIn('type', SecurityEvent::FAILURES),
                    ),
            ])
            ->paginated([25, 50, 100]);
    }
}
