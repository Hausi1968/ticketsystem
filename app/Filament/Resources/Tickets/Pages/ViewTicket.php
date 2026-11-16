<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Api\ErrorReportService;
use App\Enums\MembershipRole;
use App\Enums\TicketPriority;
use App\Filament\Resources\Tickets\Tables\TicketsTable;
use App\Filament\Resources\Tickets\TicketResource;
use App\Mail\Outbound\ReplySender;
use App\Models\CannedResponse;
use App\Models\CustomField;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketMessage;
use App\Models\TicketPresence;
use App\Models\TicketStatus;
use App\Models\User;
use App\Tickets\Presence;
use App\Tickets\TicketMerger;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * @property Ticket $record
 */
class ViewTicket extends ViewRecord
{
    protected static string $resource = TicketResource::class;

    /** Letzte Nachricht, die dieser Mitarbeiter gesehen hat – für die Warnung vor doppelter Antwort */
    public ?int $seenMessageId = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->seenMessageId = $this->latestMessageId();
        app(Presence::class)->touch($this->record, auth()->user());
    }

    /** Wird von der Ansicht alle 10 Sekunden aufgerufen; aktualisiert Anwesenheit und Verlauf. */
    public function heartbeat(): void
    {
        $writing = collect($this->mountedActions ?? [])->contains(fn ($a) => in_array($a['name'] ?? null, ['reply', 'note'], true));
        app(Presence::class)->touch($this->record, auth()->user(), $writing ? TicketPresence::WRITING : TicketPresence::VIEWING);

        // Ohne offenes Antwortfenster sieht der Mitarbeiter neue Nachrichten direkt im Verlauf
        if (! $writing) {
            $this->seenMessageId = $this->latestMessageId();
        }
    }

    private function latestMessageId(): ?int
    {
        return $this->record->messages()->reorder()->max('id');
    }

    /**
     * Ist seit dem Öffnen eine neue Nachricht (Kunde oder Kollege) eingegangen, wird einmal gewarnt
     * und abgebrochen. Ein zweites „Senden“ schickt die Antwort dann ab.
     */
    private function guardAgainstNewMessages(Action $action): void
    {
        $new = $this->record->messages()->reorder()->with('user')
            ->when($this->seenMessageId, fn ($query) => $query->where('id', '>', $this->seenMessageId))
            ->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', '!=', auth()->id()))
            ->latest('id')->get();

        $this->seenMessageId = $this->latestMessageId();

        if ($new->isEmpty()) {
            return;
        }

        $who = $new->map(fn (TicketMessage $m) => $m->user?->name ?? ($m->from_name ?: $m->from_email))->filter()->unique()->join(', ');
        Notification::make()->warning()->persistent()
            ->title($new->count() === 1 ? 'Neue Nachricht seit dem Öffnen' : $new->count().' neue Nachrichten seit dem Öffnen')
            ->body("Von {$who}. Bitte den Verlauf prüfen. Erneut auf „Senden“ klicken, um trotzdem abzuschicken.")
            ->send();
        $this->refreshRecord();
        $action->halt();
    }

    public function getTitle(): string|Htmlable
    {
        return $this->record->subject;
    }

    public function getSubheading(): string|Htmlable|null
    {
        $merged = $this->record->merged_into_id ? $this->record->mergedInto : null;

        return $this->record->reference().' · '.$this->record->tenant->name
            .($merged ? ' · zusammengeführt in '.$merged->reference() : '')
            .($this->record->error_count > 1 ? ' · '.$this->record->error_count.'× aufgetreten, zuletzt '
                .$this->record->error_last_at?->timezone($this->record->tenant->timezone ?: 'Europe/Berlin')->format('d.m.Y H:i') : '');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('tickets.presence')->viewData(fn () => [
                'others' => app(Presence::class)->others($this->record, auth()->user()),
            ]),
            View::make('tickets.conversation')->viewData(fn () => [
                'ticket' => $this->record->fresh(['tenant', 'status', 'category', 'contact', 'assignee', 'team', 'mailbox', 'participants', 'followUpOf', 'slaPolicy',
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
                $this->timeAction(),
                $this->fieldsAction(),
                $this->mergeAction(),
                $this->blockSenderAction(),
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
            // Automatische Fehlermeldungen haben keinen Empfänger – dort nur Notizen
            ->visible(fn () => $this->canWrite() && ! $this->record->merged_into_id && $this->record->channel !== ErrorReportService::CHANNEL)
            ->modalHeading(fn () => 'Antwort an '.$this->record->contact->label())
            ->modalWidth('3xl')
            ->modalSubmitActionLabel('Senden')
            ->schema([
                Select::make('canned')->label('Textbaustein einfügen')->placeholder('– keiner –')
                    ->options(fn () => CannedResponse::query()->forTenant((int) $this->record->tenant_id)
                        ->where('active', true)->orderBy('sort')->orderBy('title')->pluck('title', 'id'))
                    ->visible(fn () => CannedResponse::query()->forTenant((int) $this->record->tenant_id)->where('active', true)->exists())
                    ->searchable()->live()->dehydrated(false)
                    ->afterStateUpdated(function (?string $state, Get $get, Set $set) {
                        $canned = $state ? CannedResponse::query()->forTenant((int) $this->record->tenant_id)->find($state) : null;
                        if ($canned) {
                            $current = rtrim((string) $get('body'));
                            $set('body', ($current !== '' ? $current."\n\n" : '').$canned->renderFor($this->record, auth()->user()));
                        }
                        $set('canned', null);
                    }),
                Textarea::make('body')->label('Nachricht')->required()->rows(10)->autosize(),
                TagsInput::make('cc')->label('CC')->placeholder('weitere E-Mail-Adressen')
                    ->nestedRecursiveRules(['email'])
                    ->default(fn () => $this->record->participants->pluck('email')->all()),
                FileUpload::make('files')->label('Anhänge')->multiple()->storeFiles(false)
                    ->maxSize(20 * 1024)->helperText('Bis 20 MB je Datei. Größere Dateien bitte als Link versenden.'),
                TextInput::make('minutes')->label('Zeit (Minuten)')->numeric()->integer()->minValue(0)->maxValue(1440)
                    ->placeholder('optional, z. B. 15')->helperText('Wird in der Zeiterfassung des Tickets gebucht.'),
                Select::make('status_id')->label('Status danach')->options(fn () => self::statusOptions())
                    ->default(fn () => TicketStatus::system(TicketStatus::WAITING)->getKey())->required(),
            ])
            ->beforeFormFilled(fn () => app(Presence::class)->touch($this->record, auth()->user(), TicketPresence::WRITING))
            ->action(function (array $data, Action $action) {
                $this->guardAgainstNewMessages($action);

                try {
                    $message = app(ReplySender::class)->reply(
                        $this->record, auth()->user(), $data['body'], $data['cc'] ?? [],
                        $this->storeUploads($data['files'] ?? []),
                        TicketStatus::query()->findOrFail($data['status_id']),
                    );
                    $this->record->logTime((int) ($data['minutes'] ?? 0), auth()->user(), message: $message);
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()->danger()->title('Antwort nicht gesendet')
                        ->body($e->getMessage())->persistent()->send();
                    $action->halt(); // Fenster bleibt offen, der Text geht nicht verloren
                }

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
                TextInput::make('minutes')->label('Zeit (Minuten)')->numeric()->integer()->minValue(0)->maxValue(1440)
                    ->placeholder('optional, z. B. 15')->helperText('Wird in der Zeiterfassung des Tickets gebucht.'),
            ])
            ->action(function (array $data) {
                $note = app(ReplySender::class)->note($this->record, auth()->user(), $data['body'], $this->storeUploads($data['files'] ?? []));
                $this->record->logTime((int) ($data['minutes'] ?? 0), auth()->user(), message: $note);
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

    private function timeAction(): Action
    {
        return Action::make('time')->label('Zeit erfassen')->icon(Heroicon::OutlinedClock)
            ->visible(fn () => $this->canWrite())
            ->schema([
                TextInput::make('minutes')->label('Minuten')->numeric()->integer()->minValue(1)->maxValue(1440)->required(),
                DatePicker::make('worked_on')->label('Datum')->default(now())->maxDate(now())->native(false)->displayFormat('d.m.Y')->required(),
                TextInput::make('note')->label('Tätigkeit')->maxLength(255)->placeholder('optional, z. B. Telefonat'),
            ])
            ->action(function (array $data) {
                $this->record->logTime((int) $data['minutes'], auth()->user(), $data['note'] ?? null, $data['worked_on']);
                Notification::make()->success()->title('Zeit gebucht')->send();
                $this->refreshRecord();
            });
    }

    private function fieldsAction(): Action
    {
        return Action::make('fields')->label('Felder bearbeiten')->icon(Heroicon::OutlinedListBullet)
            ->visible(fn () => $this->canWrite() && CustomField::for((int) $this->record->tenant_id)->isNotEmpty())
            ->fillForm(fn () => ['fields' => $this->record->fields ?? []])
            ->schema(fn () => CustomField::for((int) $this->record->tenant_id)->map->formComponent()->all())
            ->action(function (array $data) {
                $this->record->updateFields($data['fields'] ?? [], auth()->user());
                Notification::make()->success()->title('Felder gespeichert')->send();
                $this->refreshRecord();
            });
    }

    private function mergeAction(): Action
    {
        return Action::make('merge')->label('Zusammenführen')->icon(Heroicon::OutlinedArrowsPointingIn)
            ->visible(fn () => $this->canWrite() && ! $this->record->merged_into_id)
            ->modalHeading('Ticket zusammenführen')
            ->modalDescription(fn () => 'Alle Nachrichten und Anhänge von '.$this->record->reference().' wandern in das gewählte Ticket, '
                .'der Absender wird dort beteiligt. Dieses Ticket wird geschlossen; spätere Antworten darauf landen automatisch im gewählten Ticket.')
            ->modalSubmitActionLabel('Zusammenführen')
            ->schema([
                Select::make('target_id')->label('In Ticket')->required()->searchable()
                    ->helperText('Nummer oder Betreff eingeben. Vorgeschlagen: offene Tickets desselben Kontakts.')
                    ->options(fn () => $this->mergeCandidates()->where('contact_id', $this->record->contact_id)
                        ->unresolved()->limit(20)->get()->mapWithKeys(fn (Ticket $t) => [$t->getKey() => self::ticketLabel($t)]))
                    ->getSearchResultsUsing(function (string $search) {
                        $number = (int) preg_replace('/\D/', '', $search);

                        return $this->mergeCandidates()
                            ->where(fn ($query) => $query->where('subject', 'like', '%'.$search.'%')
                                ->when($number > 0, fn ($query) => $query->orWhere('number', $number)))
                            ->limit(30)->get()->mapWithKeys(fn (Ticket $t) => [$t->getKey() => self::ticketLabel($t)]);
                    })
                    ->getOptionLabelUsing(fn ($value) => ($t = Ticket::query()->find($value)) ? self::ticketLabel($t) : null),
            ])
            ->action(function (array $data) {
                $target = Ticket::query()->where('tenant_id', $this->record->tenant_id)->findOrFail($data['target_id']);
                $target = app(TicketMerger::class)->merge($this->record, $target, auth()->user());

                Notification::make()->success()->title('Zusammengeführt in '.$target->reference())->send();
                $this->redirect(TicketResource::getUrl('view', ['record' => $target]));
            });
    }

    private function blockSenderAction(): Action
    {
        return Action::make('blockSender')->label('Absender sperren')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
            ->visible(fn () => $this->record->mailbox_id && $this->record->contact
                && (auth()->user()->is_admin || auth()->user()->roleIn($this->record->tenant_id) === MembershipRole::Supervisor))
            ->modalHeading('Absender sperren')
            ->modalDescription(fn () => 'Aus E-Mails dieses Absenders an „'.$this->record->mailbox?->email.'“ entstehen keine Tickets mehr. Die Liste lässt sich unter Verwaltung → Postfächer bearbeiten.')
            ->schema([
                Radio::make('scope')->label('Sperren')->required()->default('address')
                    ->options(fn () => [
                        'address' => $this->record->contact->email,
                        'domain' => 'alle Absender von @'.Str::after($this->record->contact->email, '@'),
                    ]),
                Toggle::make('solve')->label('Ticket als gelöst markieren')->default(true),
            ])
            ->action(function (array $data) {
                $email = mb_strtolower($this->record->contact->email);
                $entry = $data['scope'] === 'domain' ? '@'.Str::after($email, '@') : $email;
                $this->record->mailbox->blockSender($entry);

                if ($data['solve'] ?? false) {
                    $this->record->changeStatus(TicketStatus::SOLVED, auth()->user(), ['note' => 'Absender gesperrt']);
                }
                $this->record->logEvent('sender_blocked', auth()->user(), ['entry' => $entry]);

                Notification::make()->success()->title("{$entry} gesperrt")->send();
                $this->refreshRecord();
            });
    }

    private function mergeCandidates()
    {
        return Ticket::query()->with('tenant', 'contact')
            ->where('tenant_id', $this->record->tenant_id)
            ->whereKeyNot($this->record->getKey())
            ->whereNull('merged_into_id')
            ->latest('id');
    }

    private static function ticketLabel(Ticket $ticket): string
    {
        return $ticket->reference().' – '.Str::limit($ticket->subject, 60).' ('.($ticket->contact?->email ?? '–').')';
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
        $this->seenMessageId = max((int) $this->seenMessageId, (int) $this->latestMessageId()) ?: null;
        app(Presence::class)->touch($this->record, auth()->user());
    }
}
