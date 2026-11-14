<?php

namespace App\Filament\Resources\TicketCategories;

use App\Filament\Resources\TicketCategories\Pages\ManageTicketCategories;
use App\Models\Tenant;
use App\Models\TicketCategory;
use App\Support\BadgeColor;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class TicketCategoryResource extends Resource
{
    protected static ?string $model = TicketCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Verwaltung';

    protected static ?string $slug = 'kategorien';

    protected static ?int $navigationSort = 6;

    protected static ?string $modelLabel = 'Kategorie';

    protected static ?string $pluralModelLabel = 'Kategorien';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Name')->required()->maxLength(100)->placeholder('z. B. Bestellung, Reklamation, App-Fehler')
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $get('tenant_id')
                    ? $rule->where('tenant_id', $get('tenant_id'))
                    : $rule->whereNull('tenant_id')),
            Select::make('tenant_id')->label('Mandant')->placeholder('Alle Mandanten')
                ->options(fn () => Tenant::query()->orderBy('name')->pluck('name', 'id'))
                ->helperText('Leer lassen, wenn die Kategorie für alle Mandanten gilt.'),
            Select::make('color')->label('Farbe')->options(BadgeColor::OPTIONS)->default('gray')->required(),
            TextInput::make('sort')->label('Reihenfolge')->numeric()->default(100),
            Toggle::make('active')->label('Aktiv (zur Auswahl angeboten)')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('tenant'))
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('name')->label('Kategorie')->badge()->searchable()
                    ->color(fn (TicketCategory $record) => BadgeColor::resolve($record->color)),
                TextColumn::make('tenant.name')->label('Mandant')->placeholder('Alle Mandanten'),
                IconColumn::make('active')->label('Aktiv')->boolean(),
                TextColumn::make('tickets_count')->counts('tickets')->label('Tickets'),
            ])
            ->filters([
                SelectFilter::make('tenant_id')->label('Mandant')
                    ->options(fn () => Tenant::query()->orderBy('name')->pluck('name', 'id')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->modalDescription('Tickets dieser Kategorie behalten ihren Inhalt, verlieren aber die Kategorie.'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTicketCategories::route('/')];
    }
}
