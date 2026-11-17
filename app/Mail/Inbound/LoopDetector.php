<?php

namespace App\Mail\Inbound;

use App\Models\Mailbox;

/**
 * Erkennt E-Mails, aus denen kein Ticket werden soll: Abwesenheitsnotizen und andere
 * automatische Antworten (sonst Mail-Schleifen), Unzustellbarkeitsmeldungen und
 * E-Mails aus eigenen Postfächern.
 */
class LoopDetector
{
    public const LOOP = 'ignored_loop';

    public const BOUNCE = 'ignored_bounce';

    public const OWN = 'ignored_own';

    public const BLOCKED = 'ignored_blocked';

    public const NEWSLETTER = 'ignored_newsletter';

    public const TOO_OLD = 'ignored_old';

    public function classify(ParsedEmail $email, Mailbox $mailbox): ?string
    {
        $from = (string) $email->fromEmail;

        if ($this->isBounce($email, $from)) {
            return self::BOUNCE;
        }

        if ($from !== '' && Mailbox::query()->withoutGlobalScopes()->where('email', $from)->exists()) {
            return self::OWN;
        }

        if ($email->header('x-anliego-ticket') !== null) {
            return self::OWN;
        }

        if ($mailbox->blocksSender($from)) {
            return self::BLOCKED;
        }

        if ($this->isAutomatic($email, $from)) {
            return self::LOOP;
        }

        if ($mailbox->ignore_newsletters && $this->isNewsletter($email)) {
            return self::NEWSLETTER;
        }

        return null;
    }

    /** Newsletter und Massen-Mails (nur wenn im Postfach eingeschaltet – Webshop-Bestellungen sehen manchmal ähnlich aus) */
    private function isNewsletter(ParsedEmail $email): bool
    {
        return $email->header('list-unsubscribe') !== null
            || $email->header('list-id') !== null
            || in_array(strtolower(trim((string) $email->header('precedence'))), ['bulk', 'list', 'junk'], true);
    }

    private function isBounce(ParsedEmail $email, string $from): bool
    {
        $contentType = strtolower((string) $email->header('content-type'));

        return str_contains($contentType, 'multipart/report')
            || preg_match('/^(mailer-daemon|postmaster)@/i', $from) === 1
            || trim((string) $email->header('return-path')) === '<>';
    }

    /**
     * Automatische Antworten (Abwesenheitsnotizen, Autoresponder). Sie würden sonst Tickets
     * wieder öffnen oder neue anlegen und im schlimmsten Fall eine Mail-Schleife auslösen.
     *
     * Bewusst NICHT ignoriert: Absender wie noreply@… oder Precedence: bulk. Bestell- und
     * Formular-E-Mails aus Webshops kommen oft genau so und sollen trotzdem Tickets werden.
     */
    private function isAutomatic(ParsedEmail $email, string $from): bool
    {
        $autoSubmitted = strtolower((string) $email->header('auto-submitted'));
        if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return true;
        }

        if ($email->header('x-autoreply') !== null || $email->header('x-autorespond') !== null) {
            return true;
        }

        if (in_array(strtolower((string) $email->header('precedence')), ['auto_reply'], true)) {
            return true;
        }

        return preg_match(self::AUTO_REPLY_SUBJECT, $email->subject) === 1;
    }

    /** Betreff-Präfixe gängiger Abwesenheitsnotizen (Outlook, Gmail, Thunderbird …) */
    private const AUTO_REPLY_SUBJECT = '/^\s*(automatische antwort|abwesenheitsnotiz|abwesenheit|out of office|automatic reply|auto(matic)?[- ]?reply|autoreply|réponse automatique)\b/iu';
}
