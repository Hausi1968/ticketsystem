<?php

use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Mail\Inbound\InboundMailProcessor;
use App\Mail\Outbound\MailboxTransportFactory;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\RecordingTransport;

beforeEach(function () {
    Storage::fake('local');
    app()->instance(MailboxTransportFactory::class, new RecordingTransport);

    $this->tenant = Tenant::factory()->create(['short_code' => 'FA']);
    $this->mailbox = mailboxFor($this->tenant, ['email' => 'info@firma-a.de']);
    $this->agent = memberOf($this->tenant);
    $this->colleague = memberOf($this->tenant);

    app(InboundMailProcessor::class)->process($this->mailbox, rawMail(['id' => 'erste@kunde.de', 'subject' => 'Lieferung']));
    $this->ticket = app(TenantContext::class)->runWithoutTenancy(fn () => Ticket::sole());
});

function kundenantwort(): void
{
    test()->travel(5)->minutes();
    app(InboundMailProcessor::class)->process(test()->mailbox, rawMail([
        'id' => uniqid('antwort', true).'@kunde.de', 'subject' => 'AW: Lieferung', 'in_reply_to' => '<erste@kunde.de>',
    ]));
}

it('markiert eine Kundenantwort als NEU, bis der Mitarbeiter das Ticket öffnet', function () {
    $this->actingAs($this->agent);

    // neues Ticket: dafür gibt es den Status „Neu“, keine zusätzliche Markierung
    Livewire::test(ListTickets::class, ['activeTab' => 'all'])->assertCanSeeTableRecords([$this->ticket])->assertDontSeeHtml('anliego-neu');

    Livewire::test(ViewTicket::class, ['record' => $this->ticket->id]);
    kundenantwort();

    Livewire::test(ListTickets::class, ['activeTab' => 'all'])->assertSeeHtml('anliego-neu');

    // je Mitarbeiter: der Kollege sieht die Markierung weiter, nachdem der Mitarbeiter geöffnet hat
    $this->travel(1)->minutes();
    Livewire::test(ViewTicket::class, ['record' => $this->ticket->id]);
    Livewire::test(ListTickets::class, ['activeTab' => 'all'])->assertDontSeeHtml('anliego-neu');

    $this->actingAs($this->colleague);
    Livewire::test(ListTickets::class, ['activeTab' => 'all'])->assertSeeHtml('anliego-neu');

    // nächste Antwort: wieder NEU
    $this->actingAs($this->agent);
    kundenantwort();
    Livewire::test(ListTickets::class, ['activeTab' => 'all'])->assertSeeHtml('anliego-neu');
});

it('liefert der App unread_reply in der Liste und setzt es beim Öffnen zurück', function () {
    $this->agent->forceFill(['password' => bcrypt('geheim-12345'), 'email' => 'neu@team.de'])->save();
    $token = $this->postJson('/api/agent/v1/login', ['email' => 'neu@team.de', 'password' => 'geheim-12345', 'device' => 'Test'])->json('token');
    $api = fn () => $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);

    $api()->getJson('/api/agent/v1/tickets?tab=all')->assertOk()->assertJsonPath('data.0.unread_reply', false);

    kundenantwort();
    $api()->getJson('/api/agent/v1/tickets?tab=all')->assertJsonPath('data.0.unread_reply', true);

    $api()->getJson('/api/agent/v1/tickets/'.$this->ticket->id)->assertOk()->assertJsonPath('unread_reply', false);
    $this->travel(1)->minutes();
    $api()->getJson('/api/agent/v1/tickets?tab=all')->assertJsonPath('data.0.unread_reply', false);
});
