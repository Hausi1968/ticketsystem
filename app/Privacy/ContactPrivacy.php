<?php

namespace App\Privacy;

use App\Enums\MessageType;
use App\Models\Contact;
use App\Models\MailReceipt;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Datenschutz (DSGVO Art. 15 und 17): alle Daten zu einem Kontakt herausgeben bzw. löschen.
 * Gelöscht wird durch Anonymisieren: Die Tickets bleiben für Statistik und Verlauf erhalten,
 * aber ohne Namen, Adresse, Texte und Anhänge des Kontakts.
 */
class ContactPrivacy
{
    public const REMOVED = '[gelöscht auf Wunsch des Kontakts]';

    /** @return array<string, mixed> */
    public function export(Contact $contact): array
    {
        $tickets = $this->tickets($contact)->with(['status', 'category', 'tenant'])->get();

        return [
            'exportiert_am' => now()->toIso8601String(),
            'kontakt' => [
                'name' => $contact->name,
                'email' => $contact->email,
                'kennung_in_anwendung' => $contact->external_user_id,
                'mandant' => $contact->tenant?->name,
                'angelegt_am' => $contact->created_at?->toIso8601String(),
            ],
            'tickets' => $tickets->map(fn (Ticket $t) => [
                'nummer' => $t->reference(),
                'betreff' => $t->subject,
                'status' => $t->status?->name,
                'kategorie' => $t->category?->name,
                'eingang' => $t->channel,
                'angelegt_am' => $t->created_at?->toIso8601String(),
                'technische_angaben' => $t->context,
                'nachrichten' => $t->messages()->with('attachments', 'user')->get()->map(fn (TicketMessage $m) => [
                    'art' => match ($m->type) {
                        MessageType::Customer => 'vom Kontakt',
                        MessageType::Reply => 'Antwort des Teams',
                        MessageType::Auto => 'automatische Nachricht',
                        MessageType::Note => 'interne Notiz',
                        default => (string) $m->type?->value,
                    },
                    'von' => $m->type === MessageType::Customer ? ($m->from_name ?: $m->from_email) : ($m->user?->name ?? $m->from_name),
                    'zeit' => $m->created_at?->toIso8601String(),
                    'text' => $m->body_text,
                    'anhaenge' => $m->attachments->pluck('filename')->values()->all(),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /** Anonymisiert den Kontakt und seine Spuren in allen Tickets. Liefert die Zahl betroffener Tickets. */
    public function anonymize(Contact $contact, ?User $actor = null): int
    {
        return DB::transaction(function () use ($contact, $actor) {
            $email = $contact->email;
            $needles = array_values(array_filter([$email, $contact->name], fn ($v) => is_string($v) && mb_strlen($v) >= 3));
            $tickets = $this->tickets($contact)->get();

            foreach ($tickets as $ticket) {
                foreach ($ticket->messages()->with('attachments')->get() as $message) {
                    if ((int) $message->contact_id === (int) $contact->getKey()) {
                        // Nachrichten des Kontakts: Inhalt, Anhänge und Original-E-Mail löschen
                        $message->attachments->each->delete();
                        $this->deleteRaw($message->raw_path);
                        $message->forceFill([
                            'body_text' => self::REMOVED, 'body_html' => null, 'from_email' => null, 'from_name' => null,
                            'to' => null, 'cc' => null, 'raw_path' => null, 'subject' => null,
                        ])->save();

                        continue;
                    }

                    // Antworten und Notizen: Adresse und Name des Kontakts herausnehmen
                    $message->forceFill([
                        'body_text' => $this->scrub($message->body_text, $needles),
                        'body_html' => $this->scrub($message->body_html, $needles),
                        'to' => $this->scrubRecipients($message->to, $email),
                        'cc' => $this->scrubRecipients($message->cc, $email),
                    ])->save();
                }

                $updates = ['subject' => $this->scrub($ticket->subject, $needles)];
                if ((int) $ticket->contact_id === (int) $contact->getKey()) {
                    $updates['context'] = null; // technische Angaben der Anwendung (Benutzername, Browser …)
                }
                $ticket->forceFill($updates)->saveQuietly();
                $ticket->participants()->detach($contact->getKey());
                $ticket->logEvent('anonymized', $actor);
            }

            MailReceipt::query()->where('from_email', $email)->get()->each(function (MailReceipt $receipt) {
                $this->deleteRaw($receipt->raw_path);
                $receipt->forceFill(['from_email' => null, 'subject' => null, 'raw_path' => null])->save();
            });

            $contact->forceFill([
                'name' => null,
                'email' => 'geloescht-'.$contact->getKey().'@anonym.invalid',
                'external_user_id' => null,
            ])->save();

            return $tickets->count();
        });
    }

    /** Tickets, die der Kontakt angelegt hat oder an denen er beteiligt ist */
    private function tickets(Contact $contact)
    {
        return Ticket::query()
            ->where(fn ($query) => $query->where('contact_id', $contact->getKey())
                ->orWhereHas('participants', fn ($q) => $q->whereKey($contact->getKey()))
                ->orWhereHas('messages', fn ($q) => $q->where('contact_id', $contact->getKey())))
            ->orderBy('id');
    }

    private function scrub(?string $text, array $needles): ?string
    {
        if ($text === null || $needles === []) {
            return $text;
        }

        return str_ireplace($needles, '[entfernt]', $text);
    }

    private function scrubRecipients(?array $list, string $email): ?array
    {
        if (! $list) {
            return $list;
        }

        return array_values(array_filter($list, fn ($r) => mb_strtolower((string) ($r['email'] ?? '')) !== $email));
    }

    private function deleteRaw(?string $path): void
    {
        if ($path) {
            Storage::disk('local')->delete($path);
        }
    }
}
