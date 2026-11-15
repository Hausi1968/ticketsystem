<?php

namespace App\Mail\Outbound;

use App\Models\Mailbox;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/** Baut den SMTP-Zugang eines Postfachs. In Tests wird diese Klasse durch eine Attrappe ersetzt. */
class MailboxTransportFactory
{
    public function for(Mailbox $mailbox): TransportInterface
    {
        $transport = new EsmtpTransport(
            host: $mailbox->smtp_host,
            port: (int) $mailbox->smtp_port,
            tls: $mailbox->smtp_encryption === 'ssl' ? true : null, // null = STARTTLS, wenn angeboten
        );

        if ($mailbox->smtp_encryption === 'none') {
            $transport->setAutoTls(false);
        }

        // Nicht hängen bleiben: Ohne Grenze wartet PHP bis zu 60 s, das Webhosting bricht vorher ab
        $transport->getStream()->setTimeout(15);

        $transport->setUsername((string) $mailbox->smtp_username);
        $transport->setPassword((string) $mailbox->smtp_password);

        return $transport;
    }
}
