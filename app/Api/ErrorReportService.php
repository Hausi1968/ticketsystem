<?php

namespace App\Api;

use App\Enums\MessageType;
use App\Enums\TicketPriority;
use App\Models\ApiKey;
use App\Models\Contact;
use App\Models\Mailbox;
use App\Models\Ticket;
use App\Tickets\RuleEngine;
use App\Tickets\TicketNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Automatische Fehlermeldungen einer Anwendung (z. B. PHP-Fehler der Mitgliederverwaltung).
 * Gleiche Fehler werden über einen Fingerabdruck zusammengefasst: Solange ein Ticket dazu offen
 * ist, wird nur gezählt (und höchstens stündlich eine Notiz ergänzt). Ist es geschlossen,
 * entsteht ein neues Ticket mit Verweis auf das alte.
 */
class ErrorReportService
{
    public const CHANNEL = 'error';

    /** Notiz zu Wiederholungen höchstens so oft */
    private const NOTE_EVERY_MINUTES = 60;

    public function __construct(private readonly TicketNotifier $notifier) {}

    /** @return array{0: Ticket, 1: bool} [Ticket, neu angelegt?] */
    public function report(ApiKey $key, array $data): array
    {
        $tenantId = (int) $key->tenant_id;
        $fingerprint = self::fingerprint($data);

        return DB::transaction(function () use ($key, $data, $tenantId, $fingerprint) {
            $open = Ticket::query()->where('error_fingerprint', $fingerprint)
                ->whereNull('merged_into_id')->unresolved()->lockForUpdate()->latest('id')->first();

            if ($open) {
                $this->repeat($open, $data);

                return [$open, false];
            }

            $previous = Ticket::query()->where('error_fingerprint', $fingerprint)->latest('id')->first();

            return [$this->create($key, $tenantId, $fingerprint, $data, $previous), true];
        });
    }

    public static function fingerprint(array $data): string
    {
        if (filled($data['fingerprint'] ?? null)) {
            return substr(hash('sha256', 'fp:'.$data['fingerprint']), 0, 64);
        }

        // Zahlen und Kennungen aus der Meldung entfernen, damit z. B. „Mitglied 17“ und „Mitglied 18“ gleich sind
        $message = preg_replace('/\d+/', '#', (string) ($data['message'] ?? ''));

        return hash('sha256', implode('|', [$data['type'] ?? '', $message, $data['file'] ?? '', $data['line'] ?? '']));
    }

    private function create(ApiKey $key, int $tenantId, string $fingerprint, array $data, ?Ticket $previous): Ticket
    {
        $contact = $this->systemContact($tenantId, $data['source'] ?? $key->name);
        $subject = 'Fehler: '.Str::limit(trim(preg_replace('/\s+/', ' ', (string) $data['message'])), 150);

        $ticket = Ticket::create([
            'tenant_id' => $tenantId,
            'subject' => $subject,
            'channel' => self::CHANNEL,
            'priority' => self::priorityFor($data['level'] ?? 'error'),
            'category_id' => app(ApiTicketService::class)->categoryFor($tenantId, $data['category'] ?? null)?->getKey(),
            'mailbox_id' => $key->mailbox_id ?? Mailbox::query()->where('active', true)->orderBy('id')->value('id'),
            'team_id' => $key->team_id,
            'contact_id' => $contact->getKey(),
            'api_key_id' => $key->getKey(),
            'follow_up_of_id' => $previous?->getKey(),
            'error_fingerprint' => $fingerprint,
            'error_count' => 1,
            'error_last_at' => now(),
            'context' => array_filter([
                'url' => $data['url'] ?? null,
                'app_version' => $data['app_version'] ?? null,
                'php' => $data['php_version'] ?? null,
            ]) ?: null,
        ]);

        $ticket->messages()->create([
            'tenant_id' => $tenantId,
            'type' => MessageType::Customer,
            'contact_id' => $contact->getKey(),
            'from_email' => $contact->email,
            'from_name' => $contact->name,
            'subject' => $subject,
            'body_text' => $this->describe($data, $previous),
        ]);

        $ticket->logEvent('created', data: ['channel' => self::CHANNEL, 'api_key' => $key->name]);
        app(RuleEngine::class)->apply($ticket);
        $this->notifier->ticketCreated($ticket);

        return $ticket;
    }

    private function repeat(Ticket $ticket, array $data): void
    {
        $ticket->forceFill(['error_count' => $ticket->error_count + 1, 'error_last_at' => now()])->saveQuietly();

        $since = now()->subMinutes(self::NOTE_EVERY_MINUTES);
        $noteDue = $ticket->created_at->lt($since)
            && $ticket->messages()->where('type', MessageType::Note)->whereNull('user_id')->where('created_at', '>=', $since)->doesntExist();

        if ($noteDue) {
            $ticket->messages()->create([
                'tenant_id' => $ticket->tenant_id,
                'type' => MessageType::Note,
                'body_text' => "Erneut aufgetreten (insgesamt {$ticket->error_count}×).\n\n".$this->describe($data, null),
            ]);
        }
    }

    private function describe(array $data, ?Ticket $previous): string
    {
        $lines = array_filter([
            'Meldung: '.($data['message'] ?? '–'),
            isset($data['type']) ? 'Art: '.$data['type'] : null,
            'Stufe: '.($data['level'] ?? 'error'),
            isset($data['file']) ? 'Datei: '.$data['file'].(isset($data['line']) ? ':'.$data['line'] : '') : null,
            isset($data['url']) ? 'Seite: '.(isset($data['method']) ? $data['method'].' ' : '').$data['url'] : null,
            isset($data['user']) ? 'Benutzer: '.$data['user'] : null,
            isset($data['app_version']) ? 'Version: '.$data['app_version'] : null,
            isset($data['php_version']) ? 'PHP: '.$data['php_version'] : null,
            'Zeitpunkt: '.($data['occurred_at'] ?? now()->toIso8601String()),
            $previous ? 'Früher schon aufgetreten: '.$previous->reference().' ('.$previous->error_count.'×)' : null,
        ]);

        $text = implode("\n", $lines);
        if (filled($data['trace'] ?? null)) {
            $text .= "\n\nAufrufkette:\n".Str::limit((string) $data['trace'], 8000);
        }

        return $text;
    }

    private static function priorityFor(string $level): TicketPriority
    {
        return match (strtolower($level)) {
            'emergency', 'alert', 'critical', 'fatal' => TicketPriority::Urgent,
            'error' => TicketPriority::High,
            default => TicketPriority::Normal,
        };
    }

    /** Ein Kontakt je Mandant und Quelle; .invalid-Adresse, damit nie eine E-Mail dorthin geht */
    private function systemContact(int $tenantId, string $source): Contact
    {
        $email = 'fehler-'.Str::slug($source ?: 'anwendung').'@anliego.invalid';

        return Contact::query()->firstOrCreate(
            ['email' => $email],
            ['tenant_id' => $tenantId, 'name' => Str::limit($source ?: 'Anwendung', 60).' (automatische Fehlermeldung)'],
        );
    }
}
