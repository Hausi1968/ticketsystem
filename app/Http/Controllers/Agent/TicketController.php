<?php

namespace App\Http\Controllers\Agent;

use App\Agent\AgentPresenter;
use App\Enums\MessageType;
use App\Enums\TicketPriority;
use App\Mail\Outbound\ReplySender;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketPresence;
use App\Models\TicketStatus;
use App\Models\User;
use App\Support\TicketNumber;
use App\Tickets\BulkEditor;
use App\Tickets\Presence;
use App\Tickets\TicketSearch;
use App\Tickets\TicketTabs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

/** Tickets für die Mitarbeiter-App. Die Mandantentrennung greift über den angemeldeten Mitarbeiter. */
class TicketController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tab' => ['nullable', Rule::in(array_keys(TicketTabs::LABELS))],
            'search' => ['nullable', 'string', 'max:200'],
            'tenant_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Ticket::query()->with(['tenant', 'status', 'category', 'assignee', 'contact'])->whereNull('merged_into_id');
        $search = trim((string) ($data['search'] ?? ''));

        if ($search !== '') {
            // Wie in der Weboberfläche: Suche gilt für alle Tickets, die Ticketnummer findet direkt
            $number = TicketNumber::parse($search);
            $query->where(fn ($q) => TicketSearch::apply($q, $search)->when($number !== null, fn ($q) => $q->orWhere('number', $number)));
        } else {
            TicketTabs::apply($query, $data['tab'] ?? 'mine', $request->user());
        }

        if (! empty($data['tenant_id'])) {
            $query->where('tenant_id', $data['tenant_id']);
        }

        $page = $query->orderByDesc('updated_at')->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (Ticket $t) => AgentPresenter::summary($t))->all(),
            'page' => $page->currentPage(),
            'has_more' => $page->hasMorePages(),
            'total' => $page->total(),
            'counts' => [
                'mine' => Ticket::query()->where('assignee_id', $request->user()->getKey())->unresolved()->count(),
                'unassigned' => Ticket::query()->whereNull('assignee_id')->unresolved()->count(),
            ],
        ]);
    }

    public function show(Request $request, int $ticket): JsonResponse
    {
        $record = $this->find($ticket);
        app(Presence::class)->touch($record, $request->user(), $request->boolean('writing') ? TicketPresence::WRITING : TicketPresence::VIEWING);

        return response()->json(AgentPresenter::full($record, $request->user()));
    }

    public function reply(Request $request, int $ticket): JsonResponse
    {
        $record = $this->find($ticket);
        $user = $request->user();
        abort_unless(BulkEditor::canWrite($user, $record) && ! $record->merged_into_id && $record->channel !== 'error', 403, 'Keine Berechtigung zum Antworten.');

        $data = $request->validate([
            'body' => ['required', 'string', 'max:50000'],
            'cc' => ['nullable', 'array', 'max:20'],
            'cc.*' => ['email'],
            'status_id' => ['nullable', 'integer'],
            'minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'upload_ids' => ['nullable', 'array', 'max:10'],
            'upload_ids.*' => ['string', 'max:64'],
            'last_message_id' => ['nullable', 'integer'],
            'force' => ['nullable', 'boolean'],
        ]);

        if ($conflict = $this->newMessagesSince($record, $user, $data)) {
            return $conflict;
        }

        $status = ! empty($data['status_id'])
            ? TicketStatus::query()->where('active', true)->findOrFail($data['status_id'])
            : TicketStatus::system(TicketStatus::WAITING);

        try {
            $message = app(ReplySender::class)->reply($record, $user, $data['body'], $data['cc'] ?? $record->participants()->pluck('email')->all(),
                $this->uploads($user, $data['upload_ids'] ?? []), $status);
            $record->logTime((int) ($data['minutes'] ?? 0), $user, message: $message);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['error' => 'send_failed', 'message' => 'Antwort nicht gesendet: '.$e->getMessage()], 422);
        }

        app(Presence::class)->touch($record, $user);

        return response()->json([
            'ok' => true,
            'delivery_status' => $message->delivery_status,
            'message' => $message->delivery_status === 'sent' ? 'Antwort gesendet' : 'Antwort gespeichert, Versand wird erneut versucht',
            'ticket' => AgentPresenter::full($record->fresh(), $user),
        ]);
    }

    public function note(Request $request, int $ticket): JsonResponse
    {
        $record = $this->find($ticket);
        $user = $request->user();
        abort_unless($user->is_admin || $user->roleIn($record->tenant_id) !== null, 403);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:50000'],
            'minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'upload_ids' => ['nullable', 'array', 'max:10'],
            'upload_ids.*' => ['string', 'max:64'],
        ]);

        $note = app(ReplySender::class)->note($record, $user, $data['body'], $this->uploads($user, $data['upload_ids'] ?? []));
        $record->logTime((int) ($data['minutes'] ?? 0), $user, message: $note);

        return response()->json(['ok' => true, 'ticket' => AgentPresenter::full($record->fresh(), $user)]);
    }

    /** Übernehmen, zuweisen, Status, Kategorie, Priorität, Zeit – ein Endpunkt je Aktion */
    public function update(Request $request, int $ticket, string $action): JsonResponse
    {
        $record = $this->find($ticket);
        $user = $request->user();
        abort_unless(BulkEditor::canWrite($user, $record), 403, 'Keine Berechtigung.');

        switch ($action) {
            case 'take':
                $record->assignTo($user, $user);
                break;
            case 'assign':
                $data = $request->validate(['assignee_id' => ['nullable', 'integer']]);
                $assignee = ! empty($data['assignee_id']) ? User::query()->findOrFail($data['assignee_id']) : null;
                abort_if($assignee && ! BulkEditor::canBeAssigned($assignee, $record), 422, "{$assignee?->name} betreut diesen Mandanten nicht.");
                $record->assignTo($assignee, $user);
                break;
            case 'status':
                $data = $request->validate(['status_id' => ['required', 'integer']]);
                $record->changeStatus(TicketStatus::query()->where('active', true)->findOrFail($data['status_id']), $user);
                break;
            case 'category':
                $data = $request->validate(['category_id' => ['nullable', 'integer']]);
                $category = ! empty($data['category_id']) ? TicketCategory::query()->findOrFail($data['category_id']) : null;
                abort_if($category && ! $category->appliesTo((int) $record->tenant_id), 422, 'Diese Kategorie gehört zu einem anderen Mandanten.');
                $record->changeCategory($category, $user);
                break;
            case 'priority':
                $data = $request->validate(['priority' => ['required', Rule::enum(TicketPriority::class)]]);
                $record->changePriority(TicketPriority::from($data['priority']), $user);
                break;
            case 'time':
                $data = $request->validate(['minutes' => ['required', 'integer', 'min:1', 'max:1440'], 'note' => ['nullable', 'string', 'max:255']]);
                $record->logTime((int) $data['minutes'], $user, $data['note'] ?? null);
                break;
            default:
                abort(404);
        }

        return response()->json(['ok' => true, 'ticket' => AgentPresenter::full($record->fresh(), $user)]);
    }

    private function find(int $id): Ticket
    {
        return Ticket::query()->findOrFail($id);
    }

    /** Wie im Browser: Kam seit dem Laden eine neue Nachricht, einmal nachfragen (409), mit force=true trotzdem senden */
    private function newMessagesSince(Ticket $ticket, User $user, array $data): ?JsonResponse
    {
        if (empty($data['last_message_id']) || ! empty($data['force'])) {
            return null;
        }

        $new = $ticket->messages()->reorder()->where('id', '>', $data['last_message_id'])
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $user->getKey()))
            ->whereIn('type', [MessageType::Customer, MessageType::Reply, MessageType::Note])->count();

        return $new > 0
            ? response()->json(['error' => 'new_messages', 'message' => $new === 1 ? 'Seit dem Öffnen ist eine neue Nachricht eingegangen.' : "Seit dem Öffnen sind {$new} neue Nachrichten eingegangen.",
                'ticket' => AgentPresenter::full($ticket, $user)], 409)
            : null;
    }

    /** Hochgeladene Dateien dieses Mitarbeiters (siehe UploadController) für ReplySender aufbereiten */
    private function uploads(User $user, array $ids): array
    {
        $files = [];
        foreach ($ids as $id) {
            if (! preg_match('/^[A-Za-z0-9-]{20,64}$/', $id)) {
                continue;
            }
            $dir = UploadController::dir($user).'/'.$id;
            $meta = json_decode((string) Storage::disk('local')->get($dir.'.json'), true);
            if (! is_array($meta) || ! Storage::disk('local')->exists($dir)) {
                continue;
            }
            $files[] = ['path' => $dir, 'name' => $meta['name'], 'mime' => $meta['mime']];
        }

        return $files;
    }
}
