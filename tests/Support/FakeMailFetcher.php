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
        }

        return count($batch);
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
