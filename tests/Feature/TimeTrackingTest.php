<?php

use App\Filament\Pages\Reports;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Mail\Inbound\InboundMailProcessor;
use App\Mail\Outbound\MailboxTransportFactory;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\TimeEntry;
use App\Models\User;
use App\Reports\MonthlyReport;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\RecordingTransport;

beforeEach(function () {
    Storage::fake('local');
    app()->instance(MailboxTransportFactory::class, new RecordingTransport);
    $this->travelTo(now()->setDate(2026, 11, 10)->setTime(10, 0));

    $this->tenant = Tenant::factory()->create(['name' => 'Firma A', 'short_code' => 'FA']);
    $this->mailbox = mailboxFor($this->tenant, ['email' => 'info@firma-a.de']);
    $this->agent = memberOf($this->tenant);
    app(InboundMailProcessor::class)->process($this->mailbox, rawMail(['subject' => 'Lieferung']));
    $this->ticket = app(TenantContext::class)->runWithoutTenancy(fn () => Ticket::sole());
});

it('bucht Zeit mit Antwort, Notiz und von Hand und zeigt die Summe', function () {
    $this->actingAs($this->agent);

    Livewire::test(ViewTicket::class, ['record' => $this->ticket->id])
        ->callAction('reply', data: ['body' => 'Wir prüfen das.', 'cc' => [], 'minutes' => 20,
            'status_id' => TicketStatus::system(TicketStatus::WAITING)->id])
        ->assertHasNoActionErrors()
        ->callAction('note', data: ['body' => 'Lager angerufen', 'minutes' => 10])
        ->assertHasNoActionErrors()
        ->callAction('time', data: ['minutes' => 65, 'worked_on' => '2026-11-09', 'note' => 'Recherche'])
        ->assertHasNoActionErrors()
        ->callAction('note', data: ['body' => 'Ohne Zeit'])
        ->assertSee('Zeit: 1:35 Std.')
        ->assertSee('Recherche');

    $entries = app(TenantContext::class)->runWithoutTenancy(fn () => TimeEntry::orderBy('id')->get());
    expect($entries->pluck('minutes')->all())->toBe([20, 10, 65])
        ->and($entries[0]->ticket_message_id)->not->toBeNull()
        ->and($entries[2]->worked_on->toDateString())->toBe('2026-11-09')
        ->and($entries->every(fn ($e) => $e->user_id === $this->agent->id))->toBeTrue();
});

it('zählt die Zeit im Monatsbericht und exportiert die Buchungen', function () {
    app(TenantContext::class)->runFor($this->tenant, function () {
        $this->ticket->logTime(90, $this->agent, 'Einrichtung');
        $this->ticket->logTime(30, $this->agent, 'Vormonat', '2026-10-31');
    });

    $rows = app(MonthlyReport::class)->rows('2026-11');
    expect($rows[0]['time_h'])->toBe(1.5);

    $csv = app(MonthlyReport::class)->timeCsv('2026-11');
    expect($csv)->toContain('10.11.2026;"Firma A";FA-')->toContain(';90;Einrichtung')->not->toContain('Vormonat');

    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Livewire::withQueryParams(['monat' => '2026-11'])->test(Reports::class)
        ->assertSee('Erfasste Zeit')
        ->callAction('timeCsv')->assertFileDownloaded('anliego-zeiten-2026-11.csv');
});
