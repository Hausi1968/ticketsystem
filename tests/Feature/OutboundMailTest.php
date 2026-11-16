<?php

use App\Enums\MessageType;
use App\Jobs\DeliverTicketMessage;
use App\Mail\Inbound\InboundMailProcessor;
use App\Mail\Outbound\MailboxTransportFactory;
use App\Mail\Outbound\ReplySender;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketStatus;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RecordingTransport;

beforeEach(function () {
    Storage::fake('local');
    $this->smtp = new RecordingTransport;
    app()->instance(MailboxTransportFactory::class, $this->smtp);

    $this->tenant = Tenant::factory()->create(['name' => 'Firma Muster', 'short_code' => 'FM']);
    $this->mailbox = mailboxFor($this->tenant, ['email' => 'bestellung@firma.de', 'from_name' => 'Firma Muster Auftragsbearbeitung']);
    $this->agent = memberOf($this->tenant);
    DB::table('tenant_user')->where('user_id', $this->agent->id)->update(['signature' => "Max Agent\nFirma Muster"]);

    app(InboundMailProcessor::class)->process($this->mailbox, rawMail(['id' => 'kunde1@kunde.de', 'subject' => 'Bestellung 4711']));
    $this->actingAs($this->agent);
    $this->ticket = Ticket::sole();
});

it('sendet eine Antwort über das Postfach im richtigen E-Mail-Verlauf', function () {
    app(ReplySender::class)->reply($this->ticket, $this->agent, 'Vielen Dank, die Ware geht morgen raus.');

    $email = $this->smtp->last();
    $headers = $email->getHeaders();

    expect($this->smtp->sent)->toHaveCount(1)
        ->and($email->getFrom()[0]->getAddress())->toBe('bestellung@firma.de')
        ->and($email->getFrom()[0]->getName())->toBe('Firma Muster Auftragsbearbeitung')
        ->and($email->getTo()[0]->getAddress())->toBe('erika@kunde.de')
        ->and($email->getSubject())->toBe('Re: Bestellung 4711 [#FM-1000]')
        ->and($headers->get('In-Reply-To')->getBodyAsString())->toBe('<kunde1@kunde.de>')
        ->and($headers->get('References')->getBodyAsString())->toContain('<kunde1@kunde.de>')
        ->and($headers->get('Message-ID')->getBodyAsString())->toContain('@firma.de>')
        ->and($email->getTextBody())->toContain("Vielen Dank, die Ware geht morgen raus.\n\n-- \nMax Agent");

    $message = TicketMessage::where('type', MessageType::Reply)->sole();
    $ticket = $this->ticket->fresh();

    expect($message->delivery_status)->toBe('sent')
        ->and($ticket->status->system_key)->toBe(TicketStatus::WAITING)
        ->and($ticket->assignee_id)->toBe($this->agent->id)
        ->and($ticket->first_response_at)->not->toBeNull();
});

it('ordnet die Kundenantwort auf unsere E-Mail wieder dem Ticket zu', function () {
    $reply = app(ReplySender::class)->reply($this->ticket, $this->agent, 'Rückfrage: welche Farbe?');

    $receipt = app(InboundMailProcessor::class)->process($this->mailbox, rawMail([
        'subject' => 'AW: Bestellung 4711 [#FM-1000]',
        'in_reply_to' => "<{$reply->message_id}>",
    ]));

    expect($receipt->outcome)->toBe('reopened')
        ->and($this->ticket->fresh()->status->system_key)->toBe(TicketStatus::OPEN)
        ->and($this->ticket->messages()->count())->toBe(3);
});

it('versendet Anhänge mit der Antwort', function () {
    Storage::disk('local')->put('livewire-tmp/abc', 'Inhalt der Datei');

    $message = app(ReplySender::class)->reply($this->ticket, $this->agent, 'Anbei die Rechnung', files: [
        ['path' => 'livewire-tmp/abc', 'name' => 'Rechnung.pdf', 'mime' => 'application/pdf'],
    ]);

    $attachment = $message->attachments()->sole();
    expect($attachment->filename)->toBe('Rechnung.pdf')
        ->and($attachment->path)->toStartWith("tenants/{$this->tenant->id}/attachments/")
        ->and($this->smtp->last()->getAttachments()[0]->getFilename())->toBe('Rechnung.pdf');
});

it('plant einen neuen Versuch, wenn SMTP nicht erreichbar ist', function () {
    Queue::fake();
    $this->smtp->fail = true;

    $message = app(ReplySender::class)->reply($this->ticket, $this->agent, 'Hallo');

    expect($message->fresh()->delivery_status)->toBe('failed')
        ->and($message->fresh()->delivery_error)->toContain('SMTP nicht erreichbar');
    Queue::assertPushed(DeliverTicketMessage::class);

    // Später klappt es
    $this->smtp->fail = false;
    auth()->logout();
    (new DeliverTicketMessage($this->tenant->id, $message->id))->handle(app(ReplySender::class), app(TenantContext::class));

    expect(app(TenantContext::class)->runWithoutTenancy(fn () => $message->fresh()->delivery_status))->toBe('sent')
        ->and($this->smtp->sent)->toHaveCount(1);
});

it('versendet interne Notizen nicht', function () {
    app(ReplySender::class)->note($this->ticket, $this->agent, 'Kunde ist Stammkunde.');

    expect($this->smtp->sent)->toBe([])
        ->and(TicketMessage::where('type', MessageType::Note)->sole()->body_text)->toBe('Kunde ist Stammkunde.');
});

it('weist Tickets nur Mitarbeitern des Mandanten zu', function () {
    $outsider = memberOf(Tenant::factory()->create());

    $this->ticket->assignTo($this->agent, $this->agent);
    expect($this->ticket->fresh()->assignee_id)->toBe($this->agent->id)
        ->and($this->ticket->fresh()->status->system_key)->toBe(TicketStatus::OPEN);

    expect(fn () => $this->ticket->assignTo($outsider))->toThrow(AuthorizationException::class);
});

it('protokolliert Status- und Zuweisungsänderungen', function () {
    $this->ticket->assignTo($this->agent, $this->agent);
    $this->ticket->changeStatus(TicketStatus::SOLVED, $this->agent);

    expect($this->ticket->events()->pluck('type')->all())->toBe(['created', 'assigned', 'status'])
        ->and($this->ticket->fresh()->closed_at)->not->toBeNull();
});
