<?php

use App\Enums\MessageType;
use App\Models\TicketStatus;
use App\Mail\Inbound\InboundMailProcessor;
use App\Models\Attachment;
use App\Models\MailReceipt;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

function process($mailbox, string $raw): MailReceipt
{
    return app(InboundMailProcessor::class)->process($mailbox, $raw);
}

function allTickets()
{
    return app(TenantContext::class)->runWithoutTenancy(fn () => Ticket::with('contact', 'messages')->orderBy('id')->get());
}

beforeEach(function () {
    Storage::fake('local');
    $this->tenant = Tenant::factory()->create(['name' => 'Firma Muster', 'short_code' => 'FM']);
    $this->team = teamFor($this->tenant, 'Auftragsbearbeitung');
    $this->mailbox = mailboxFor($this->tenant, ['email' => 'bestellung@firma.de', 'team_id' => $this->team->id]);
});

it('legt aus einer neuen E-Mail ein Ticket mit Kontakt und Nachricht an', function () {
    $receipt = process($this->mailbox, rawMail(['subject' => 'Bestellung 4711', 'id' => 'a1@kunde.de']));

    expect($receipt->outcome)->toBe('created');

    $ticket = allTickets()->sole();
    expect($ticket->tenant_id)->toBe($this->tenant->id)
        ->and($ticket->number)->toBe(1000)
        ->and($ticket->subject)->toBe('Bestellung 4711')
        ->and($ticket->status->system_key)->toBe(TicketStatus::NEW)
        ->and($ticket->team_id)->toBe($this->team->id)
        ->and($ticket->contact->email)->toBe('erika@kunde.de')
        ->and($ticket->contact->name)->toBe('Erika Muster')
        ->and($ticket->messages->sole()->type)->toBe(MessageType::Customer)
        ->and($ticket->messages->sole()->message_id)->toBe('a1@kunde.de')
        ->and($ticket->messages->sole()->body_text)->toContain('3 Kisten');

    Storage::disk('local')->assertExists($ticket->messages->sole()->raw_path);
});

it('speichert Anhänge im Ordner des Mandanten', function () {
    process($this->mailbox, rawMail(['attachment' => 'Bestellung.pdf']));

    $attachment = app(TenantContext::class)->runWithoutTenancy(fn () => Attachment::sole());

    expect($attachment->filename)->toBe('Bestellung.pdf')
        ->and($attachment->mime_type)->toBe('application/pdf')
        ->and($attachment->tenant_id)->toBe($this->tenant->id)
        ->and($attachment->path)->toStartWith("tenants/{$this->tenant->id}/attachments/")
        ->and(Storage::disk('local')->get($attachment->path))->toBe('%PDF-1.4 test');
});

it('verarbeitet dieselbe E-Mail nur einmal', function () {
    $raw = rawMail(['id' => 'dup@kunde.de']);

    process($this->mailbox, $raw);
    $second = process($this->mailbox, $raw);

    expect($second->outcome)->toBe('duplicate')
        ->and(allTickets())->toHaveCount(1);
});

it('ordnet eine Antwort über In-Reply-To dem bestehenden Ticket zu', function () {
    process($this->mailbox, rawMail(['id' => 'first@kunde.de']));
    $receipt = process($this->mailbox, rawMail([
        'id' => 'second@kunde.de', 'subject' => 'AW: Bestellung 4711', 'in_reply_to' => '<first@kunde.de>',
    ]));

    expect($receipt->outcome)->toBe('appended')
        ->and(allTickets())->toHaveCount(1)
        ->and(allTickets()->sole()->messages)->toHaveCount(2);
});

it('ordnet eine Antwort über die Betreff-Kennung zu, aber nur vom Anfragenden', function () {
    process($this->mailbox, rawMail(['id' => 'x1@kunde.de']));
    $ticket = allTickets()->sole();

    $own = process($this->mailbox, rawMail(['subject' => "Re: Bestellung [#FM-{$ticket->number}]"]));
    $stranger = process($this->mailbox, rawMail([
        'from' => 'fremd@andere.de', 'subject' => "Re: Bestellung [#FM-{$ticket->number}]",
    ]));

    expect($own->ticket_id)->toBe($ticket->id)
        ->and($stranger->outcome)->toBe('created')
        ->and($stranger->ticket_id)->not->toBe($ticket->id);
});

it('ignoriert die Betreff-Kennung eines anderen Mandanten', function () {
    $other = Tenant::factory()->create(['short_code' => 'XY']);
    $otherMailbox = mailboxFor($other, ['email' => 'info@xy.de']);
    process($otherMailbox, rawMail(['id' => 'o1@kunde.de']));
    $foreign = allTickets()->sole();

    $receipt = process($this->mailbox, rawMail(['subject' => "Re: [#XY-{$foreign->number}]"]));

    expect($receipt->outcome)->toBe('created')
        ->and(app(TenantContext::class)->runWithoutTenancy(fn () => Ticket::find($receipt->ticket_id))->tenant_id)
        ->toBe($this->tenant->id);
});

it('ordnet über References keine Tickets eines anderen Mandanten zu', function () {
    $other = Tenant::factory()->create();
    process(mailboxFor($other, ['email' => 'info@other.de']), rawMail(['id' => 'secret@kunde.de']));

    $receipt = process($this->mailbox, rawMail(['in_reply_to' => '<secret@kunde.de>']));

    expect($receipt->outcome)->toBe('created')
        ->and(app(TenantContext::class)->runWithoutTenancy(fn () => Ticket::find($receipt->ticket_id))->tenant_id)
        ->toBe($this->tenant->id);
});

it('öffnet ein gelöstes Ticket bei einer Antwort wieder', function () {
    process($this->mailbox, rawMail(['id' => 's1@kunde.de']));
    app(TenantContext::class)->runFor($this->tenant, fn () => Ticket::sole()->changeStatus(TicketStatus::SOLVED));

    $receipt = process($this->mailbox, rawMail(['in_reply_to' => '<s1@kunde.de>']));

    expect($receipt->outcome)->toBe('reopened')
        ->and(allTickets()->sole()->status->system_key)->toBe(TicketStatus::OPEN);
});

it('legt bei Antwort auf ein geschlossenes Ticket ein Folgeticket an', function () {
    process($this->mailbox, rawMail(['id' => 'c1@kunde.de']));
    $closed = app(TenantContext::class)->runFor($this->tenant, function () {
        $ticket = Ticket::sole();
        $ticket->changeStatus(TicketStatus::where('name', 'Geschlossen')->sole());

        return $ticket;
    });

    $receipt = process($this->mailbox, rawMail(['in_reply_to' => '<c1@kunde.de>']));
    $tickets = allTickets();

    expect($receipt->outcome)->toBe('follow_up')
        ->and($tickets)->toHaveCount(2)
        ->and($tickets->last()->follow_up_of_id)->toBe($closed->id);
});

it('ignoriert Abwesenheitsnotizen und Unzustellbarkeitsmeldungen', function (array $mail, string $outcome) {
    $receipt = process($this->mailbox, rawMail($mail));

    expect($receipt->outcome)->toBe($outcome)
        ->and(allTickets())->toHaveCount(0);
})->with([
    'Auto-Submitted' => [['headers' => ['Auto-Submitted' => 'auto-replied']], 'ignored_loop'],
    'X-Autoreply' => [['headers' => ['X-Autoreply' => 'yes']], 'ignored_loop'],
    'Outlook-Abwesenheit' => [['subject' => 'Automatische Antwort: Ihre Anfrage'], 'ignored_loop'],
    'Mailer-Daemon' => [['from' => 'MAILER-DAEMON@mx.kunde.de'], 'ignored_bounce'],
    'Eigenes Postfach' => [['from' => 'bestellung@firma.de'], 'ignored_own'],
]);

it('nimmt Bestellungen von noreply-Absendern an (z. B. Webshop)', function () {
    $receipt = process($this->mailbox, rawMail(['from' => 'noreply@shop.de', 'headers' => ['Precedence' => 'bulk']]));

    expect($receipt->outcome)->toBe('created');
});

it('übernimmt CC-Empfänger als Beteiligte, aber keine eigenen Postfächer', function () {
    process($this->mailbox, rawMail(['to' => 'bestellung@firma.de', 'cc' => 'Chef <chef@kunde.de>, bestellung@firma.de']));

    $ticket = allTickets()->sole();
    $participants = app(TenantContext::class)->runFor($this->tenant, fn () => $ticket->participants()->pluck('email')->all());

    expect($participants)->toBe(['chef@kunde.de']);
});

it('entfernt Re:/AW: aus dem Betreff neuer Tickets', function () {
    expect(InboundMailProcessor::cleanSubject('AW: WG: Re: Lieferung'))->toBe('Lieferung')
        ->and(InboundMailProcessor::cleanSubject('Antw: Frage'))->toBe('Frage')
        ->and(InboundMailProcessor::cleanSubject('Reparatur'))->toBe('Reparatur');
});

it('bereinigt HTML-Nachrichten', function () {
    $raw = rawMail(['headers' => ['Content-Type' => 'text/html; charset=utf-8'], 'body' => '<p onclick="x()">Hallo<script>alert(1)</script><img src="https://tracker.de/p.gif"></p>']);

    process($this->mailbox, $raw);
    $message = app(TenantContext::class)->runWithoutTenancy(fn () => TicketMessage::sole());

    expect($message->body_html)->toContain('Hallo')
        ->not->toContain('script')
        ->not->toContain('onclick')
        ->not->toContain('tracker.de')
        ->and($message->body_text)->toBe('Hallo');
});

it('ruft fällige Postfächer per Befehl ab und beachtet das Intervall', function () {
    $fetcher = fakeFetcher()->push($this->mailbox, rawMail(), rawMail());
    $slow = mailboxFor($this->tenant, ['email' => 'info@firma.de', 'poll_interval_minutes' => 10, 'last_polled_at' => now()->subMinutes(3)]);

    $this->artisan('anliego:poll-mailboxes')->assertSuccessful();

    expect($fetcher->polled)->toBe([$this->mailbox->id])
        ->and(allTickets())->toHaveCount(2)
        ->and(app(TenantContext::class)->runWithoutTenancy(fn () => $this->mailbox->fresh())->last_polled_at)->not->toBeNull();

    $this->travel(8)->minutes();
    $this->artisan('anliego:poll-mailboxes')->assertSuccessful();

    expect($fetcher->polled)->toBe([$this->mailbox->id, $this->mailbox->id, $slow->id]);
});

it('merkt sich Verbindungsfehler am Postfach', function () {
    $fetcher = fakeFetcher();
    $fetcher->failWith = 'Anmeldung fehlgeschlagen';

    $this->artisan('anliego:poll-mailboxes')->assertFailed();

    expect(app(TenantContext::class)->runWithoutTenancy(fn () => $this->mailbox->fresh()->last_error))
        ->toBe('Anmeldung fehlgeschlagen');
});
