<?php

namespace App\Api;

use App\Enums\MessageType;
use App\Enums\StatusBehaviour;
use App\Enums\TicketPriority;
use App\Mail\Outbound\AcknowledgementSender;
use App\Models\ApiKey;
use App\Models\ApiUpload;
use App\Models\Contact;
use App\Models\Mailbox;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketStatus;
use App\Tickets\RuleEngine;
use App\Tickets\TicketNotifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApiTicketService
{
    public function __construct(
        private readonly TicketNotifier $notifier,
        private readonly AcknowledgementSender $acknowledgement,
    ) {}

    /** @param array<string, mixed> $data bereits validiert */
    public function create(ApiKey $key, array $data): Ticket
    {
        return DB::transaction(function () use ($key, $data) {
            $tenantId = (int) $key->tenant_id;
            $contact = $this->contactFor($tenantId, $data['reporter']);
            $uploads = $this->uploadsFor($key, $data['attachment_ids'] ?? []);

            $ticket = Ticket::create([
                'tenant_id' => $tenantId,
                'subject' => $data['subject'],
                'channel' => 'api',
                'priority' => isset($data['priority']) ? TicketPriority::from($data['priority']) : TicketPriority::Normal,
                'category_id' => $this->categoryFor($tenantId, $data['category'] ?? null)?->getKey(),
                'mailbox_id' => $key->mailbox_id ?? Mailbox::query()->where('active', true)->orderBy('id')->value('id'),
                'team_id' => $key->team_id,
                'contact_id' => $contact->getKey(),
                'context' => $data['context'] ?? null,
                'api_key_id' => $key->getKey(),
                'last_customer_message_at' => now(),
            ]);

            $message = $ticket->messages()->create([
                'tenant_id' => $tenantId,
                'type' => MessageType::Customer,
                'contact_id' => $contact->getKey(),
                'from_email' => $contact->email,
                'from_name' => $contact->name,
                'subject' => $data['subject'],
                'body_text' => $data['description'],
            ]);

            foreach ($uploads as $upload) {
                $upload->attachTo($message);
            }

            $ticket->logEvent('created', data: ['channel' => 'api', 'api_key' => $key->name]);

            if (! empty($data['fields']) && is_array($data['fields'])) {
                $ticket->updateFields($data['fields']);
            }

            app(RuleEngine::class)->apply($ticket);
            $this->notifier->ticketCreated($ticket);
            $this->acknowledgement->queueFor($ticket);

            return $ticket;
        });
    }

    /**
     * Antwort des Nutzers auf sein Ticket, z. B. aus "Meine Meldungen". Wirkt wie eine Kunden-E-Mail:
     * Wartet/geschlossen → wieder offen, Hinweis an den Bearbeiter. Ist beim geschlossenen Status
     * "Kundenantwort → neues Ticket" eingestellt, entsteht ein Folgeticket.
     *
     * @param  array<string, mixed>  $data  bereits validiert
     * @return array{0: Ticket, 1: bool} Ticket, das die Antwort bekommen hat, und ob es neu ist
     */
    public function reply(ApiKey $key, Ticket $ticket, array $data): array
    {
        $reporter = $data['reporter'];
        $externalId = $reporter['external_user_id'] ?? null;
        $email = isset($reporter['email']) ? mb_strtolower(trim($reporter['email'])) : null;

        $owner = ($externalId !== null && $ticket->contact?->external_user_id === $externalId)
            || ($email !== null && $ticket->acceptsRepliesFrom($email));
        if (! $owner) {
            throw new AuthorizationException('Dieses Ticket gehört nicht zu diesem Nutzer.');
        }

        // Wurde das Ticket zusammengeführt, geht die Antwort ins Ziel-Ticket, Absender bleibt der Nutzer
        $author = $ticket->contact;
        $ticket = $ticket->mergeTarget();

        $status = $ticket->status;
        if ($status->closes() && $status->reply_creates_new_ticket) {
            $followUp = $this->create($key, [
                'subject' => $ticket->subject,
                'description' => $data['body'],
                'reporter' => $reporter + ['email' => $author->email],
                'attachment_ids' => $data['attachment_ids'] ?? [],
            ] + ['category' => $ticket->category?->name]);
            $followUp->forceFill(['follow_up_of_id' => $ticket->getKey(), 'priority' => $ticket->priority])->save();

            return [$followUp, true];
        }

        return DB::transaction(function () use ($key, $ticket, $data, $status, $author) {
            $contact = $author;
            $uploads = $this->uploadsFor($key, $data['attachment_ids'] ?? []);

            $message = $ticket->messages()->create([
                'tenant_id' => $ticket->tenant_id,
                'type' => MessageType::Customer,
                'contact_id' => $contact->getKey(),
                'from_email' => $contact->email,
                'from_name' => $contact->name,
                'subject' => 'Re: '.$ticket->subject,
                'body_text' => $data['body'],
            ]);

            foreach ($uploads as $upload) {
                $upload->attachTo($message);
            }

            $ticket->forceFill(['last_customer_message_at' => now(), 'customer_seen_at' => now()])->save();

            if (in_array($status->behaviour, [StatusBehaviour::Closed, StatusBehaviour::Pending], true)) {
                $ticket->changeStatus(TicketStatus::OPEN);
            }

            $ticket->unsnooze(null, 'reply');
            $this->notifier->customerReplied($ticket);

            return [$ticket, false];
        });
    }

    /** Kontakt über external_user_id, sonst E-Mail; fehlende Angaben werden ergänzt. */
    private function contactFor(int $tenantId, array $reporter): Contact
    {
        $email = mb_strtolower(trim($reporter['email']));
        $externalId = $reporter['external_user_id'] ?? null;
        $name = $reporter['name'] ?? null;

        $contact = $externalId ? Contact::query()->where('external_user_id', $externalId)->first() : null;
        $contact ??= Contact::query()->where('email', $email)->first();

        if (! $contact) {
            return Contact::create(['tenant_id' => $tenantId, 'email' => $email, 'name' => $name, 'external_user_id' => $externalId]);
        }

        $updates = [];
        if ($externalId && ! $contact->external_user_id) {
            $updates['external_user_id'] = $externalId;
        }
        if ($name && ! $contact->name) {
            $updates['name'] = $name;
        }
        // Neue E-Mail-Adresse des Nutzers übernehmen, sofern sie keinem anderen Kontakt gehört
        if ($contact->email !== $email && ! Contact::query()->where('email', $email)->exists()) {
            $updates['email'] = $email;
        }
        if ($updates) {
            $contact->update($updates);
        }

        return $contact;
    }

    public function categoryFor(int $tenantId, ?string $name): ?TicketCategory
    {
        if (blank($name)) {
            return null;
        }

        return TicketCategory::query()->forTenant($tenantId)->where('active', true)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->orderByRaw('tenant_id IS NULL') // Mandanten-Kategorie vor allgemeiner
            ->first();
    }

    /** @return list<ApiUpload> */
    private function uploadsFor(ApiKey $key, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $uploads = ApiUpload::query()->whereIn('public_id', $ids)->whereNull('used_at')->lockForUpdate()->get();

        $missing = array_diff($ids, $uploads->pluck('public_id')->all());
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'attachment_ids' => 'Unbekannte oder bereits verwendete Anhänge: '.implode(', ', $missing),
            ]);
        }

        return $uploads->all();
    }
}
