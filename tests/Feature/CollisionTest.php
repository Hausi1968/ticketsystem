<?php

use App\Enums\MessageType;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Mail\Inbound\InboundMailProcessor;
use App\Mail\Outbound\MailboxTransportFactory;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketPresence;
use App\Models\TicketStatus;
use App\Tenancy\TenantContext;
use App\Tickets\Presence;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\RecordingTransport;

beforeEach(function () {
    Storage::fake('local');
    $this->smtp = new RecordingTransport;
    app()->instance(MailboxTransportFactory::class, $this->smtp);

    $this->tenant = Tenant::factory()->create(['short_code' => 'FA']);
    $mailbox = mailboxFor($this->tenant, ['email' => 'auftrag@firma-a.de']);
    app(InboundMailProcessor::class)->process($mailbox, rawMail(['subject' => 'Bestellung']));
    $this->ticket = app(TenantContext::class)->runFor($this->tenant, fn () => Ticket::sole());

    $this->anna = memberOf($this->tenant);
    $this->anna->forceFill(['name' => 'Anna'])->save();
    $this->ben = memberOf($this->tenant);
    $this->ben->forceFill(['name' => 'Ben'])->save();
});

it('zeigt, wer das Ticket gerade offen hat und wer schreibt', function () {
    app(Presence::class)->touch($this->ticket, $this->ben, TicketPresence::WRITING);

    $this->actingAs($this->anna);
    Livewire::test(ViewTicket::class, ['record' => $this->ticket->id])
        ->assertSee('Ben')
        ->assertSee('schreibt gerade eine Antwort');

    app(Presence::class)->touch($this->ticket, $this->ben);
    Livewire::test(ViewTicket::class, ['record' => $this->ticket->id])
        ->assertSee('Auch geöffnet von Ben')
        ->assertDontSee('schreibt gerade');
});

it('zeigt niemanden, der sich länger nicht gemeldet hat', function () {
    app(Presence::class)->touch($this->ticket, $this->ben);
    TicketPresence::query()->update(['last_seen_at' => now()->subMinute()]);

    $this->actingAs($this->anna);
    Livewire::test(ViewTicket::class, ['record' => $this->ticket->id])
        ->assertDontSee('Auch geöffnet von');
});

it('meldet beim Öffnen und im Takt die eigene Anwesenheit', function () {
    $this->actingAs($this->anna);
    Livewire::test(ViewTicket::class, ['record' => $this->ticket->id])
        ->call('heartbeat');

    expect(TicketPresence::query()->where('user_id', $this->anna->id)->value('activity'))->toBe(TicketPresence::VIEWING)
        ->and(app(Presence::class)->others($this->ticket, $this->ben)->pluck('user.name')->all())->toBe(['Anna']);
});

it('warnt einmal vor dem Senden, wenn seit dem Öffnen eine neue Nachricht kam', function () {
    $this->actingAs($this->anna);
    $page = Livewire::test(ViewTicket::class, ['record' => $this->ticket->id])->mountAction('reply');

    // Ben antwortet in der Zwischenzeit
    app(TenantContext::class)->runFor($this->tenant, fn () => $this->ticket->messages()->create([
        'tenant_id' => $this->tenant->id, 'type' => MessageType::Note, 'user_id' => $this->ben->id, 'body_text' => 'Habe schon angerufen',
    ]));

    $data = ['body' => 'Danke, ist notiert.', 'cc' => [], 'status_id' => TicketStatus::system(TicketStatus::WAITING)->id];
    $page->setActionData($data)->callMountedAction()
        ->assertNotified('Neue Nachricht seit dem Öffnen');
    expect($this->smtp->sent)->toBe([]);

    $page->callMountedAction()->assertHasNoActionErrors();
    expect($this->smtp->sent)->toHaveCount(1);
});

it('sendet ohne Warnung, wenn nichts Neues kam', function () {
    $this->actingAs($this->anna);
    Livewire::test(ViewTicket::class, ['record' => $this->ticket->id])
        ->callAction('reply', data: ['body' => 'Danke.', 'cc' => [], 'status_id' => TicketStatus::system(TicketStatus::WAITING)->id])
        ->assertHasNoActionErrors();

    expect($this->smtp->sent)->toHaveCount(1);
});

it('meldet „schreibt“, solange das Antwortfenster offen ist', function () {
    $this->actingAs($this->anna);
    Livewire::test(ViewTicket::class, ['record' => $this->ticket->id])
        ->mountAction('reply')
        ->call('heartbeat');

    expect(TicketPresence::query()->where('user_id', $this->anna->id)->value('activity'))->toBe(TicketPresence::WRITING);
});
