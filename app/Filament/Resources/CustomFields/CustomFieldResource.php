<?php

namespace App\Filament\Resources\CustomFields;

use App\Filament\Resources\CustomFields\Pages\ManageCustomFields;
use App\Models\CustomField;
use App\Models\Tenant;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class CustomFieldResource extends Resource
{
    protected static ?string $model = CustomField::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|UnitEnum|null $navigationGroup = 'Verwaltung';

    protected static ?string $slug = 'felder';

    protected static ?int $navigationSort = 7;

    protected static ?string $modelLabel = 'Eigenes Feld';

    protected static ?string $pluralModelLabel = 'Eigene Felder';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('label')->label('Bezeichnung')->required()->maxLength(80)->placeholder('z. B. Bestellnummer')
                ->live(onBlur: true)
                ->afterStateUpdated(function (?string $state, Get $get, Set $set, string $operation) {
                    if ($operation === 'create' && blank($get('key'))) {
                        $set('key', Str::of((string) $state)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(40, '')->toString());
                    }
                }),
            TextInput::make('key')->label('Kennung')->required()->maxLength(40)->regex('/^[a-z][a-z0-9_]*$/')
                ->disabledOn('edit')->dehydrated()
                ->helperText('Für die API (z. B. "bestellnummer"). Nur Kleinbuchstaben, Ziffern und _. Später nicht mehr änderbar.')
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $get('tenant_id')
                    ? $rule->where('tenant_id', $get('tenant_id'))
                    : $rule->whereNull('tenant_id')),
            Select::make('tenant_id')->label('Mandant')->placeholder('Alle Mandanten')
                ->options(fn () => Tenant::query()->orderBy('name')->pluck('name', 'id'))->disabledOn('edit'),
            Select::make('type')->label('Art')->options(CustomField::TYPES)->default('text')->required()->live(),
            TagsInput::make('options')->label('Auswahlwerte')->placeholder('Wert eingeben und Enter')->columnSpanFull()
                ->visible(fn (Get $get) => $get('type') === 'select')->required(fn (Get $get) => $get('type') === 'select'),
            Toggle::make('show_in_list')->label('In der Ticketliste anzeigen und filtern'),
            TextInput::make('sort')->label('Reihenfolge')->numeric()->default(100),
            Toggle::make('active')->label('Aktiv')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('tenant'))
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('label')->label('Feld')->searchable()->description(fn (CustomField $record) => $record->key),
                TextColumn::make('type')->label('Art')->formatStateUsing(fn (string $state) => CustomField::TYPES[$state] ?? $state),
                TextColumn::make('tenant.name')->label('Mandant')->placeholder('Alle Mandanten'),
                IconColumn::make('show_in_list')->label('In Liste')->boolean(),
                IconColumn::make('active')->label('Aktiv')->boolean(),
            ])
            ->filters([
                SelectFilter::make('tenant_id')->label('Mandant')
                    ->options(fn () => Tenant::query()->orderBy('name')->pluck('name', 'id')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->modalDescription('Die Werte bleiben in den Tickets gespeichert, werden aber nicht mehr angezeigt.'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCustomFields::route('/')];
    }
}
