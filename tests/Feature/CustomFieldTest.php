<?php

use App\Filament\Resources\CustomFields\Pages\ManageCustomFields;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Mail\Inbound\InboundMailProcessor;
use App\Mail\Outbound\MailboxTransportFactory;
use App\Models\ApiKey;
use App\Models\CustomField;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\RecordingTransport;

beforeEach(function () {
    Storage::fake('local');
    app()->instance(MailboxTransportFactory::class, new RecordingTransport);

    $this->tenant = Tenant::factory()->create(['short_code' => 'FA']);
    $this->other = Tenant::factory()->create(['short_code' => 'XY']);
    $this->mailbox = mailboxFor($this->tenant, ['email' => 'info@firma-a.de']);
    $this->agent = memberOf($this->tenant);

    CustomField::create(['tenant_id' => $this->tenant->id, 'key' => 'bestellnummer', 'label' => 'Bestellnummer', 'show_in_list' => true]);
    CustomField::create(['tenant_id' => null, 'key' => 'bereich', 'label' => 'Bereich', 'type' => 'select', 'options' => ['Verkauf', 'Technik'], 'show_in_list' => true]);
    CustomField::create(['tenant_id' => $this->other->id, 'key' => 'vereinsnummer', 'label' => 'Vereinsnummer']);

    app(InboundMailProcessor::class)->process($this->mailbox, rawMail(['subject' => 'Lieferung']));
    app(InboundMailProcessor::class)->process($this->mailbox, rawMail(['subject' => 'Andere', 'from' => 'max@kunde.de']));
    [$this->ticket, $this->second] = app(TenantContext::class)->runWithoutTenancy(fn () => Ticket::orderBy('id')->get()->all());
});

it('speichert Felder des Mandanten geprüft und protokolliert die Änderung', function () {
    app(TenantContext::class)->runFor($this->tenant, fn () => $this->ticket->updateFields([
        'bestellnummer' => ' 4711 ', 'bereich' => 'Unsinn', 'vereinsnummer' => '99',
    ], $this->agent));

    $ticket = app(TenantContext::class)->runWithoutTenancy(fn () => $this->ticket->fresh());
    expect($ticket->fields)->toBe(['bestellnummer' => '4711'])
        ->and(app(TenantContext::class)->runWithoutTenancy(fn () => $ticket->events()->where('type', 'fields')->count()))->toBe(1);
});

it('bearbeitet Felder in der Ticketansicht und filtert die Liste danach', function () {
    $this->actingAs($this->agent);

    Livewire::test(ViewTicket::class, ['record' => $this->ticket->id])
        ->callAction('fields', data: ['fields' => ['bestellnummer' => 'B-100', 'bereich' => 'Technik']])
        ->assertHasNoActionErrors()
        ->assertSee('B-100');

    Livewire::test(ListTickets::class)
        ->assertCanSeeTableRecords([$this->ticket, $this->second])
        ->filterTable('field_bestellnummer', ['value' => 'B-1'])
        ->assertCanSeeTableRecords([$this->ticket])->assertCanNotSeeTableRecords([$this->second])
        ->resetTableFilters()
        ->filterTable('field_bereich', 'Technik')
        ->assertCanSeeTableRecords([$this->ticket])->assertCanNotSeeTableRecords([$this->second]);
});

it('nimmt Felder über die API an und gibt sie zurück', function () {
    [, $plain] = app(TenantContext::class)->runFor($this->tenant, fn () => ApiKey::issue($this->tenant->id, 'App'));
    $headers = ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'];

    $ref = $this->withHeaders($headers)->postJson('/api/v1/tickets', [
        'subject' => 'Fehler', 'description' => 'Geht nicht',
        'reporter' => ['email' => 'jonas@mitglied.de'],
        'fields' => ['bestellnummer' => 'A-1', 'bereich' => 'Verkauf'],
    ])->assertCreated()->json('reference');

    $this->withHeaders($headers)->getJson("/api/v1/tickets/{$ref}")
        ->assertOk()->assertJsonPath('fields.bestellnummer', 'A-1')->assertJsonPath('fields.bereich', 'Verkauf');
});

it('legt Felder in der Verwaltung an', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    $this->get('/felder')->assertOk();

    Livewire::test(ManageCustomFields::class)
        ->callAction('create', data: ['label' => 'Kundennummer', 'key' => 'kundennummer', 'tenant_id' => $this->tenant->id, 'type' => 'text'])
        ->assertHasNoActionErrors();

    expect(CustomField::where('key', 'kundennummer')->exists())->toBeTrue();
});
