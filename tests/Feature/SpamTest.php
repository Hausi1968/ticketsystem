<?php

use App\Enums\MembershipRole;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Mail\Inbound\InboundMailProcessor;
use App\Mail\Inbound\LoopDetector;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->tenant = Tenant::factory()->create(['short_code' => 'FA']);
    $this->mailbox = mailboxFor($this->tenant, ['email' => 'auftrag@firma-a.de']);
    $this->tickets = fn () => app(TenantContext::class)->runFor($this->tenant, fn () => Ticket::query()->count());
});

it('erkennt gesperrte Adressen und Domains', function () {
    $this->mailbox->blocked_senders = "werbung@shop.de\n@spam.de\n*@junk.org\nnervig.com";

    expect($this->mailbox->blocksSender('Werbung@Shop.de'))->toBeTrue()
        ->and($this->mailbox->blocksSender('info@shop.de'))->toBeFalse()
        ->and($this->mailbox->blocksSender('a@spam.de'))->toBeTrue()
        ->and($this->mailbox->blocksSender('a@mail.spam.de'))->toBeTrue()
        ->and($this->mailbox->blocksSender('a@nospam.de'))->toBeFalse()
        ->and($this->mailbox->blocksSender('x@junk.org'))->toBeTrue()
        ->and($this->mailbox->blocksSender('x@nervig.com'))->toBeTrue();
});

it('legt aus gesperrten Absendern kein Ticket an', function () {
    $this->mailbox->update(['blocked_senders' => '@kunde.de']);

    $receipt = app(InboundMailProcessor::class)->process($this->mailbox->fresh(), rawMail(['subject' => 'Kaufen Sie jetzt']));

    expect($receipt->outcome)->toBe(LoopDetector::BLOCKED)
        ->and(($this->tickets)())->toBe(0);
});

it('ignoriert Newsletter nur, wenn das Postfach es so eingestellt hat', function () {
    $newsletter = fn (string $id) => "List-Unsubscribe: <https://shop.de/abmelden>\r\n".rawMail(['subject' => 'Unser Angebot', 'message_id' => "<$id@x>"]);

    app(InboundMailProcessor::class)->process($this->mailbox, $newsletter('n1'));
    expect(($this->tickets)())->toBe(1);

    $this->mailbox->update(['ignore_newsletters' => true]);
    $receipt = app(InboundMailProcessor::class)->process($this->mailbox->fresh(), $newsletter('n2'));
    expect($receipt->outcome)->toBe(LoopDetector::NEWSLETTER)
        ->and(($this->tickets)())->toBe(1);
});

it('sperrt den Absender aus dem Ticket heraus und markiert es als gelöst', function () {
    app(InboundMailProcessor::class)->process($this->mailbox, rawMail(['subject' => 'Spam']));
    $ticket = app(TenantContext::class)->runFor($this->tenant, fn () => Ticket::sole());
    $supervisor = memberOf();
    $supervisor->memberships()->create(['tenant_id' => $this->tenant->id, 'role' => MembershipRole::Supervisor]);
    $this->actingAs($supervisor);

    Livewire::test(ViewTicket::class, ['record' => $ticket->id])
        ->callAction('blockSender', data: ['scope' => 'domain', 'solve' => true])
        ->assertHasNoActionErrors();

    expect($this->mailbox->fresh()->blockedSenderList())->toBe(['@kunde.de'])
        ->and($ticket->fresh()->status->system_key)->toBe(TicketStatus::SOLVED);
});

it('zeigt „Absender sperren“ nicht für normale Mitarbeiter', function () {
    app(InboundMailProcessor::class)->process($this->mailbox, rawMail(['subject' => 'Frage']));
    $ticket = app(TenantContext::class)->runFor($this->tenant, fn () => Ticket::sole());
    $this->actingAs(memberOf($this->tenant));

    Livewire::test(ViewTicket::class, ['record' => $ticket->id])->assertActionHidden('blockSender');
});
