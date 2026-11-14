<?php

use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Mail\Inbound\InboundMailProcessor;
use App\Mail\Outbound\MailboxTransportFactory;
use App\Models\Attachment;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\RecordingTransport;

beforeEach(function () {
    Storage::fake('local');
    $this->smtp = new RecordingTransport;
    app()->instance(MailboxTransportFactory::class, $this->smtp);

    $this->a = Tenant::factory()->create(['name' => 'Firma A', 'short_code' => 'FA']);
    $this->b = Tenant::factory()->create(['name' => 'Verein B', 'short_code' => 'VB']);
    $this->mailboxA = mailboxFor($this->a, ['email' => 'auftrag@firma-a.de']);
    $this->mailboxB = mailboxFor($this->b, ['email' => 'info@verein-b.de']);

    app(InboundMailProcessor::class)->process($this->mailboxA, rawMail(['subject' => 'Bestellung Firma A', 'attachment' => 'auftrag.pdf']));
    app(InboundMailProcessor::class)->process($this->mailboxB, rawMail(['subject' => 'Frage an Verein B']));

    $this->agentA = memberOf($this->a);
    $this->ticketA = app(TenantContext::class)->runFor($this->a, fn () => Ticket::sole());
    $this->ticketB = app(TenantContext::class)->runFor($this->b, fn () => Ticket::sole());
});

it('zeigt in der Arbeitsliste nur Tickets der betreuten Mandanten', function () {
    $this->actingAs($this->agentA);

    Livewire::test(ListTickets::class, ['activeTab' => 'all'])
        ->assertCanSeeTableRecords([$this->ticketA])
        ->assertCanNotSeeTableRecords([$this->ticketB]);
});

it('zeigt Mitarbeitern mit mehreren Mandanten alle ihre Tickets in einer Liste', function () {
    $this->actingAs(memberOf($this->a, $this->b));

    Livewire::test(ListTickets::class, ['activeTab' => 'unassigned'])
        ->assertCanSeeTableRecords([$this->ticketA, $this->ticketB]);
});

it('lässt ein Ticket übernehmen', function () {
    $this->actingAs($this->agentA);

    Livewire::test(ListTickets::class, ['activeTab' => 'unassigned'])
        ->callTableAction('take', $this->ticketA);

    expect($this->ticketA->fresh()->assignee_id)->toBe($this->agentA->id)
        ->and($this->ticketA->fresh()->status)->toBe(TicketStatus::Open);
});

it('öffnet fremde Tickets nicht', function () {
    $this->actingAs($this->agentA)
        ->get("/tickets/{$this->ticketB->id}")
        ->assertNotFound();
});

it('zeigt die Ticketansicht mit Verlauf und Anhang', function () {
    $this->actingAs($this->agentA)
        ->get("/tickets/{$this->ticketA->id}")
        ->assertOk()
        ->assertSee('Bestellung Firma A')
        ->assertSee('FA-1000')
        ->assertSee('auftrag.pdf')
        ->assertSee('3 Kisten');
});

it('sendet eine Antwort aus der Ticketansicht', function () {
    $this->actingAs($this->agentA);

    Livewire::test(ViewTicket::class, ['record' => $this->ticketA->id])
        ->callAction('reply', data: ['body' => 'Danke, ist notiert.', 'cc' => [], 'status' => TicketStatus::PendingCustomer->value])
        ->assertHasNoActionErrors();

    expect($this->smtp->sent)->toHaveCount(1)
        ->and($this->smtp->last()->getTo()[0]->getAddress())->toBe('erika@kunde.de')
        ->and($this->ticketA->fresh()->status)->toBe(TicketStatus::PendingCustomer);
});

it('speichert interne Notizen ohne Versand', function () {
    $this->actingAs($this->agentA);

    Livewire::test(ViewTicket::class, ['record' => $this->ticketA->id])
        ->callAction('note', data: ['body' => 'Rückruf vereinbart'])
        ->assertHasNoActionErrors();

    expect($this->smtp->sent)->toBe([])
        ->and($this->ticketA->messages()->where('type', 'note')->value('body_text'))->toBe('Rückruf vereinbart');
});

it('liefert Anhänge nur an Mitarbeiter des Mandanten aus', function () {
    $attachment = app(TenantContext::class)->runWithoutTenancy(fn () => Attachment::sole());

    $this->actingAs($this->agentA)->get("/anhaenge/{$attachment->id}")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/octet-stream');

    $this->actingAs(memberOf($this->b))->get("/anhaenge/{$attachment->id}")->assertNotFound();

    auth()->logout();
    $this->get("/anhaenge/{$attachment->id}")->assertRedirect('/login');
});

it('erlaubt die Postfachverwaltung nur Administratoren', function () {
    $this->actingAs($this->agentA)->get('/postfaecher')->assertForbidden();
    $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/postfaecher')->assertOk()->assertSee('auftrag@firma-a.de');
});

it('vergibt über alle Mandanten eindeutige Ticketnummern', function () {
    expect($this->ticketA->reference())->toBe('FA-1000')
        ->and($this->ticketB->reference())->toBe('VB-1001');
});

it('findet ein Ticket über seine Nummer und öffnet den Verlauf', function () {
    $this->actingAs(memberOf($this->a, $this->b));

    Livewire::test(ListTickets::class, ['activeTab' => 'all'])
        ->searchTable('VB-1001')
        ->assertCanSeeTableRecords([$this->ticketB])
        ->assertCanNotSeeTableRecords([$this->ticketA]);

    $results = \App\Filament\Resources\Tickets\TicketResource::getGlobalSearchResults('#1000');
    expect($results)->toHaveCount(1)
        ->and($results->first()->url)->toEndWith("/tickets/{$this->ticketA->id}");
});

it('findet über die Nummer keine Tickets fremder Mandanten', function () {
    $this->actingAs($this->agentA);

    expect(\App\Filament\Resources\Tickets\TicketResource::getGlobalSearchResults('VB-1001'))->toHaveCount(0);
});
