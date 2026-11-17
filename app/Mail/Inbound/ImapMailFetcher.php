<?php

namespace App\Mail\Inbound;

use App\Models\Mailbox;
use RuntimeException;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

class ImapMailFetcher implements MailFetcher
{
    public function fetch(Mailbox $mailbox, int $limit, callable $handler): int
    {
        $client = $this->connect($mailbox);

        try {
            $folder = $client->getFolderByPath($mailbox->imap_folder ?: 'INBOX')
                ?? throw new RuntimeException("Ordner „{$mailbox->imap_folder}“ nicht gefunden.");

            if ($mailbox->marksSeenOnResolve()) {
                return $this->fetchKeepingUnread($mailbox, $folder, $limit, $handler);
            }

            $messages = $folder->query()
                ->unseen()
                ->when($mailbox->import_since, fn ($q, $since) => $q->whereSince($since))
                ->leaveUnread()          // erst nach erfolgreicher Verarbeitung als gelesen markieren
                ->setFetchOrder('asc')   // älteste zuerst, damit Antworten nach dem Ursprung kommen
                ->limit($limit)
                ->get();

            $count = 0;
            foreach ($messages as $message) {
                $handler($this->raw($message));
                $count++;

                if ($mailbox->processed_folder) {
                    $message->move($mailbox->processed_folder);
                } else {
                    $message->setFlag('Seen');
                }
            }

            return $count;
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Abruf, ohne das Gelesen-Kennzeichen anzufassen: Anliego merkt sich die höchste abgeholte UID
     * des Ordners und holt beim nächsten Mal nur E-Mails mit größerer UID. So kommen auch E-Mails an,
     * die jemand in Outlook schon gelesen hat, und keine wird doppelt abgeholt.
     */
    private function fetchKeepingUnread(Mailbox $mailbox, Folder $folder, int $limit, callable $handler): int
    {
        $status = $folder->status(); // STATUS ändert die Ordnerauswahl nicht (anders als EXAMINE)
        $uidValidity = (int) ($status['uidvalidity'] ?? 0);

        if ($mailbox->imap_last_uid === null || (int) $mailbox->imap_uidvalidity !== $uidValidity) {
            // Erster Abruf in dieser Einstellung: ab der ältesten ungelesenen E-Mail, sonst nur neue
            $firstUnseen = $folder->query()->unseen()
                ->when($mailbox->import_since, fn ($q, $since) => $q->whereSince($since))
                ->leaveUnread()->setFetchBody(false)
                ->setFetchOrder('asc')->limit(1)->get()->first()?->getUid();
            $start = $firstUnseen ? (int) $firstUnseen - 1 : max(0, (int) ($status['uidnext'] ?? 1) - 1);

            $mailbox->forceFill(['imap_uidvalidity' => $uidValidity, 'imap_last_uid' => $start])->saveQuietly();
        }

        $lastUid = (int) $mailbox->imap_last_uid;

        // „UID n:*“ liefert immer mindestens die letzte E-Mail, auch wenn ihre UID kleiner ist – deshalb filtern
        $messages = $folder->query()
            ->where('CUSTOM UID '.($lastUid + 1).':*') // Webklex würde den Bereich sonst in Anführungszeichen setzen
            ->when($mailbox->import_since, fn ($q, $since) => $q->whereSince($since))
            ->leaveUnread()
            ->setFetchOrder('asc')
            ->limit($limit)
            ->get()
            ->filter(fn (Message $m) => (int) $m->getUid() > $lastUid)
            ->sortBy(fn (Message $m) => (int) $m->getUid());

        $count = 0;
        foreach ($messages as $message) {
            $handler($this->raw($message));
            $count++;

            $mailbox->forceFill(['imap_last_uid' => (int) $message->getUid()])->saveQuietly();
        }

        return $count;
    }

    public function setSeen(Mailbox $mailbox, array $messageIds, bool $seen): int
    {
        $messageIds = array_values(array_unique(array_filter($messageIds)));
        if ($messageIds === []) {
            return 0;
        }

        $client = $this->connect($mailbox);

        try {
            $folders = array_values(array_unique(array_filter([$mailbox->imap_folder ?: 'INBOX', $mailbox->processed_folder])));
            $changed = 0;

            foreach ($folders as $path) {
                $folder = $client->getFolderByPath($path);
                if (! $folder) {
                    continue;
                }

                foreach ($messageIds as $messageId) {
                    $found = $folder->query()->where('CUSTOM HEADER Message-ID "'.addcslashes($messageId, '"\\').'"')
                        ->leaveUnread()->setFetchBody(false)->get();

                    foreach ($found as $message) {
                        $seen ? $message->setFlag('Seen') : $message->unsetFlag('Seen');
                        $changed++;

                        // Erledigte E-Mails wie beim normalen Abruf in den Ordner für verarbeitete verschieben
                        if ($seen && $mailbox->processed_folder && $path !== $mailbox->processed_folder) {
                            $message->move($mailbox->processed_folder);
                        }
                    }
                }
            }

            return $changed;
        } finally {
            $client->disconnect();
        }
    }

    private function raw(Message $message): string
    {
        return rtrim($message->getHeader()->raw)."\r\n\r\n".$message->getRawBody();
    }

    public function test(Mailbox $mailbox): string
    {
        $client = $this->connect($mailbox);

        try {
            $folder = $client->getFolderByPath($mailbox->imap_folder ?: 'INBOX')
                ?? throw new RuntimeException("Verbindung ok, aber Ordner „{$mailbox->imap_folder}“ nicht gefunden.");

            if ($mailbox->processed_folder && ! $client->getFolderByPath($mailbox->processed_folder)) {
                throw new RuntimeException("Verbindung ok, aber Ordner „{$mailbox->processed_folder}“ für verarbeitete E-Mails nicht gefunden.");
            }

            if ($mailbox->sent_folder && ! $client->getFolderByPath($mailbox->sent_folder)) {
                throw new RuntimeException("Verbindung ok, aber Ordner „{$mailbox->sent_folder}“ für gesendete Antworten nicht gefunden. Den genauen Namen zeigt Outlook in der Ordnerliste.");
            }

            $unseen = $folder->query()->unseen()->leaveUnread()->setFetchBody(false)->count();

            return "Verbindung erfolgreich. Ungelesene E-Mails in {$folder->path}: {$unseen}";
        } finally {
            $client->disconnect();
        }
    }

    public function append(Mailbox $mailbox, string $folder, string $raw): void
    {
        $client = $this->connect($mailbox);

        try {
            $target = $client->getFolderByPath($folder)
                ?? throw new RuntimeException("Ordner „{$folder}“ nicht gefunden.");
            $target->appendMessage($raw, ['\\Seen']);
        } finally {
            $client->disconnect();
        }
    }

    private function connect(Mailbox $mailbox): Client
    {
        $client = (new ClientManager)->make([
            'host' => $mailbox->imap_host,
            'port' => $mailbox->imap_port,
            'encryption' => match ($mailbox->imap_encryption) {
                'ssl' => 'ssl',
                'tls' => 'tls',
                default => false,
            },
            'validate_cert' => true,
            'username' => $mailbox->imap_username,
            'password' => $mailbox->imap_password,
            'protocol' => 'imap',
            'timeout' => 30,
            'options' => [
                'decoder' => ['message' => 'mimeheader', 'attachment' => 'mimeheader'],
            ],
        ]);

        $client->connect();

        return $client;
    }
}
