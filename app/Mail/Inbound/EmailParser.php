<?php

namespace App\Mail\Inbound;

use Carbon\CarbonImmutable;
use Throwable;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Message;

/** Zerlegt eine Roh-E-Mail (RFC 822) in ein ParsedEmail. */
class EmailParser
{
    public function parse(string $raw): ParsedEmail
    {
        $message = Message::fromString($raw, self::config());
        $headers = self::rawHeaders($raw);

        $from = $message->getFrom()->first();

        $attachments = [];
        foreach ($message->getAttachments() as $attachment) {
            $disposition = strtolower((string) $attachment->getDisposition());
            $contentId = trim((string) ($attachment->id ?? ''), '<> ') ?: null;

            $attachments[] = [
                'filename' => self::safeFilename((string) ($attachment->getName() ?: 'anhang')),
                'mime' => $attachment->getMimeType() ?: null,
                'content' => (string) $attachment->getContent(),
                'content_id' => $contentId,
                'inline' => $disposition === 'inline' && $contentId !== null,
            ];
        }

        $references = $message->getReferences()->toArray();

        return new ParsedEmail(
            raw: $raw,
            messageId: self::cleanId((string) $message->getMessageId()),
            inReplyTo: self::cleanId((string) $message->getInReplyTo()),
            references: array_values(array_filter(array_map(fn ($id) => self::cleanId((string) $id), is_array($references) ? $references : []))),
            fromEmail: $from?->mail ? mb_strtolower($from->mail) : null,
            fromName: self::cleanName($from?->personal ?? null),
            to: self::addresses($message->getTo()->all()),
            cc: self::addresses($message->getCc()->all()),
            subject: trim((string) $message->getSubject()) ?: '(ohne Betreff)',
            text: $message->hasTextBody() ? $message->getTextBody() : null,
            html: $message->hasHTMLBody() ? $message->getHTMLBody() : null,
            date: self::date($message),
            headers: $headers,
            attachments: $attachments,
        );
    }

    public static function config(): Config
    {
        return Config::make(['decoding' => ['options' => [
            'header' => 'mimeheader', 'message' => 'mimeheader', 'attachment' => 'mimeheader',
        ]]]);
    }

    /** Kopfzeilen selbst lesen, damit Schleifen-Header unabhängig von der Bibliothek geprüft werden. */
    public static function rawHeaders(string $raw): array
    {
        $block = preg_split("/\r?\n\r?\n/", $raw, 2)[0] ?? '';
        $block = preg_replace("/\r?\n[ \t]+/", ' ', $block); // gefaltete Zeilen zusammenführen

        $headers = [];
        foreach (preg_split("/\r?\n/", $block) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] ??= trim($value);
        }

        return $headers;
    }

    private static function addresses(array $list): array
    {
        $result = [];
        foreach ($list as $address) {
            if (! empty($address->mail) && str_contains($address->mail, '@')) {
                $result[] = ['email' => mb_strtolower($address->mail), 'name' => self::cleanName($address->personal ?? null)];
            }
        }

        return $result;
    }

    private static function cleanId(string $id): ?string
    {
        $id = trim($id, " \t\r\n<>");

        return $id === '' ? null : mb_substr($id, 0, 250);
    }

    private static function cleanName(?string $name): ?string
    {
        $name = trim((string) $name, " \t\"'");

        return $name === '' ? null : $name;
    }

    private static function safeFilename(string $name): string
    {
        $name = str_replace(['/', '\\', "\0"], '_', $name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? 'anhang';

        return mb_substr(trim($name) ?: 'anhang', 0, 200);
    }

    private static function date(Message $message): ?CarbonImmutable
    {
        try {
            $date = $message->getDate()->first();

            return $date ? CarbonImmutable::instance($date) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
