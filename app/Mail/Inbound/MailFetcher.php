<?php

namespace App\Mail\Inbound;

use App\Models\Mailbox;

interface MailFetcher
{
    /**
     * Ruft bis zu $limit neue E-Mails ab und übergibt jede als Rohtext an $handler.
     * Normalerweise wird die E-Mail danach als gelesen markiert bzw. verschoben. Steht das Postfach auf
     * „erst wenn das Ticket gelöst ist“, bleibt sie unverändert; dann markiert setSeen() sie später.
     *
     * @param  callable(string $raw): mixed  $handler
     * @return int Anzahl verarbeiteter E-Mails
     */
    public function fetch(Mailbox $mailbox, int $limit, callable $handler): int;

    /**
     * Setzt (oder entfernt) das Gelesen-Kennzeichen der E-Mails mit diesen Message-IDs (ohne <>) im
     * Eingangsordner und im Ordner für verarbeitete E-Mails. Beim Setzen werden sie, falls eingestellt,
     * in den Ordner für verarbeitete E-Mails verschoben.
     *
     * @param  list<string>  $messageIds
     * @return int Anzahl geänderter E-Mails
     */
    public function setSeen(Mailbox $mailbox, array $messageIds, bool $seen): int;

    /** Prüft die IMAP-Verbindung; wirft eine Exception mit verständlicher Meldung bei Fehlern. */
    public function test(Mailbox $mailbox): string;

    /** Legt eine versendete E-Mail (Rohtext) im angegebenen IMAP-Ordner als gelesen ab. */
    public function append(Mailbox $mailbox, string $folder, string $raw): void;
}
