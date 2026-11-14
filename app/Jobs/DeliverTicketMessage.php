<?php

namespace App\Jobs;

use App\Mail\Outbound\ReplySender;
use App\Models\TicketMessage;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/** Wiederholt den Versand einer Antwort, wenn der SMTP-Server beim ersten Versuch nicht erreichbar war. */
class DeliverTicketMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $tenantId, public int $messageId) {}

    public function backoff(): array
    {
        return [120, 600, 1800, 3600];
    }

    public function handle(ReplySender $sender, TenantContext $tenancy): void
    {
        $tenancy->runFor($this->tenantId, function () use ($sender) {
            $message = TicketMessage::query()->find($this->messageId);

            if ($message && ! $sender->deliver($message)) {
                throw new RuntimeException('Versand erneut fehlgeschlagen: '.$message->delivery_error);
            }
        });
    }
}
