<?php

namespace App\Http\Controllers\Api;

use App\Api\ApiTicketService;
use App\Api\TicketPresenter;
use App\Enums\TicketPriority;
use App\Models\ApiKey;
use App\Models\Ticket;
use App\Support\TicketNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TicketController
{
    public function store(Request $request, ApiTicketService $service): JsonResponse
    {
        /** @var ApiKey $key */
        $key = $request->attributes->get('api_key');
        $idempotencyKey = $request->header('Idempotency-Key');

        if ($idempotencyKey !== null && ($existing = $this->replay($key, $idempotencyKey))) {
            return response()->json(TicketPresenter::summary($existing), 200, ['Idempotent-Replayed' => 'true']);
        }

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:50000'],
            'category' => ['nullable', 'string', 'max:100'],
            'priority' => ['nullable', Rule::enum(TicketPriority::class)],
            'reporter' => ['required', 'array'],
            'reporter.email' => ['required', 'email', 'max:191'],
            'reporter.name' => ['nullable', 'string', 'max:191'],
            'reporter.external_user_id' => ['nullable', 'string', 'max:191'],
            'context' => ['nullable', 'array', 'max:30'],
            'context.*' => ['nullable', function (string $attribute, mixed $value, \Closure $fail) {
                if (! is_scalar($value) || mb_strlen((string) $value) > 2000) {
                    $fail('Kontextangaben müssen einfache Werte bis 2000 Zeichen sein.');
                }
            }],
            'attachment_ids' => ['nullable', 'array', 'max:20'],
            'attachment_ids.*' => ['string', 'max:40'],
        ]);

        try {
            $ticket = DB::transaction(function () use ($service, $key, $data, $idempotencyKey) {
                $ticket = $service->create($key, $data);

                if ($idempotencyKey !== null) {
                    DB::table('api_idempotency_keys')->insert([
                        'api_key_id' => $key->getKey(),
                        'key' => mb_substr($idempotencyKey, 0, 100),
                        'ticket_id' => $ticket->getKey(),
                        'created_at' => now(),
                    ]);
                }

                return $ticket;
            });
        } catch (UniqueConstraintViolationException) {
            // Gleichzeitige Wiederholung: die andere Anfrage hat das Ticket schon angelegt
            $ticket = $this->replay($key, $idempotencyKey) ?? abort(409);

            return response()->json(TicketPresenter::summary($ticket), 200, ['Idempotent-Replayed' => 'true']);
        }

        return response()->json(TicketPresenter::summary($ticket->fresh(['status', 'category', 'tenant'])), 201);
    }

    /** Tickets eines Nutzers, z. B. für "Meine Meldungen" in der Anwendung. */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'external_user_id' => ['required_without:email', 'nullable', 'string', 'max:191'],
            'email' => ['required_without:external_user_id', 'nullable', 'email'],
        ]);

        $tickets = Ticket::query()
            ->with('status', 'category', 'tenant')
            ->whereHas('contact', fn ($query) => $request->filled('external_user_id')
                ? $query->where('external_user_id', $request->input('external_user_id'))
                : $query->where('email', mb_strtolower($request->input('email'))))
            ->latest('id')
            ->limit(50)
            ->get();

        return response()->json(['data' => $tickets->map(fn (Ticket $t) => TicketPresenter::summary($t))->values()]);
    }

    public function show(string $reference): JsonResponse
    {
        $number = TicketNumber::parse($reference) ?? abort(404);
        $ticket = Ticket::query()->with('status', 'category', 'tenant')->where('number', $number)->firstOrFail();

        return response()->json(TicketPresenter::full($ticket));
    }

    /** Antwort des Nutzers auf sein Ticket */
    public function reply(Request $request, string $reference, ApiTicketService $service): JsonResponse
    {
        $number = TicketNumber::parse($reference) ?? abort(404);
        $ticket = Ticket::query()->with('status', 'contact', 'category')->where('number', $number)->firstOrFail();

        $data = $request->validate([
            'body' => ['required', 'string', 'max:50000'],
            'reporter' => ['required', 'array'],
            'reporter.external_user_id' => ['required_without:reporter.email', 'nullable', 'string', 'max:191'],
            'reporter.email' => ['required_without:reporter.external_user_id', 'nullable', 'email', 'max:191'],
            'reporter.name' => ['nullable', 'string', 'max:191'],
            'attachment_ids' => ['nullable', 'array', 'max:20'],
            'attachment_ids.*' => ['string', 'max:40'],
        ]);

        try {
            [$target, $created] = $service->reply($request->attributes->get('api_key'), $ticket, $data);
        } catch (AuthorizationException $e) {
            return response()->json(['error' => 'not_owner', 'message' => $e->getMessage()], 403);
        }

        return response()->json(
            TicketPresenter::summary($target->fresh(['status', 'category', 'tenant'])) + ['follow_up' => $created],
            201,
        );
    }

    private function replay(ApiKey $key, ?string $idempotencyKey): ?Ticket
    {
        $ticketId = DB::table('api_idempotency_keys')
            ->where('api_key_id', $key->getKey())
            ->where('key', mb_substr((string) $idempotencyKey, 0, 100))
            ->value('ticket_id');

        return $ticketId ? Ticket::query()->with('status', 'category', 'tenant')->find($ticketId) : null;
    }
}
