<?php

namespace Tests\Support;

use App\Mail\Inbound\MailFetcher;
use App\Models\Mailbox;
use RuntimeException;

class FakeMailFetcher implements MailFetcher
{
    /** @var array<int, list<string>> */
    public array $inbox = [];

    /** @var list<int> */
    public array $polled = [];

    public ?string $failWith = null;

    /** @var list<array{mailbox: int, folder: string, raw: string}> */
    public array $appended = [];

    /** Beim Abruf als gelesen markierte E-Mails je Postfach (Rohtext) */
    public array $markedOnFetch = [];

    /** @var list<array{mailbox: int, ids: list<string>, seen: bool}> */
    public array $seenChanges = [];

    public function push(Mailbox $mailbox, string ...$raws): static
    {
        $this->inbox[$mailbox->id] = [...($this->inbox[$mailbox->id] ?? []), ...$raws];

        return $this;
    }

    public function fetch(Mailbox $mailbox, int $limit, callable $handler): int
    {
        $this->polled[] = $mailbox->id;

        if ($this->failWith) {
            throw new RuntimeException($this->failWith);
        }

        $this->inbox[$mailbox->id] ??= [];
        $batch = array_splice($this->inbox[$mailbox->id], 0, $limit);
        foreach ($batch as $raw) {
            $handler($raw);
            if (! $mailbox->marksSeenOnResolve()) {
                $this->markedOnFetch[$mailbox->id][] = $raw;
            }
        }

        return count($batch);
    }

    public function setSeen(Mailbox $mailbox, array $messageIds, bool $seen): int
    {
        sort($messageIds);
        $this->seenChanges[] = ['mailbox' => $mailbox->id, 'ids' => $messageIds, 'seen' => $seen];

        return count($messageIds);
    }

    public function test(Mailbox $mailbox): string
    {
        if ($this->failWith) {
            throw new RuntimeException($this->failWith);
        }

        return 'Verbindung erfolgreich.';
    }

    public function append(Mailbox $mailbox, string $folder, string $raw): void
    {
        $this->appended[] = ['mailbox' => $mailbox->id, 'folder' => $folder, 'raw' => $raw];
    }
}
