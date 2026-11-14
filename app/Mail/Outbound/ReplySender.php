<?php

namespace App\Mail\Outbound;

use App\Enums\MessageType;
use App\Jobs\DeliverTicketMessage;
use App\Models\Attachment;
use App\Models\Mailbox;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Antworten und interne Notizen. Antworten gehen über das SMTP-Postfach des Tickets, mit
 * Message-ID/In-Reply-To/References, damit sie beim Kunden im selben Gesprächsverlauf landen
 * und seine Antwort wieder dem Ticket zugeordnet wird.
 */
class ReplySender
{
    public function __construct(private readonly MailboxTransportFactory $transports) {}

    /**
     * @param  list<string>  $cc
     * @param  list<array{path: string, name: string, mime?: ?string}>  $files  Dateien auf der lokalen Disk
     */
    public function reply(Ticket $ticket, User $agent, string $body, array $cc = [], array $files = [], ?TicketStatus $newStatus = null): TicketMessage
    {
        $mailbox = $this->mailboxFor($ticket);

        $message = DB::transaction(function () use ($ticket, $agent, $body, $cc, $files, $mailbox, $newStatus) {
            $message = $ticket->messages()->create([
                'tenant_id' => $ticket->tenant_id,
                'type' => MessageType::Reply,
                'user_id' => $agent->getKey(),
                'from_email' => $mailbox->email,
                'from_name' => $mailbox->displayName(),
                'to' => [['email' => $ticket->contact->email, 'name' => $ticket->contact->name]],
                'cc' => array_map(fn ($e) => ['email' => mb_strtolower(trim($e)), 'name' => null], $cc),
                'subject' => $this->subjectFor($ticket),
                'body_text' => $this->withSignature($ticket, $agent, $body),
                'message_id' => $this->newMessageId($ticket, $mailbox),
                'in_reply_to' => $ticket->messages()->whereNotNull('message_id')->where('type', MessageType::Customer)->reorder()->latest('id')->value('message_id'),
                'references' => $this->referencesFor($ticket),
                'delivery_status' => 'pending',
            ]);

            $this->attachFiles($message, $files);

            $ticket->forceFill([
                'last_agent_message_at' => now(),
                'first_response_at' => $ticket->first_response_at ?? now(),
            ])->save();

            if ($ticket->assignee_id === null) {
                $ticket->assignTo($agent, $agent);
            }

            $ticket->changeStatus($newStatus ?? TicketStatus::WAITING, $agent);

            return $message;
        });

        if (! $this->deliver($message)) {
            DeliverTicketMessage::dispatch($message->tenant_id, $message->getKey())->delay(now()->addMinutes(2));
        }

        return $message;
    }

    public function note(Ticket $ticket, User $agent, string $body, array $files = []): TicketMessage
    {
        return DB::transaction(function () use ($ticket, $agent, $body, $files) {
            $message = $ticket->messages()->create([
                'tenant_id' => $ticket->tenant_id,
                'type' => MessageType::Note,
                'user_id' => $agent->getKey(),
                'body_text' => $body,
            ]);

            $this->attachFiles($message, $files);

            return $message;
        });
    }

    /** Versendet eine gespeicherte Antwort. Gibt false zurück, wenn der Versand fehlschlug. */
    public function deliver(TicketMessage $message): bool
    {
        if ($message->delivery_status === 'sent') {
            return true;
        }

        $ticket = $message->ticket;

        try {
            $mailbox = $this->mailboxFor($ticket);
            $this->transports->for($mailbox)->send($this->buildEmail($message, $mailbox));

            $message->forceFill(['delivery_status' => 'sent', 'delivery_error' => null, 'sent_at' => now()])->save();

            return true;
        } catch (Throwable $e) {
            Log::warning('Antwort konnte nicht gesendet werden', ['message' => $message->getKey(), 'error' => $e->getMessage()]);
            $message->forceFill(['delivery_status' => 'failed', 'delivery_error' => mb_substr($e->getMessage(), 0, 2000)])->save();

            return false;
        }
    }

    public function buildEmail(TicketMessage $message, Mailbox $mailbox): Email
    {
        $email = (new Email)
            ->from(new Address($mailbox->email, $mailbox->displayName()))
            ->replyTo(new Address($mailbox->email, $mailbox->displayName()))
            ->subject((string) $message->subject)
            ->text((string) $message->body_text)
            ->html($this->htmlBody((string) $message->body_text));

        foreach ($message->to ?? [] as $recipient) {
            $email->addTo(new Address($recipient['email'], (string) ($recipient['name'] ?? '')));
        }
        foreach ($message->cc ?? [] as $recipient) {
            $email->addCc(new Address($recipient['email'], (string) ($recipient['name'] ?? '')));
        }

        $headers = $email->getHeaders();
        $headers->addIdHeader('Message-ID', $message->message_id);
        if ($message->in_reply_to) {
            $headers->addIdHeader('In-Reply-To', $message->in_reply_to);
        }
        if ($message->references) {
            $headers->addIdHeader('References', explode(' ', $message->references));
        }
        // Eigene Kennung: kommt diese E-Mail zurück in ein Postfach, wird sie nicht erneut verarbeitet
        $headers->addTextHeader('X-Anliego-Ticket', $message->ticket->reference());
        $headers->addTextHeader('Auto-Submitted', 'no');

        foreach ($message->attachments as $attachment) {
            $email->attach(Storage::disk($attachment->disk)->get($attachment->path), $attachment->filename, $attachment->mime_type);
        }

        return $email;
    }

    private function mailboxFor(Ticket $ticket): Mailbox
    {
        return $ticket->mailbox
            ?? Mailbox::query()->where('tenant_id', $ticket->tenant_id)->where('active', true)->orderBy('id')->first()
            ?? throw new RuntimeException('Für diesen Mandanten ist kein Postfach eingerichtet.');
    }

    private function subjectFor(Ticket $ticket): string
    {
        return 'Re: '.$ticket->subject.' '.$ticket->subjectToken();
    }

    private function newMessageId(Ticket $ticket, Mailbox $mailbox): string
    {
        $domain = Str::after($mailbox->email, '@') ?: 'anliego.local';

        return sprintf('anliego.%s.%s@%s', Str::lower($ticket->reference()), Str::lower(Str::random(16)), $domain);
    }

    private function referencesFor(Ticket $ticket): ?string
    {
        $ids = $ticket->messages()->whereNotNull('message_id')->pluck('message_id')->take(-20)->all();

        return $ids ? implode(' ', $ids) : null;
    }

    private function withSignature(Ticket $ticket, User $agent, string $body): string
    {
        $signature = DB::table('tenant_user')
            ->where('tenant_id', $ticket->tenant_id)->where('user_id', $agent->getKey())
            ->value('signature');

        return rtrim($body).($signature ? "\n\n-- \n".trim($signature) : '');
    }

    private function htmlBody(string $text): string
    {
        $html = nl2br(e($text), false);

        return '<!DOCTYPE html><html><body style="font-family: Arial, sans-serif; font-size: 14px; line-height: 1.5;">'.$html.'</body></html>';
    }

    private function attachFiles(TicketMessage $message, array $files): void
    {
        foreach ($files as $file) {
            $disk = Storage::disk('local');
            $target = sprintf('tenants/%d/attachments/%s/%s', $message->tenant_id, now()->format('Y/m'), Str::uuid());
            $disk->move($file['path'], $target);

            $message->attachments()->create([
                'tenant_id' => $message->tenant_id,
                'filename' => $file['name'],
                'mime_type' => $file['mime'] ?? $disk->mimeType($target) ?: null,
                'size' => $disk->size($target),
                'disk' => 'local',
                'path' => $target,
                'sha256' => hash('sha256', $disk->get($target)),
            ]);
        }
    }
}
