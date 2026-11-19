<?php

namespace App\Filament\Resources\Tickets\Tables;

use App\Enums\TicketPriority;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Models\CustomField;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketStatus;
use App\Models\User;
use App\Sla\SlaDisplay;
use App\Support\BadgeColor;
use App\Support\TicketNumber;
use App\Tickets\BulkEditor;
use App\Tickets\TicketSearch;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

class TicketsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->searchPlaceholder('Alle Tickets durchsuchen (Text, Absender, Nummer)')
            ->poll('30s')
            ->recordUrl(fn (Ticket $record) => route('filament.app.resources.tickets.view', $record))
            // für die Markierung „NEU“ bei ungelesenen Kundenantworten
            ->modifyQueryUsing(fn (Builder $query) => auth()->check() ? $query->withReadAtFor(auth()->id()) : $query)
            ->columns([
                TextColumn::make('number')->label('Ticket')->badge()
                    ->formatStateUsing(fn (Ticket $record) => $record->reference())
                    ->color(fn (Ticket $record) => Color::hex($record->tenant->color))
                    ->tooltip(fn (Ticket $record) => $record->tenant->name)
                    ->sortable()
                    // Suche nach "MUS-1000", "#1000" oder "1000"
                    ->searchable(query: fn (Builder $query, string $search) => ($number = TicketNumber::parse($search)) !== null
                        ? $query->orWhere('number', $number)
                        : $query),
                TextColumn::make('subject')->label('Betreff')->sortable()
                    // Ungelesene Kundenantwort: „NEU“ vor dem Betreff, Betreff fett
                    ->prefix(fn (Ticket $record) => $record->hasUnreadCustomerReply()
                        ? new HtmlString('<span class="anliego-neu" title="Neue Kundenantwort, noch nicht gelesen">NEU</span> ')
                        : null)
                    ->weight(fn (Ticket $record) => $record->hasUnreadCustomerReply() ? FontWeight::Bold : null)
                    // Sucht im Betreff, im Text aller Nachrichten und beim Absender (Name/E-Mail)
                    ->searchable(query: fn (Builder $query, string $search) => TicketSearch::apply($query, $search))->wrap()->limit(80)
                    ->description(fn (Ticket $record) => $record->contact?->label()),
                TextColumn::make('status.name')->label('Status')->badge()
                    // nach der Reihenfolge der Status (Neu, Offen, Wartet …), nicht alphabetisch
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy(
                        TicketStatus::query()->select('sort')->whereColumn('ticket_statuses.id', 'tickets.status_id'), $direction))
                    ->color(fn (Ticket $record) => BadgeColor::resolve($record->status?->color)),
                TextColumn::make('category.name')->label('Kategorie')->badge()->placeholder('–')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy(
                        TicketCategory::query()->select('name')->whereColumn('ticket_categories.id', 'tickets.category_id'), $direction))
                    ->color(fn (Ticket $record) => BadgeColor::resolve($record->category?->color))
                    ->toggleable(),
                TextColumn::make('priority')->label('Priorität')->badge()->sortable(),
                TextColumn::make('sla_due_at')->label('Frist')->sortable()->badge()->placeholder('–')
                    ->formatStateUsing(fn (Ticket $record) => SlaDisplay::short($record))
                    ->color(fn (Ticket $record) => SlaDisplay::shortColor($record))
                    ->icon(Heroicon::OutlinedClock)
                    ->tooltip(fn (Ticket $record) => collect(SlaDisplay::rows($record))->map(fn ($r) => "{$r['label']}: {$r['text']}")->join("\n") ?: null)
                    ->toggleable(),
                TextColumn::make('assignee.name')->label('Bearbeiter')->placeholder('– niemand –')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy(
                        User::query()->select('name')->whereColumn('users.id', 'tickets.assignee_id'), $direction)),
                TextColumn::make('team.name')->label('Team')->placeholder('–')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy(
                        Team::query()->withoutGlobalScopes()->select('name')->whereColumn('teams.id', 'tickets.team_id'), $direction))
                    ->toggleable(),
                ...self::customFieldColumns(),
                TextColumn::make('created_at')->label('Erstellt')->dateTime('d.m.Y H:i', 'Europe/Berlin')->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label('Aktualisiert')->since()->sortable()
                    ->tooltip(fn (Ticket $record) => $record->updated_at?->timezone('Europe/Berlin')->format('d.m.Y H:i')),
            ])
            ->filters([
                SelectFilter::make('tenant_id')->label('Mandant')
                    ->options(fn () => Tenant::query()->accessible()->orderBy('name')->pluck('name', 'id')),
                SelectFilter::make('team_id')->label('Team')
                    ->options(fn () => Team::query()->with('tenant')->orderBy('name')->get()
                        ->mapWithKeys(fn (Team $t) => [$t->id => "{$t->name} ({$t->tenant->short_code})"])),
                SelectFilter::make('priority')->label('Priorität')->options(TicketPriority::class),
                SelectFilter::make('status_id')->label('Status')->multiple()
                    ->options(fn () => TicketStatus::query()->ordered()->pluck('name', 'id')),
                SelectFilter::make('sla')->label('Frist (SLA)')
                    ->options(['overdue' => 'überschritten', 'soon' => 'läuft bald ab', 'breached' => 'irgendwann überschritten'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'overdue' => $query->whereNotNull('sla_due_at')->where('sla_due_at', '<', now()),
                        'soon' => $query->whereNotNull('sla_due_at')->where('sla_due_at', '>=', now())->where('sla_due_at', '<', now()->addHours(4)),
                        'breached' => $query->where('sla_breached', true),
                        default => $query,
                    }),
                SelectFilter::make('category_id')->label('Kategorie')->multiple()
                    ->options(fn () => TicketCategory::query()->with('tenant')->ordered()->get()
                        ->mapWithKeys(fn (TicketCategory $c) => [$c->id => $c->name.($c->tenant ? " ({$c->tenant->short_code})" : '')])),
                ...self::customFieldFilters(),
            ])
            ->recordActions([
                self::takeAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make(self::bulkActions())->label('Ausgewählte bearbeiten'),
            ]);
    }

    /** Mehrere markierte Tickets auf einmal übernehmen, zuweisen, Status/Priorität/Kategorie setzen. */
    private static function bulkActions(): array
    {
        $report = function (string $what, array $result) {
            [$done, $skipped] = $result;
            Notification::make()->title("{$what}: {$done} Ticket(s) geändert")
                ->body($skipped ? "{$skipped} übersprungen (keine Berechtigung, zusammengeführt oder passt nicht zum Mandanten)." : null)
                ->{$skipped ? 'warning' : 'success'}()->send();
        };

        return [
            BulkAction::make('bulkTake')->label('Übernehmen')->icon(Heroicon::OutlinedHandRaised)
                ->requiresConfirmation()->modalHeading('Ausgewählte Tickets übernehmen?')
                ->action(fn (Collection $records) => $report('Übernommen', app(BulkEditor::class)->assign($records, auth()->user(), auth()->user())))
                ->deselectRecordsAfterCompletion(),
            BulkAction::make('bulkAssign')->label('Zuweisen')->icon(Heroicon::OutlinedUser)
                ->schema([
                    Select::make('assignee_id')->label('Bearbeiter')->placeholder('– niemand –')->searchable()
                        ->options(fn () => User::query()->where('active', true)->orderBy('name')->pluck('name', 'id'))
                        ->helperText('Tickets von Mandanten, die diese Person nicht betreut, werden übersprungen.'),
                ])
                ->action(fn (Collection $records, array $data) => $report('Zugewiesen', app(BulkEditor::class)->assign(
                    $records, $data['assignee_id'] ? User::query()->find($data['assignee_id']) : null, auth()->user())))
                ->deselectRecordsAfterCompletion(),
            BulkAction::make('bulkTeam')->label('Team setzen')->icon(Heroicon::OutlinedUserGroup)
                ->schema([
                    Select::make('team_id')->label('Team')->placeholder('– kein Team –')
                        ->options(fn () => Team::query()->with('tenant')->orderBy('name')->get()
                            ->mapWithKeys(fn (Team $t) => [$t->id => "{$t->name} ({$t->tenant->short_code})"]))
                        ->helperText('Ein Team wird nur bei Tickets seines Mandanten gesetzt.'),
                ])
                ->action(fn (Collection $records, array $data) => $report('Team', app(BulkEditor::class)->team(
                    $records, $data['team_id'] ? Team::query()->find($data['team_id']) : null, auth()->user())))
                ->deselectRecordsAfterCompletion(),
            BulkAction::make('bulkStatus')->label('Status setzen')->icon(Heroicon::OutlinedFlag)
                ->schema([Select::make('status_id')->label('Status')->options(fn () => ViewTicket::statusOptions())->required()])
                ->action(fn (Collection $records, array $data) => $report('Status', app(BulkEditor::class)->status(
                    $records, TicketStatus::query()->findOrFail($data['status_id']), auth()->user())))
                ->deselectRecordsAfterCompletion(),
            BulkAction::make('bulkPriority')->label('Priorität setzen')->icon(Heroicon::OutlinedExclamationTriangle)
                ->schema([Select::make('priority')->label('Priorität')->options(TicketPriority::class)->required()])
                ->action(fn (Collection $records, array $data) => $report('Priorität', app(BulkEditor::class)->priority(
                    $records, $data['priority'] instanceof TicketPriority ? $data['priority'] : TicketPriority::from($data['priority']), auth()->user())))
                ->deselectRecordsAfterCompletion(),
            BulkAction::make('bulkCategory')->label('Kategorie setzen')->icon(Heroicon::OutlinedTag)
                ->schema([
                    Select::make('category_id')->label('Kategorie')->placeholder('– keine –')
                        ->options(fn () => TicketCategory::query()->with('tenant')->where('active', true)->ordered()->get()
                            ->mapWithKeys(fn (TicketCategory $c) => [$c->id => $c->name.($c->tenant ? " ({$c->tenant->short_code})" : '')]))
                        ->helperText('Kategorien eines Mandanten werden nur bei dessen Tickets gesetzt.'),
                ])
                ->action(fn (Collection $records, array $data) => $report('Kategorie', app(BulkEditor::class)->category(
                    $records, $data['category_id'] ? TicketCategory::query()->find($data['category_id']) : null, auth()->user())))
                ->deselectRecordsAfterCompletion(),
        ];
    }

    /** Eigene Felder mit „In der Ticketliste anzeigen“ (je Kennung eine Spalte, auch wenn mehrere Mandanten sie haben) */
    private static function listedFields()
    {
        return CustomField::query()->where('active', true)->where('show_in_list', true)
            ->orderBy('sort')->get()->unique('key');
    }

    private static function customFieldColumns(): array
    {
        return self::listedFields()->map(fn (CustomField $field) => TextColumn::make('fields.'.$field->key)->label($field->label)
            ->placeholder('–')->toggleable()
            ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('fields->'.$field->key, $direction))
            ->formatStateUsing(fn ($state) => $field->display($state)))->values()->all();
    }

    private static function customFieldFilters(): array
    {
        return self::listedFields()->map(function (CustomField $field) {
            $path = 'fields->'.$field->key;

            if (in_array($field->type, ['select', 'checkbox'], true)) {
                return SelectFilter::make('field_'.$field->key)->label($field->label)
                    ->options($field->type === 'checkbox' ? ['1' => 'ja', '0' => 'nein'] : $field->choices())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? ($field->type === 'checkbox'
                            ? ($data['value'] === '1' ? $query->where($path, true) : $query->where(fn ($q) => $q->whereNull($path)->orWhere($path, false)))
                            : $query->where($path, $data['value']))
                        : $query);
            }

            return Filter::make('field_'.$field->key)
                ->schema([TextInput::make('value')->label($field->label)])
                ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                    ? $query->where($path, 'like', '%'.$data['value'].'%')
                    : $query)
                ->indicateUsing(fn (array $data) => filled($data['value'] ?? null) ? $field->label.': '.$data['value'] : null);
        })->values()->all();
    }

    public static function takeAction(): Action
    {
        return Action::make('take')->label('Übernehmen')->icon(Heroicon::OutlinedHandRaised)
            ->visible(fn (Ticket $record) => $record->assignee_id !== auth()->id()
                && ! $record->isClosed())
            ->requiresConfirmation(fn (Ticket $record) => $record->assignee_id !== null)
            ->modalDescription(fn (Ticket $record) => $record->assignee
                ? "Das Ticket ist {$record->assignee->name} zugewiesen. Trotzdem übernehmen?" : null)
            ->action(function (Ticket $record) {
                $record->assignTo(auth()->user(), auth()->user());
                Notification::make()->success()->title("{$record->reference()} übernommen")->send();
            });
    }
}
