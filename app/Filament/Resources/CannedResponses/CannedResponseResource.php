<?php

namespace App\Filament\Resources\CannedResponses;

use App\Filament\Resources\CannedResponses\Pages\ManageCannedResponses;
use App\Models\CannedResponse;
use App\Models\Tenant;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

class CannedResponseResource extends Resource
{
    protected static ?string $model = CannedResponse::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|UnitEnum|null $navigationGroup = 'Verwaltung';

    protected static ?string $slug = 'textbausteine';

    protected static ?int $navigationSort = 8;

    protected static ?string $modelLabel = 'Textbaustein';

    protected static ?string $pluralModelLabel = 'Textbausteine';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('title')->label('Titel')->required()->maxLength(120)->placeholder('z. B. Bestellung eingegangen'),
            Select::make('tenant_id')->label('Mandant')->placeholder('Alle Mandanten')
                ->options(fn () => Tenant::query()->orderBy('name')->pluck('name', 'id'))
                ->helperText('Leer lassen, wenn der Baustein für alle Mandanten gilt.'),
            Textarea::make('body')->label('Text')->required()->rows(10)->columnSpanFull()
                ->helperText('Platzhalter: '.CannedResponse::PLACEHOLDERS.'. Die Signatur wird wie immer automatisch angehängt.'),
            TextInput::make('sort')->label('Reihenfolge')->numeric()->default(100),
            Toggle::make('active')->label('Aktiv (im Antwortfenster angeboten)')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('tenant'))
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('title')->label('Titel')->searchable()
                    ->description(fn (CannedResponse $record) => Str::limit(str_replace("\n", ' ', $record->body), 90)),
                TextColumn::make('tenant.name')->label('Mandant')->placeholder('Alle Mandanten'),
                IconColumn::make('active')->label('Aktiv')->boolean(),
            ])
            ->filters([
                SelectFilter::make('tenant_id')->label('Mandant')
                    ->options(fn () => Tenant::query()->orderBy('name')->pluck('name', 'id')),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCannedResponses::route('/')];
    }
}
