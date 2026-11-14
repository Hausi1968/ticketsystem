<?php

namespace App\Api;

use App\Enums\MessageType;
use App\Enums\TicketPriority;
use App\Models\ApiKey;
use App\Models\ApiUpload;
use App\Models\Contact;
use App\Models\Mailbox;
use App\Models\Ticket;
use App\Models\TicketCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApiTicketService
{
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

            return $ticket;
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

    private function categoryFor(int $tenantId, ?string $name): ?TicketCategory
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
