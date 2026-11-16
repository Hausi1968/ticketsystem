<?php

namespace App\Mail\Inbound;

use App\Models\Mailbox;
use RuntimeException;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;

class ImapMailFetcher implements MailFetcher
{
    public function fetch(Mailbox $mailbox, int $limit, callable $handler): int
    {
        $client = $this->connect($mailbox);

        try {
            $folder = $client->getFolderByPath($mailbox->imap_folder ?: 'INBOX')
                ?? throw new RuntimeException("Ordner „{$mailbox->imap_folder}“ nicht gefunden.");

            $messages = $folder->query()
                ->unseen()
                ->leaveUnread()          // erst nach erfolgreicher Verarbeitung als gelesen markieren
                ->setFetchOrder('asc')   // älteste zuerst, damit Antworten nach dem Ursprung kommen
                ->limit($limit)
                ->get();

            $count = 0;
            foreach ($messages as $message) {
                $raw = rtrim($message->getHeader()->raw)."\r\n\r\n".$message->getRawBody();

                $handler($raw);
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

    public function test(Mailbox $mailbox): string
    {
        $client = $this->connect($mailbox);

        try {
            $folder = $client->getFolderByPath($mailbox->imap_folder ?: 'INBOX')
                ?? throw new RuntimeException("Verbindung ok, aber Ordner „{$mailbox->imap_folder}“ nicht gefunden.");

            if ($mailbox->processed_folder && ! $client->getFolderByPath($mailbox->processed_folder)) {
                throw new RuntimeException("Verbindung ok, aber Ordner „{$mailbox->processed_folder}“ für verarbeitete E-Mails nicht gefunden.");
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
