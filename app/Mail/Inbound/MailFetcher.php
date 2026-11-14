<?php

namespace App\Mail\Inbound;

use App\Models\Mailbox;

interface MailFetcher
{
    /**
     * Ruft bis zu $limit ungelesene E-Mails ab und übergibt jede als Rohtext an $handler.
     * Nach erfolgreicher Übergabe wird die E-Mail als gelesen markiert bzw. verschoben.
     *
     * @param  callable(string $raw): mixed  $handler
     * @return int Anzahl verarbeiteter E-Mails
     */
    public function fetch(Mailbox $mailbox, int $limit, callable $handler): int;

    /** Prüft die IMAP-Verbindung; wirft eine Exception mit verständlicher Meldung bei Fehlern. */
    public function test(Mailbox $mailbox): string;
}
