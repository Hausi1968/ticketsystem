<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Enums\MembershipRole;
use App\Enums\TicketPriority;
use App\Filament\Resources\Tickets\Tables\TicketsTable;
use App\Filament\Resources\Tickets\TicketResource;
use App\Mail\Outbound\ReplySender;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketStatus;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * @property Ticket $record
 */
class ViewTicket extends ViewRecord
{
    protected static string $resource = TicketResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->record->subject;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->record->reference().' · '.$this->record->tenant->name;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('tickets.conversation')->viewData(fn () => [
                'ticket' => $this->record->fresh(['tenant', 'status', 'category', 'contact', 'assignee', 'team', 'mailbox', 'participants', 'followUpOf',
                    'messages.attachments', 'messages.user', 'events.user']),
            ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->replyAction(),
            $this->noteAction(),
            TicketsTable::takeAction()->record($this->record)->after(fn () => $this->refreshRecord()),
            ActionGroup::make([
                $this->assignAction(),
                $this->statusAction(),
                $this->categoryAction(),
                $this->priorityAction(),
            ])->label('Bearbeiten')->button()->color('gray')->icon(Heroicon::OutlinedAdjustmentsHorizontal),
        ];
    }

    private function canWrite(): bool
    {
        $user = auth()->user();

        return $user->is_admin || ! in_array($user->roleIn($this->record->tenant_id), [null, MembershipRole::LightAgent], true);
    }

    private function replyAction(): Action
    {
        return Action::make('reply')->label('Antworten')->icon(Heroicon::OutlinedPaperAirplane)
            ->visible(fn () => $this->canWrite())
            ->modalHeading(fn () => 'Antwort an '.$this->record->contact->label())
            ->modalWidth('3xl')
            ->modalSubmitActionLabel('Senden')
            ->schema([
                Textarea::make('body')->label('Nachricht')->required()->rows(10)->autosize(),
                TagsInput::make('cc')->label('CC')->placeholder('weitere E-Mail-Adressen')
                    ->nestedRecursiveRules(['email'])
                    ->default(fn () => $this->record->participants->pluck('email')->all()),
                FileUpload::make('files')->label('Anhänge')->multiple()->storeFiles(false)
                    ->maxSize(20 * 1024)->helperText('Bis 20 MB je Datei. Größere Dateien bitte als Link versenden.'),
                Select::make('status_id')->label('Status danach')->options(fn () => self::statusOptions())
                    ->default(fn () => TicketStatus::system(TicketStatus::WAITING)->getKey())->required(),
            ])
            ->action(function (array $data) {
                $message = app(ReplySender::class)->reply(
                    $this->record, auth()->user(), $data['body'], $data['cc'] ?? [],
                    $this->storeUploads($data['files'] ?? []),
                    TicketStatus::query()->findOrFail($data['status_id']),
                );

                $message->delivery_status === 'sent'
                    ? Notification::make()->success()->title('Antwort gesendet')->send()
                    : Notification::make()->warning()->title('Antwort gespeichert, Versand fehlgeschlagen')
                        ->body(($message->delivery_error ?? '').' – Es wird automatisch erneut versucht.')->persistent()->send();

                $this->refreshRecord();
            });
    }

    private function noteAction(): Action
    {
        return Action::make('note')->label('Interne Notiz')->icon(Heroicon::OutlinedPencilSquare)->color('gray')
            ->modalHeading('Interne Notiz (nur für das Team sichtbar)')
            ->modalWidth('2xl')
            ->modalSubmitActionLabel('Speichern')
            ->schema([
                Textarea::make('body')->label('Notiz')->required()->rows(6)->autosize(),
                FileUpload::make('files')->label('Anhänge')->multiple()->storeFiles(false)->maxSize(100 * 1024),
            ])
            ->action(function (array $data) {
                app(ReplySender::class)->note($this->record, auth()->user(), $data['body'], $this->storeUploads($data['files'] ?? []));
                Notification::make()->success()->title('Notiz gespeichert')->send();
                $this->refreshRecord();
            });
    }

    private function assignAction(): Action
    {
        return Action::make('assign')->label('Zuweisen')->icon(Heroicon::OutlinedUser)
            ->visible(fn () => $this->canWrite())
            ->schema([
                Select::make('assignee_id')->label('Bearbeiter')->placeholder('– niemand –')
                    ->default(fn () => $this->record->assignee_id)
                    ->options(fn () => User::query()->where('active', true)
                        ->where(fn ($q) => $q->where('is_admin', true)
                            ->orWhereHas('memberships', fn ($m) => $m->where('tenant_id', $this->record->tenant_id)
                                ->where('role', '!=', MembershipRole::LightAgent->value)))
                        ->orderBy('name')->pluck('name', 'id'))
                    ->searchable(),
            ])
            ->action(function (array $data) {
                $this->record->assignTo($data['assignee_id'] ? User::find($data['assignee_id']) : null, auth()->user());
                Notification::make()->success()->title('Zuweisung gespeichert')->send();
                $this->refreshRecord();
            });
    }

    private function statusAction(): Action
    {
        return Action::make('status')->label('Status ändern')->icon(Heroicon::OutlinedFlag)
            ->visible(fn () => $this->canWrite())
            ->schema([
                Select::make('status_id')->label('Status')->options(fn () => self::statusOptions())
                    ->default(fn () => $this->record->status_id)->required(),
            ])
            ->action(function (array $data) {
                $status = TicketStatus::query()->findOrFail($data['status_id']);
                $this->record->changeStatus($status, auth()->user());
                Notification::make()->success()->title('Status: '.$status->name)->send();
                $this->refreshRecord();
            });
    }

    private function categoryAction(): Action
    {
        return Action::make('category')->label('Kategorie')->icon(Heroicon::OutlinedTag)
            ->visible(fn () => $this->canWrite())
            ->schema([
                Select::make('category_id')->label('Kategorie')->placeholder('– keine –')
                    ->options(fn () => TicketCategory::query()->where('active', true)
                        ->forTenant((int) $this->record->tenant_id)->ordered()->pluck('name', 'id'))
                    ->default(fn () => $this->record->category_id),
            ])
            ->action(function (array $data) {
                $category = $data['category_id'] ? TicketCategory::query()->findOrFail($data['category_id']) : null;
                $this->record->changeCategory($category, auth()->user());
                Notification::make()->success()->title('Kategorie gespeichert')->send();
                $this->refreshRecord();
            });
    }

    /** Aktive Status; schließende Status sind in der Auswahl gekennzeichnet. */
    public static function statusOptions(): array
    {
        return TicketStatus::query()->where('active', true)->ordered()->get()
            ->mapWithKeys(fn (TicketStatus $s) => [$s->getKey() => $s->name.($s->closes() ? ' (schließt das Ticket)' : '')])
            ->all();
    }

    private function priorityAction(): Action
    {
        return Action::make('priority')->label('Priorität ändern')->icon(Heroicon::OutlinedExclamationTriangle)
            ->visible(fn () => $this->canWrite())
            ->schema([
                Select::make('priority')->label('Priorität')->options(TicketPriority::class)
                    ->default(fn () => $this->record->priority)->required(),
            ])
            ->action(function (array $data) {
                $priority = $data['priority'] instanceof TicketPriority ? $data['priority'] : TicketPriority::from($data['priority']);
                $this->record->changePriority($priority, auth()->user());
                $this->refreshRecord();
            });
    }

    /**
     * Livewire-Uploads in einen Zwischenordner der lokalen Disk legen; ReplySender verschiebt sie
     * anschließend in den Anhang-Ordner des Mandanten.
     *
     * @param  array<TemporaryUploadedFile>  $files
     */
    private function storeUploads(array $files): array
    {
        $result = [];
        foreach ($files as $file) {
            if ($file instanceof TemporaryUploadedFile) {
                $result[] = [
                    'path' => $file->store('uploads-tmp', 'local'),
                    'name' => $file->getClientOriginalName(),
                    'mime' => $file->getMimeType(),
                ];
            }
        }

        return $result;
    }

    private function refreshRecord(): void
    {
        $this->record->refresh();
    }
}
