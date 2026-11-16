<?php

namespace App\Mail\Inbound;

use App\Enums\MessageType;
use App\Enums\StatusBehaviour;
use App\Mail\Outbound\AcknowledgementSender;
use App\Models\Attachment;
use App\Models\Contact;
use App\Models\Mailbox;
use App\Models\MailReceipt;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketStatus;
use App\Support\HtmlCleaner;
use App\Tenancy\TenantContext;
use App\Tickets\TicketNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Verarbeitet eine abgerufene E-Mail eines Postfachs:
 * Dublettenprüfung → Schleifen-/Bounce-Erkennung → Zuordnung zu einem bestehenden Ticket
 * (Message-ID-Kette, dann Betreff-Kennung) → sonst neues Ticket.
 *
 * Jede E-Mail hinterlässt einen MailReceipt, damit nachvollziehbar ist, was mit ihr passiert ist.
 */
class InboundMailProcessor
{
    public const CREATED = 'created';

    public const APPENDED = 'appended';

    public const REOPENED = 'reopened';

    public const FOLLOW_UP = 'follow_up';

    public const DUPLICATE = 'duplicate';

    public const FAILED = 'failed';

    public function __construct(
        private readonly EmailParser $parser,
        private readonly LoopDetector $loopDetector,
        private readonly TenantContext $tenancy,
        private readonly TicketNotifier $notifier,
        private readonly AcknowledgementSender $acknowledgement,
    ) {}

    public function process(Mailbox $mailbox, string $raw): MailReceipt
    {
        return $this->tenancy->runFor((int) $mailbox->tenant_id, fn () => $this->handle($mailbox, $raw));
    }

    private function handle(Mailbox $mailbox, string $raw): MailReceipt
    {
        try {
            $email = $this->parser->parse($raw);
        } catch (Throwable $e) {
            Log::warning('E-Mail konnte nicht gelesen werden', ['mailbox' => $mailbox->getKey(), 'error' => $e->getMessage()]);

            return $this->receipt($mailbox, hash('sha256', 'raw:'.$raw), null, null, self::FAILED,
                note: 'Nicht lesbar: '.$e->getMessage(), rawPath: $this->storeRaw($mailbox, $raw));
        }

        $hash = $email->hash();

        if ($existing = MailReceipt::query()->where('mailbox_id', $mailbox->getKey())->where('message_hash', $hash)->first()) {
            return $existing->setAttribute('outcome', self::DUPLICATE);
        }

        // Dieselbe E-Mail kam schon über ein anderes Postfach desselben Mandanten (z. B. als CC)
        if ($email->messageId && TicketMessage::query()->where('message_id', $email->messageId)->exists()) {
            return $this->receipt($mailbox, $hash, $email, null, self::DUPLICATE, note: 'Bereits über ein anderes Postfach eingegangen');
        }

        $rawPath = $this->storeRaw($mailbox, $raw);

        if ($reason = $this->loopDetector->classify($email, $mailbox)) {
            return $this->receipt($mailbox, $hash, $email, null, $reason, rawPath: $rawPath);
        }

        if (! $email->fromEmail) {
            return $this->receipt($mailbox, $hash, $email, null, self::FAILED, note: 'Kein Absender', rawPath: $rawPath);
        }

        try {
            return DB::transaction(function () use ($mailbox, $email, $hash, $rawPath) {
                [$ticket, $outcome] = $this->resolveTicket($mailbox, $email);
                $this->appendMessage($ticket, $email, $rawPath);

                if (in_array($outcome, [self::CREATED, self::FOLLOW_UP], true)) {
                    $this->notifier->ticketCreated($ticket);
                    $this->acknowledgement->queueFor($ticket, $email);
                } else {
                    $this->notifier->customerReplied($ticket);
                }

                return $this->receipt($mailbox, $hash, $email, $ticket, $outcome, rawPath: $rawPath);
            });
        } catch (Throwable $e) {
            Log::error('E-Mail konnte nicht verarbeitet werden', ['mailbox' => $mailbox->getKey(), 'error' => $e->getMessage()]);

            return $this->receipt($mailbox, $hash, $email, null, self::FAILED, note: $e->getMessage(), rawPath: $rawPath);
        }
    }

    /** @return array{0: Ticket, 1: string} */
    private function resolveTicket(Mailbox $mailbox, ParsedEmail $email): array
    {
        // Zusammengeführte Tickets: die Antwort gehört ins Ziel-Ticket
        $existing = ($this->findByReferences($email) ?? $this->findBySubjectToken($mailbox, $email))?->mergeTarget();

        if ($existing === null) {
            return [$this->createTicket($mailbox, $email), self::CREATED];
        }

        $status = $existing->status;

        // Beim Status einstellbar: Antwort auf ein geschlossenes Ticket öffnet es wieder oder erzeugt ein neues
        if ($status->closes() && $status->reply_creates_new_ticket) {
            return [$this->createTicket($mailbox, $email, followUpOf: $existing), self::FOLLOW_UP];
        }

        if (in_array($status->behaviour, [StatusBehaviour::Closed, StatusBehaviour::Pending], true)) {
            $existing->changeStatus(TicketStatus::OPEN);

            return [$existing, self::REOPENED];
        }

        return [$existing, self::APPENDED];
    }

    private function findByReferences(ParsedEmail $email): ?Ticket
    {
        $ids = $email->referencedIds();

        if ($ids === []) {
            return null;
        }

        $message = TicketMessage::query()->whereIn('message_id', $ids)->latest('id')->first();

        return $message?->ticket;
    }

    /**
     * Betreff-Kennung wie [#ABC-1001]. Nur gültig, wenn der Absender zum Ticket gehört –
     * sonst könnte jeder durch Raten einer Nummer Nachrichten in fremde Tickets schreiben.
     */
    private function findBySubjectToken(Mailbox $mailbox, ParsedEmail $email): ?Ticket
    {
        if (! preg_match('/\[#([A-Z0-9]{1,10})-(\d+)\]/i', $email->subject, $m)) {
            return null;
        }

        $tenant = Tenant::query()->find($mailbox->tenant_id);
        if (! $tenant || strcasecmp($tenant->short_code, $m[1]) !== 0) {
            return null;
        }

        $ticket = Ticket::query()->where('number', (int) $m[2])->first();

        return $ticket && $ticket->acceptsRepliesFrom((string) $email->fromEmail) ? $ticket : null;
    }

    private function createTicket(Mailbox $mailbox, ParsedEmail $email, ?Ticket $followUpOf = null): Ticket
    {
        $contact = Contact::findOrCreateFor((int) $mailbox->tenant_id, $email->fromEmail, $email->fromName);

        $ticket = Ticket::create([
            'tenant_id' => $mailbox->tenant_id,
            'subject' => mb_substr(self::cleanSubject($email->subject), 0, 250),
            'channel' => 'email',
            'mailbox_id' => $mailbox->getKey(),
            'team_id' => $mailbox->team_id,
            'category_id' => $mailbox->default_category_id,
            'contact_id' => $contact->getKey(),
            'follow_up_of_id' => $followUpOf?->getKey(),
        ]);

        $ticket->logEvent('created', data: array_filter(['channel' => 'email', 'mailbox' => $mailbox->email, 'follow_up_of' => $followUpOf?->getKey()]));

        return $ticket;
    }

    private function appendMessage(Ticket $ticket, ParsedEmail $email, ?string $rawPath): TicketMessage
    {
        $contact = Contact::findOrCreateFor((int) $ticket->tenant_id, $email->fromEmail, $email->fromName);

        // CC-Empfänger dürfen mitlesen und antworten (außer den eigenen Postfächern). An-Empfänger
        // werden bewusst nicht übernommen: Dort steht meist unsere eigene Adresse oder ein Alias davon.
        $ownAddresses = Mailbox::query()->withoutGlobalScopes()->pluck('email')->all();
        foreach ($email->cc as $recipient) {
            if (! in_array($recipient['email'], $ownAddresses, true)) {
                $ticket->addParticipant(Contact::findOrCreateFor((int) $ticket->tenant_id, $recipient['email'], $recipient['name']));
            }
        }
        if ($contact->getKey() !== $ticket->contact_id) {
            $ticket->addParticipant($contact);
        }

        $text = $email->text ?? HtmlCleaner::toText($email->html);

        $message = $ticket->messages()->create([
            'tenant_id' => $ticket->tenant_id,
            'type' => MessageType::Customer,
            'contact_id' => $contact->getKey(),
            'from_email' => $email->fromEmail,
            'from_name' => $email->fromName,
            'to' => $email->to,
            'cc' => $email->cc,
            'subject' => mb_substr($email->subject, 0, 250),
            'body_text' => $text,
            'body_html' => HtmlCleaner::clean($email->html),
            'message_id' => $email->messageId,
            'in_reply_to' => $email->inReplyTo,
            'references' => implode(' ', $email->references) ?: null,
            'raw_path' => $rawPath,
        ]);

        foreach ($email->attachments as $file) {
            $this->storeAttachment($message, $file);
        }

        $ticket->forceFill(['last_customer_message_at' => now()])->save();

        return $message;
    }

    private function storeAttachment(TicketMessage $message, array $file): Attachment
    {
        $path = sprintf('tenants/%d/attachments/%s/%s', $message->tenant_id, now()->format('Y/m'), Str::uuid());
        Storage::disk('local')->put($path, $file['content']);

        return $message->attachments()->create([
            'tenant_id' => $message->tenant_id,
            'filename' => $file['filename'],
            'mime_type' => $file['mime'],
            'size' => strlen($file['content']),
            'disk' => 'local',
            'path' => $path,
            'content_id' => $file['content_id'],
            'inline' => $file['inline'],
            'sha256' => hash('sha256', $file['content']),
        ]);
    }

    private function storeRaw(Mailbox $mailbox, string $raw): string
    {
        $path = sprintf('tenants/%d/raw/%s/%s.eml', $mailbox->tenant_id, now()->format('Y/m'), Str::uuid());
        Storage::disk('local')->put($path, $raw);

        return $path;
    }

    private function receipt(
        Mailbox $mailbox, string $hash, ?ParsedEmail $email, ?Ticket $ticket, string $outcome,
        ?string $note = null, ?string $rawPath = null,
    ): MailReceipt {
        return MailReceipt::query()->firstOrCreate(
            ['mailbox_id' => $mailbox->getKey(), 'message_hash' => $hash],
            [
                'tenant_id' => $mailbox->tenant_id,
                'message_id' => $email?->messageId,
                'from_email' => $email?->fromEmail ? mb_substr($email->fromEmail, 0, 250) : null,
                'subject' => $email ? mb_substr($email->subject, 0, 250) : null,
                'outcome' => $outcome,
                'ticket_id' => $ticket?->getKey(),
                'note' => $note ? mb_substr($note, 0, 2000) : null,
                'raw_path' => $rawPath,
            ],
        );
    }

    /** Entfernt "Re:", "AW:", "WG:" … am Anfang des Betreffs. */
    public static function cleanSubject(string $subject): string
    {
        $clean = preg_replace('/^\s*((re|aw|wg|fw|fwd|antw|sv|vs)\s*(\[\d+\])?\s*:\s*)+/iu', '', $subject);

        return trim($clean ?? $subject) ?: '(ohne Betreff)';
    }
}
