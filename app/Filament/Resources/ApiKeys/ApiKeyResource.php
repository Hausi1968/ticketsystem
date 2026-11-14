<?php

namespace App\Filament\Resources\ApiKeys;

use App\Filament\Resources\ApiKeys\Pages\ManageApiKeys;
use App\Models\ApiKey;
use App\Models\Mailbox;
use App\Models\Team;
use App\Models\Tenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ApiKeyResource extends Resource
{
    protected static ?string $model = ApiKey::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Verwaltung';

    protected static ?string $slug = 'api-schluessel';

    protected static ?int $navigationSort = 7;

    protected static ?string $modelLabel = 'API-Schlüssel';

    protected static ?string $pluralModelLabel = 'API-Schlüssel';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('tenant', 'mailbox', 'team'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('tenant.short_code')->label('Mandant')->badge()
                    ->color(fn (ApiKey $record) => Color::hex($record->tenant->color)),
                TextColumn::make('name')->label('Bezeichnung')
                    ->description(fn (ApiKey $record) => $record->prefix.'…'),
                TextColumn::make('mailbox.email')->label('Antworten über')->placeholder('erstes Postfach'),
                TextColumn::make('team.name')->label('Team')->placeholder('–'),
                TextColumn::make('last_used_at')->label('Zuletzt benutzt')->since()->placeholder('noch nie'),
                TextColumn::make('revoked_at')->label('Status')->badge()
                    ->state(fn (ApiKey $record) => $record->isActive() ? 'aktiv' : 'widerrufen')
                    ->color(fn (ApiKey $record) => $record->isActive() ? 'success' : 'danger'),
            ])
            ->recordActions([
                Action::make('revoke')->label('Widerrufen')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                    ->visible(fn (ApiKey $record) => $record->isActive())
                    ->requiresConfirmation()
                    ->modalDescription('Anwendungen mit diesem Schlüssel können danach keine Tickets mehr anlegen. Das lässt sich nicht rückgängig machen.')
                    ->action(fn (ApiKey $record) => $record->revoke()),
            ]);
    }

    /** Auswahl für das Anlegen-Formular */
    public static function mailboxOptions(?int $tenantId): array
    {
        return $tenantId ? Mailbox::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('email', 'id')->all() : [];
    }

    public static function teamOptions(?int $tenantId): array
    {
        return $tenantId ? Team::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('name', 'id')->all() : [];
    }

    public static function tenantOptions(): array
    {
        return Tenant::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function getPages(): array
    {
        return ['index' => ManageApiKeys::route('/')];
    }
}
