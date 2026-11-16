<?php

use App\Mail\Inbound\InboundMailProcessor;
use App\Mail\Outbound\MailboxTransportFactory;
use App\Mail\Outbound\ReplySender;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RecordingTransport;

beforeEach(function () {
    Storage::fake('local');
    $this->smtp = new RecordingTransport;
    app()->instance(MailboxTransportFactory::class, $this->smtp);

    $this->tenant = Tenant::factory()->create(['short_code' => 'FA', 'settings' => [
        'reminder_days' => 3, 'auto_solve_days' => 7, 'auto_close_days' => 5,
    ]]);
    $this->mailbox = mailboxFor($this->tenant, ['email' => 'auftrag@firma-a.de']);
    $this->agent = memberOf($this->tenant);

    app(InboundMailProcessor::class)->process($this->mailbox, rawMail(['subject' => 'Lieferung fehlt']));
    $this->ticket = app(TenantContext::class)->runFor($this->tenant, fn () => Ticket::sole());

    // Antwort des Teams: Ticket wartet auf den Kunden
    app(TenantContext::class)->runFor($this->tenant, fn () => app(ReplySender::class)->reply($this->ticket, $this->agent, 'Welche Bestellnummer?'));
    $this->smtp->sent = [];
});

function ticketNow(): Ticket
{
    return app(TenantContext::class)->runWithoutTenancy(fn () => test()->ticket->fresh(['status']));
}

it('erinnert den Kunden einmal nach den eingestellten Tagen', function () {
    $this->travel(2)->days();
    $this->artisan('anliego:automation')->assertSuccessful();
    expect($this->smtp->sent)->toBe([]);

    $this->travel(2)->days();
    $this->artisan('anliego:automation')->assertSuccessful();
    expect($this->smtp->sent)->toHaveCount(1)
        ->and($this->smtp->last()->getTextBody())->toContain('wir warten noch auf Ihre Rückmeldung')
        ->and($this->smtp->last()->getSubject())->toContain('[#FA-');

    // Kein zweites Mal in derselben Wartezeit
    $this->travel(1)->days();
    $this->artisan('anliego:automation')->assertSuccessful();
    expect($this->smtp->sent)->toHaveCount(1)
        ->and(ticketNow()->status->system_key)->toBe(TicketStatus::WAITING);
});

it('löst wartende Tickets ab der Erinnerung und schließt gelöste später', function () {
    $this->travel(4)->days();
    $this->artisan('anliego:automation'); // Erinnerung an Tag 4

    $this->travel(6)->days(); // Tag 10: 10 Tage seit der Antwort, aber erst 6 seit der Erinnerung
    $this->artisan('anliego:automation');
    expect(ticketNow()->status->system_key)->toBe(TicketStatus::WAITING);

    $this->travel(2)->days(); // 8 Tage seit der Erinnerung
    $this->artisan('anliego:automation');
    expect(ticketNow()->status->system_key)->toBe(TicketStatus::SOLVED);

    $this->travel(5)->days();
    $this->artisan('anliego:automation');
    expect(ticketNow()->status->name)->toBe('Geschlossen');

    $events = app(TenantContext::class)->runWithoutTenancy(fn () => $this->ticket->events()->where('type', 'status')->pluck('data'));
    expect($events->last())->toMatchArray(['to' => 'Geschlossen', 'auto' => 5]);
});

it('macht nichts, wenn beim Mandanten 0 eingestellt ist', function () {
    $this->tenant->update(['settings' => ['reminder_days' => 0, 'auto_solve_days' => 0, 'auto_close_days' => 0]]);

    $this->travel(30)->days();
    $this->artisan('anliego:automation')->assertSuccessful();

    expect($this->smtp->sent)->toBe([])
        ->and(ticketNow()->status->system_key)->toBe(TicketStatus::WAITING);
});

it('zählt nach einer Kundenantwort und neuer Rückfrage neu', function () {
    $this->travel(2)->days();
    app(InboundMailProcessor::class)->process($this->mailbox, rawMail([
        'subject' => 'Re: Lieferung fehlt '.ticketNow()->subjectToken(), 'message_id' => '<neu@kunde.de>',
    ]));
    expect(ticketNow()->status->system_key)->toBe(TicketStatus::OPEN)
        ->and(ticketNow()->waiting_since)->toBeNull();

    $this->travel(1)->days();
    app(TenantContext::class)->runFor($this->tenant, fn () => app(ReplySender::class)->reply(ticketNow(), $this->agent, 'Danke, und die Adresse?'));
    $this->smtp->sent = [];

    $this->travel(2)->days(); // 5 Tage seit der ersten, 2 seit der zweiten Rückfrage
    $this->artisan('anliego:automation');
    expect($this->smtp->sent)->toBe([]);
});

it('verschickt Antworten über das beim Mandanten eingestellte Postfach', function () {
    $other = mailboxFor($this->tenant, ['email' => 'service@firma-a.de']);
    $this->tenant->update(['settings' => ['reply_mailbox_email' => $other->getKey()]]);

    app(TenantContext::class)->runFor($this->tenant, fn () => app(ReplySender::class)
        ->reply($this->ticket->fresh(), $this->agent, 'Bitte noch die Adresse.'));

    expect($this->smtp->last()->getFrom()[0]->getAddress())->toBe('service@firma-a.de');

    // Ein inaktives oder fremdes Postfach wird ignoriert
    $other->update(['active' => false]);
    app(TenantContext::class)->runFor($this->tenant, fn () => app(ReplySender::class)
        ->reply($this->ticket->fresh(), $this->agent, 'Danke.'));

    expect($this->smtp->last()->getFrom()[0]->getAddress())->toBe('auftrag@firma-a.de');
});
