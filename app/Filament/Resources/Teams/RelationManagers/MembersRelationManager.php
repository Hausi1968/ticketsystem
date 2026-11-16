<?php

namespace App\Filament\Resources\Teams\RelationManagers;

use App\Models\Team;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Mitglieder eines Teams. Aufgenommen werden können nur Mitarbeiter, die den Mandanten des Teams betreuen.
 * „Bekommt Zuweisungen“ steuert, wer bei Regeln mit „reihum im Team“ berücksichtigt wird.
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Mitglieder';

    protected static ?string $modelLabel = 'Mitglied';

    protected static ?string $pluralModelLabel = 'Mitglieder';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->canManageTeams();
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Toggle::make('receives_assignments')->label('Bekommt Zuweisungen')
                ->helperText('Wird bei Regeln mit „reihum im Team“ berücksichtigt.'),
        ]);
    }

    public function table(Table $table): Table
    {
        /** @var Team $team */
        $team = $this->getOwnerRecord();

        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label('Name')->sortable()->searchable(),
                TextColumn::make('email')->label('E-Mail')->searchable(),
                IconColumn::make('receives_assignments')->label('Bekommt Zuweisungen')->boolean(),
                IconColumn::make('active')->label('Aktiv')->boolean(),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('Noch keine Mitglieder')
            ->emptyStateDescription('Nur Mitarbeiter, die diesen Mandanten betreuen, können ins Team.')
            ->headerActions([
                AttachAction::make()->label('Mitglied hinzufügen')->icon(Heroicon::OutlinedUserPlus)
                    ->modalHeading('Mitglied hinzufügen')
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(fn (Builder $query) => $query
                        ->where('active', true)
                        ->whereHas('memberships', fn (Builder $m) => $m->where('tenant_id', $team->tenant_id)))
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect()->label('Mitarbeiter'),
                        Toggle::make('receives_assignments')->label('Bekommt Zuweisungen')->default(true),
                        Hidden::make('tenant_id')->default($team->tenant_id),
                    ])
                    ->attachAnother(false),
            ])
            ->recordActions([
                EditAction::make()->label('Bearbeiten')->modalHeading('Mitglied bearbeiten'),
                DetachAction::make()->label('Entfernen')->modalHeading('Aus dem Team entfernen?'),
            ])
            ->toolbarActions([
                DetachBulkAction::make()->label('Entfernen'),
            ]);
    }
}
