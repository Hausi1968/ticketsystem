<?php

namespace App\Console\Commands;

use App\Mail\Inbound\InboundMailProcessor;
use App\Mail\Inbound\MailFetcher;
use App\Models\Mailbox;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ruft alle fälligen Postfächer ab. Läuft jede Minute über den Scheduler; ob ein Postfach
 * dran ist, bestimmt sein Abrufintervall (z. B. alle 5 oder 10 Minuten).
 */
class PollMailboxes extends Command
{
    protected $signature = 'anliego:poll-mailboxes
        {--mailbox= : Nur dieses Postfach (ID)}
        {--force : Intervall ignorieren}
        {--limit=25 : Höchstens so viele E-Mails je Postfach und Lauf}';

    protected $description = 'Ruft neue E-Mails der fälligen Postfächer ab und legt Tickets an';

    public function handle(MailFetcher $fetcher, InboundMailProcessor $processor, TenantContext $tenancy): int
    {
        $mailboxes = $tenancy->runWithoutTenancy(fn () => Mailbox::query()
            ->where('active', true)
            ->where('usage', '!=', 'send')
            ->when($this->option('mailbox'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')
            ->get());

        $failed = 0;

        foreach ($mailboxes as $mailbox) {
            if (! $this->option('force') && ! $mailbox->isDue()) {
                continue;
            }

            $tenancy->runFor((int) $mailbox->tenant_id, function () use ($mailbox, $fetcher, $processor, &$failed) {
                $mailbox->forceFill(['last_polled_at' => now()])->save();

                try {
                    $count = $fetcher->fetch($mailbox, (int) $this->option('limit'), fn (string $raw) => $processor->process($mailbox, $raw));
                    $mailbox->forceFill(['last_success_at' => now(), 'last_error' => null])->save();
                    $this->line("{$mailbox->email}: {$count} E-Mail(s)");
                } catch (Throwable $e) {
                    $failed++;
                    $mailbox->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 2000)])->save();
                    $this->error("{$mailbox->email}: {$e->getMessage()}");
                    report($e);
                }
            });
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
