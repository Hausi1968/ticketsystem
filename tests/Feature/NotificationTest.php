<?php

use App\Enums\MessageType;
use App\Filament\Auth\EditProfile;
use App\Mail\Inbound\InboundMailProcessor;
use App\Mail\Outbound\MailboxTransportFactory;
use App\Mail\Outbound\ReplySender;
use App\Models\ApiKey;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketStatus;
use App\Notifications\TicketActivity;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\RecordingTransport;

beforeEach(function () {
    Storage::fake('local');
    $this->smtp = new RecordingTransport;
    app()->instance(MailboxTransportFactory::class, $this->smtp);

    $this->tenant = Tenant::factory()->create(['name' => 'Firma Muster', 'short_code' => 'FM']);
    $this->mailbox = mailboxFor($this->tenant, ['email' => 'bestellung@firma.de', 'from_name' => 'Firma Muster', 'send_acknowledgement' => true]);
    $this->agent = memberOf($this->tenant);
    $this->colleague = memberOf($this->tenant);
    $this->outsider = memberOf(Tenant::factory()->create());
});

function incoming(array $o = [])
{
    return app(InboundMailProcessor::class)->process(test()->mailbox, rawMail($o));
}

function inTenant(callable $callback): mixed
{
    return app(TenantContext::class)->runFor(test()->tenant, $callback);
}

// ---------------------------------------------------------------- Eingangsbestätigung

it('bestätigt den Eingang eines neuen Tickets mit der Ticketnummer', function () {
    incoming(['id' => 'k1@kunde.de']);

    $email = $this->smtp->last();
    expect($this->smtp->sent)->toHaveCount(1)
        ->and($email->getTo()[0]->getAddress())->toBe('erika@kunde.de')
        ->and($email->getSubject())->toBe('Re: Bestellung 4711 [#FM-1000]')
        ->and($email->getTextBody())->toContain('Guten Tag Erika Muster,')->toContain('Nummer FM-1000')->toContain('Firma Muster')
        ->and($email->getHeaders()->get('Auto-Submitted')->getBodyAsString())->toBe('auto-replied')
        ->and($email->getHeaders()->get('In-Reply-To')->getBodyAsString())->toBe('<k1@kunde.de>');

    $ticket = inTenant(fn () => Ticket::sole());
    expect(inTenant(fn () => TicketMessage::where('type', MessageType::Auto)->sole()->delivery_status))->toBe('sent')
        ->and(inTenant(fn () => $ticket->status->system_key))->toBe(TicketStatus::NEW)
        ->and($ticket->first_response_at)->toBeNull()
        ->and($ticket->assignee_id)->toBeNull();
});

it('ordnet die Antwort des Kunden auf die Bestätigung dem Ticket zu und bestätigt nicht erneut', function () {
    incoming();
    $ack = inTenant(fn () => TicketMessage::where('type', MessageType::Auto)->sole());

    $receipt = incoming(['subject' => 'AW: Bestellung 4711 [#FM-1000]', 'in_reply_to' => "<{$ack->message_id}>"]);

    expect($receipt->outcome)->toBe('appended')
        ->and($this->smtp->sent)->toHaveCount(1)
        ->and(inTenant(fn () => Ticket::count()))->toBe(1);
});

it('verwendet den eigenen Text des Postfachs', function () {
    $this->mailbox->update(['acknowledgement_text' => "Hallo {name}, Nr. {nummer} zu „{betreff}“ – {mandant}"]);

    incoming(['from' => 'bestellung-ohne-name@kunde.de']);

    expect($this->smtp->last()->getTextBody())->toBe('Hallo, Nr. FM-1000 zu „Bestellung 4711“ – Firma Muster');
});

it('sendet keine Bestätigung, wenn sie keiner erwartet', function (array $mail) {
    incoming($mail);

    expect($this->smtp->sent)->toBe([])
        ->and(inTenant(fn () => Ticket::count()))->toBe(1);
})->with([
    'noreply-Absender (Webshop)' => [['from' => 'Shop <noreply@shop.de>']],
    'no-reply mit Zusatz' => [['from' => 'no-reply+bestellung@shop.de']],
    'Newsletter' => [['headers' => ['List-Unsubscribe' => '<mailto:weg@news.de>']]],
    'Massenversand' => [['headers' => ['Precedence' => 'bulk']]],
]);

it('sendet keine Bestätigung, wenn sie im Postfach ausgeschaltet ist', function () {
    $this->mailbox->update(['send_acknowledgement' => false]);

    incoming();

    expect($this->smtp->sent)->toBe([]);
});

it('bestätigt einem Kunden höchstens einmal in 15 Minuten', function () {
    incoming(['subject' => 'Erste Frage']);
    incoming(['subject' => 'Zweite Frage']);
    expect($this->smtp->sent)->toHaveCount(1);

    $this->travel(16)->minutes();
    incoming(['subject' => 'Dritte Frage']);
    expect($this->smtp->sent)->toHaveCount(2)
        ->and(inTenant(fn () => Ticket::count()))->toBe(3);
});

it('bestätigt auch Meldungen aus einer Anwendung', function () {
    [, $plain] = inTenant(fn () => ApiKey::issue($this->tenant->id, 'App', mailboxId: $this->mailbox->id));

    $this->withHeaders(['Authorization' => "Bearer {$plain}"])->postJson('/api/v1/tickets', [
        'subject' => 'Login klappt nicht',
        'description' => 'Fehlermeldung beim Anmelden',
        'reporter' => ['email' => 'jonas@mitglied.de', 'name' => 'Jonas'],
    ])->assertCreated();

    expect($this->smtp->last()->getTo()[0]->getAddress())->toBe('jonas@mitglied.de')
        ->and($this->smtp->last()->getSubject())->toBe('Re: Login klappt nicht [#FM-1000]');
});

it('zeigt die Bestätigung im Verlauf, liefert sie aber nicht über die API aus', function () {
    incoming();
    $ticket = inTenant(fn () => Ticket::sole());

    $this->actingAs($this->agent)->get("/tickets/{$ticket->id}")
        ->assertOk()->assertSee('Automatische Eingangsbestätigung');
});

// ---------------------------------------------------------------- Benachrichtigungen

it('benachrichtigt bei einem neuen Ticket die Mitarbeiter des Mandanten', function () {
    Notification::fake();

    incoming();

    Notification::assertSentTo([$this->agent, $this->colleague], TicketActivity::class,
        fn (TicketActivity $n) => $n->event === 'new_ticket' && $n->ticket['reference'] === 'FM-1000' && str_contains($n->ticket['excerpt'], '3 Kisten'));
    Notification::assertNotSentTo($this->outsider, TicketActivity::class);
});

it('benachrichtigt bei Tickets eines Teams nur die Teammitglieder', function () {
    Notification::fake();
    $team = teamFor($this->tenant, 'Auftragsbearbeitung');
    inTenant(fn () => $team->addMember($this->agent));
    $this->mailbox->update(['team_id' => $team->id]);

    incoming();

    Notification::assertSentTo($this->agent, TicketActivity::class);
    Notification::assertNotSentTo($this->colleague, TicketActivity::class);
});

it('benachrichtigt bei einer Zuweisung den neuen Bearbeiter, aber nicht beim Selbst-Übernehmen', function () {
    incoming();
    $ticket = inTenant(fn () => Ticket::sole());
    Notification::fake();

    inTenant(fn () => $ticket->assignTo($this->colleague, $this->agent));
    Notification::assertSentTo($this->colleague, TicketActivity::class,
        fn (TicketActivity $n) => $n->event === 'assigned' && str_contains($n->title(), $this->agent->name));

    inTenant(fn () => $ticket->assignTo($this->agent, $this->agent));
    Notification::assertNotSentTo($this->agent, TicketActivity::class);
});

it('benachrichtigt bei einer Kundenantwort den Bearbeiter', function () {
    incoming(['id' => 'k1@kunde.de']);
    $ticket = inTenant(fn () => Ticket::sole());
    inTenant(fn () => app(ReplySender::class)->reply($ticket, $this->agent, 'Welche Farbe?'));
    Notification::fake();

    incoming(['subject' => 'AW: Bestellung 4711 [#FM-1000]', 'body' => 'Blau bitte.', 'in_reply_to' => '<k1@kunde.de>']);

    Notification::assertSentTo($this->agent, TicketActivity::class,
        fn (TicketActivity $n) => $n->event === 'customer_reply' && $n->ticket['excerpt'] === 'Blau bitte.');
    Notification::assertNotSentTo($this->colleague, TicketActivity::class);
});

it('zeigt Hinweise in der Oberfläche und sendet E-Mails nach Wunsch', function () {
    $this->colleague->update(['notification_preferences' => ['new_ticket' => false]]);

    incoming();

    $note = $this->agent->notifications()->sole();
    expect($note->data['title'])->toBe('Neues Ticket FM-1000: Bestellung 4711')
        ->and($note->data['actions'][0]['url'])->toEndWith('/tickets/'.inTenant(fn () => Ticket::sole()->id))
        ->and($this->colleague->notifications()->count())->toBe(1);

    $notification = new TicketActivity('new_ticket', $note->data + ['reference' => 'FM-1000']);
    expect($notification->via($this->agent))->toBe(['database', 'mail'])
        ->and($notification->via($this->colleague->fresh()))->toBe(['database']);
});

it('speichert die E-Mail-Einstellungen im Profil', function () {
    $this->actingAs($this->agent);

    Livewire::test(EditProfile::class)
        ->assertFormSet(['notification_preferences.assigned' => true])
        ->fillForm(['notification_preferences.new_ticket' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->agent->fresh()->wantsMailFor('new_ticket'))->toBeFalse()
        ->and($this->agent->fresh()->wantsMailFor('assigned'))->toBeTrue();
});
