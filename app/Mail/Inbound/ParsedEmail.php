<?php

namespace App\Mail\Inbound;

use Carbon\CarbonImmutable;

/** Eine eingegangene E-Mail in aufbereiteter Form, unabhängig von der IMAP-Bibliothek. */
final class ParsedEmail
{
    /**
     * @param  list<string>  $references
     * @param  list<array{email: string, name: ?string}>  $to
     * @param  list<array{email: string, name: ?string}>  $cc
     * @param  array<string, string>  $headers  Kopfzeilen, Namen klein geschrieben (bei Mehrfachnennung die erste)
     * @param  list<array{filename: string, mime: ?string, content: string, content_id: ?string, inline: bool}>  $attachments
     */
    public function __construct(
        public readonly string $raw,
        public readonly ?string $messageId,
        public readonly ?string $inReplyTo,
        public readonly array $references,
        public readonly ?string $fromEmail,
        public readonly ?string $fromName,
        public readonly array $to,
        public readonly array $cc,
        public readonly string $subject,
        public readonly ?string $text,
        public readonly ?string $html,
        public readonly ?CarbonImmutable $date,
        public readonly array $headers,
        public readonly array $attachments,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Eindeutiger Schlüssel für den Dublettenschutz. */
    public function hash(): string
    {
        return hash('sha256', $this->messageId !== null ? 'mid:'.$this->messageId : 'raw:'.$this->raw);
    }

    /** Alle Message-IDs, auf die sich diese E-Mail bezieht (neueste zuerst). */
    public function referencedIds(): array
    {
        return array_values(array_unique(array_filter([
            $this->inReplyTo,
            ...array_reverse($this->references),
        ])));
    }
}
