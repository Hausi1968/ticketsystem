<?php

namespace Tests\Support;

use App\Mail\Outbound\MailboxTransportFactory;
use App\Models\Mailbox;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

/** Ersetzt SMTP in Tests und merkt sich alle gesendeten E-Mails. */
class RecordingTransport extends MailboxTransportFactory
{
    /** @var list<array{mailbox: int, email: Email}> */
    public array $sent = [];

    public bool $fail = false;

    public function for(Mailbox $mailbox): TransportInterface
    {
        $recorder = $this;

        return new class($recorder, $mailbox) extends AbstractTransport
        {
            public function __construct(private RecordingTransport $recorder, private Mailbox $mailbox)
            {
                parent::__construct();
            }

            protected function doSend(SentMessage $message): void
            {
                if ($this->recorder->fail) {
                    throw new RuntimeException('SMTP nicht erreichbar');
                }

                $this->recorder->sent[] = ['mailbox' => $this->mailbox->id, 'email' => $message->getOriginalMessage()];
            }

            public function __toString(): string
            {
                return 'recording://';
            }
        };
    }

    public function last(): Email
    {
        return end($this->sent)['email'];
    }
}
