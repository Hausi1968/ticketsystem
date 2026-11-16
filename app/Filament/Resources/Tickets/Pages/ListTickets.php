<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Enums\StatusBehaviour;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\SavedView;
use App\Models\Ticket;
use App\Tickets\TicketTabs;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ListTickets extends ListRecords
{
    protected static string $resource = TicketResource::class;

    /** Gespeicherte Ansicht, deren Filter gerade angewendet sind */
    public ?int $appliedViewId = null;

    private ?Collection $savedViewsCache = null;

    public const BASE_TABS = [
        'mine' => 'Meine',
        'unassigned' => 'Unzugewiesen',
        'open' => 'Alle offenen',
        'pending' => 'Wartend',
        'closed' => 'Geschlossen',
        'all' => 'Alle',
    ];

    public function mount(): void
    {
        parent::mount();

        if ($view = $this->activeSavedView()) {
            $this->applySavedView($view);
        }
    }

    public function getTabs(): array
    {
        $tabs = [
            'mine' => Tab::make('Meine')
                ->badge(fn () => Ticket::query()->where('assignee_id', auth()->id())->withBehaviour(StatusBehaviour::Open)->count() ?: null),
            'unassigned' => Tab::make('Unzugewiesen')
                ->badge(fn () => Ticket::query()->whereNull('assignee_id')->unresolved()->count() ?: null)
                ->badgeColor('warning'),
            'open' => Tab::make('Alle offenen'),
            'pending' => Tab::make('Wartend'),
            'closed' => Tab::make('Geschlossen'),
            'all' => Tab::make('Alle'),
        ];

        foreach ($tabs as $key => $tab) {
            $tab->modifyQueryUsing(fn (Builder $query) => $this->scopeForTab($query, $key));
        }

        foreach ($this->savedViews() as $view) {
            $tabs[$view->tabKey()] = Tab::make($view->name)->icon(Heroicon::OutlinedBookmark)
                ->modifyQueryUsing(fn (Builder $query) => $this->scopeForTab($query, $view->base_tab ?? 'all'));
        }

        return $tabs;
    }

    /** Einschränkung eines Reiters; bei einer Suche gilt sie nicht (gesucht wird in allen Tickets). */
    private function scopeForTab(Builder $query, string $tab): Builder
    {
        if ($this->searching()) {
            return $query;
        }

        return TicketTabs::apply($query, $tab, auth()->user());
    }

    /** Bei einer Suche gilt der Reiter nicht: gesucht wird in allen Tickets, auch wartenden und geschlossenen. */
    protected function searching(): bool
    {
        return filled(trim((string) $this->tableSearch));
    }

    public function getDefaultActiveTab(): string|int|null
    {
        // Wer nichts zugewiesen hat, startet in der Eingangs-Queue
        return Ticket::query()->where('assignee_id', auth()->id())->unresolved()->exists()
            ? 'mine' : 'unassigned';
    }

    public function updatedActiveTab(): void
    {
        parent::updatedActiveTab();

        if ($view = $this->activeSavedView()) {
            $this->applySavedView($view);
        } elseif ($this->appliedViewId !== null) {
            // Zurück zu einem normalen Reiter: Filter der Ansicht wieder entfernen
            $this->appliedViewId = null;
            $this->tableFilters = $this->tableDeferredFilters = null;
            $this->tableSearch = '';
            $this->tableSort = null;
            $this->getTableFiltersForm()->fill();
        }
    }

    private function applySavedView(SavedView $view): void
    {
        $this->appliedViewId = $view->getKey();
        $this->tableFilters = $this->tableDeferredFilters = $view->filters ?: null;
        $this->tableSearch = (string) $view->search;
        $this->tableSort = $view->sort;

        // Beim ersten Aufruf ist die Tabelle noch nicht aufgebaut; sie übernimmt die Filter dann selbst
        if (isset($this->table)) {
            $this->getTableFiltersForm()->fill($view->filters ?? []);
            $this->resetPage();
        }
    }

    private function savedViews(): Collection
    {
        return $this->savedViewsCache ??= SavedView::query()->ownedBy(auth()->user())->get();
    }

    private function forgetSavedViews(): void
    {
        $this->savedViewsCache = null;
        unset($this->cachedTabs);
    }

    private function activeSavedView(): ?SavedView
    {
        return str_starts_with((string) $this->activeTab, 'view_')
            ? $this->savedViews()->first(fn (SavedView $v) => $v->tabKey() === $this->activeTab)
            : null;
    }

    /** Aktueller Zustand der Liste, wie er gespeichert wird */
    private function currentState(): array
    {
        $base = $this->activeSavedView()?->base_tab ?? (array_key_exists((string) $this->activeTab, self::BASE_TABS) ? $this->activeTab : 'all');

        return [
            'base_tab' => $base,
            'filters' => $this->tableFilters,
            'search' => filled($this->tableSearch) ? (string) $this->tableSearch : null,
            'sort' => $this->tableSort,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('saveView')->label('Ansicht speichern')->icon(Heroicon::OutlinedBookmark)->color('gray')
                ->modalHeading('Aktuelle Ansicht speichern')
                ->modalDescription('Speichert Reiter, Filter, Suche und Sortierung als eigenen Reiter. Nur du siehst ihn.')
                ->schema([TextInput::make('name')->label('Name')->required()->maxLength(60)->placeholder('z. B. Bestellungen Firma A')])
                ->action(function (array $data) {
                    $view = SavedView::query()->create(['user_id' => auth()->id(), 'name' => $data['name'],
                        'position' => (int) SavedView::query()->ownedBy(auth()->user())->max('position') + 1] + $this->currentState());
                    $this->forgetSavedViews();
                    $this->activeTab = $view->tabKey();
                    $this->applySavedView($view);
                    Notification::make()->success()->title("Ansicht „{$view->name}“ gespeichert")->send();
                }),
            Action::make('updateView')->label('Ansicht aktualisieren')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->visible(fn () => $this->activeSavedView() !== null)
                ->requiresConfirmation()->modalHeading(fn () => 'Ansicht „'.$this->activeSavedView()?->name.'“ mit den aktuellen Filtern überschreiben?')
                ->action(function () {
                    $this->activeSavedView()?->update($this->currentState());
                    $this->forgetSavedViews();
                    Notification::make()->success()->title('Ansicht aktualisiert')->send();
                }),
            Action::make('deleteView')->label('Ansicht löschen')->icon(Heroicon::OutlinedTrash)->color('danger')
                ->visible(fn () => $this->activeSavedView() !== null)
                ->requiresConfirmation()->modalHeading(fn () => 'Ansicht „'.$this->activeSavedView()?->name.'“ löschen?')
                ->action(function () {
                    $this->activeSavedView()?->delete();
                    $this->forgetSavedViews();
                    $this->activeTab = $this->getDefaultActiveTab();
                    $this->updatedActiveTab();
                    Notification::make()->success()->title('Ansicht gelöscht')->send();
                }),
        ];
    }
}
