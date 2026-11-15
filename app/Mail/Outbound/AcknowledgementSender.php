<?php

namespace App\Mail\Outbound;

use App\Enums\MessageType;
use App\Mail\Inbound\ParsedEmail;
use App\Models\Mailbox;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Eingangsbestätigung an den Anfragenden, wenn ein neues Ticket entsteht. Je Postfach ein- und
 * ausschaltbar, Text anpassbar. Mehrere Sperren verhindern E-Mail-Schleifen und Antworten an
 * Absender, die keine Antwort erwarten (z. B. Bestellungen von noreply@-Adressen eines Webshops).
 */
class AcknowledgementSender
{
    public const DEFAULT_TEXT = <<<'TXT'
        Guten Tag {name},

        vielen Dank für Ihre Nachricht. Wir haben Ihr Anliegen unter der Nummer {nummer} aufgenommen und melden uns so schnell wie möglich.

        Wenn Sie etwas ergänzen möchten, antworten Sie einfach auf diese E-Mail. Bitte lassen Sie die Nummer im Betreff stehen.

        Ihr Anliegen: {betreff}

        Viele Grüße
        {absender}
        TXT;

    /** Höchstens eine Bestätigung je Kontakt in diesem Zeitraum (Schutz vor Schleifen mit Autorespondern) */
    public const QUIET_MINUTES = 15;

    public function __construct(private readonly ReplySender $sender) {}

    /** Nach dem Speichern des Tickets senden; ein Fehler hier darf die Ticketanlage nie verhindern. */
    public function queueFor(Ticket $ticket, ?ParsedEmail $incoming = null): void
    {
        DB::afterCommit(function () use ($ticket, $incoming) {
            try {
                $this->sendFor($ticket, $incoming);
            } catch (Throwable $e) {
                Log::warning('Eingangsbestätigung nicht möglich', ['ticket' => $ticket->getKey(), 'error' => $e->getMessage()]);
            }
        });
    }

    public function sendFor(Ticket $ticket, ?ParsedEmail $incoming = null): ?TicketMessage
    {
        $ticket->loadMissing('mailbox', 'contact', 'tenant');
        $mailbox = $ticket->mailbox;
        $email = $ticket->contact?->email;

        if (! $mailbox?->send_acknowledgement || ! $mailbox->active || ! $email || $this->skipReason($ticket, $email, $incoming)) {
            return null;
        }

        return $this->sender->automatic($ticket, $this->render($ticket, $mailbox));
    }

    public function skipReason(Ticket $ticket, string $email, ?ParsedEmail $incoming): ?string
    {
        $local = strtolower(strstr($email, '@', true) ?: $email);

        if (preg_match('/^(no-?reply|do-?not-?reply|donotreply|mailer-daemon|postmaster|bounces?|notifications?)([+._-]|$)/', $local)) {
            return 'Absender erwartet keine Antwort';
        }

        if (Mailbox::query()->withoutGlobalScopes()->where('email', mb_strtolower($email))->exists()) {
            return 'Eigenes Postfach';
        }

        // Newsletter und Verteiler bekommen keine Bestätigung
        if ($incoming && (
            in_array(strtolower((string) $incoming->header('Precedence')), ['bulk', 'list', 'junk'], true)
            || $incoming->header('List-Id') !== null
            || $incoming->header('List-Unsubscribe') !== null
        )) {
            return 'Massen-E-Mail';
        }

        $recent = TicketMessage::query()
            ->where('type', MessageType::Auto)
            ->where('created_at', '>=', now()->subMinutes(self::QUIET_MINUTES))
            ->whereHas('ticket', fn ($query) => $query->where('contact_id', $ticket->contact_id))
            ->exists();

        return $recent ? 'Kürzlich schon bestätigt' : null;
    }

    public function render(Ticket $ticket, Mailbox $mailbox): string
    {
        $template = trim((string) $mailbox->acknowledgement_text) !== '' ? $mailbox->acknowledgement_text : self::DEFAULT_TEXT;

        $text = strtr($template, [
            '{name}' => (string) $ticket->contact?->name,
            '{nummer}' => $ticket->reference(),
            '{betreff}' => (string) $ticket->subject,
            '{mandant}' => (string) $ticket->tenant?->name,
            '{absender}' => $mailbox->displayName(),
        ]);

        // "Guten Tag ," ohne Namen
        return trim(preg_replace('/[ \t]+,/', ',', $text));
    }
}
