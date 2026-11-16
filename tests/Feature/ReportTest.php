<?php

use App\Filament\Pages\Reports;
use App\Mail\Inbound\InboundMailProcessor;
use App\Mail\Outbound\MailboxTransportFactory;
use App\Mail\Outbound\ReplySender;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use App\Reports\MonthlyReport;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\RecordingTransport;

beforeEach(function () {
    Storage::fake('local');
    app()->instance(MailboxTransportFactory::class, new RecordingTransport);
    $this->travelTo(now()->setDate(2026, 10, 5)->setTime(9, 0));

    $this->a = Tenant::factory()->create(['name' => 'Firma A', 'short_code' => 'FA']);
    $this->b = Tenant::factory()->create(['name' => 'Verein B', 'short_code' => 'VB']);
    $this->mailbox = mailboxFor($this->a, ['email' => 'info@firma-a.de']);
    $this->agent = memberOf($this->a);

    // Zwei Tickets im Oktober: eines nach 2 Std. beantwortet und nach 4 Std. gelöst, eines offen
    app(InboundMailProcessor::class)->process($this->mailbox, rawMail(['subject' => 'Eins']));
    app(InboundMailProcessor::class)->process($this->mailbox, rawMail(['subject' => 'Zwei', 'from' => 'max@kunde.de']));
    $this->first = app(TenantContext::class)->runWithoutTenancy(fn () => Ticket::where('subject', 'Eins')->sole());

    $this->travel(2)->hours();
    app(TenantContext::class)->runFor($this->a, fn () => app(ReplySender::class)->reply($this->first, $this->agent, 'Erledigt.'));
    $this->travel(2)->hours();
    app(TenantContext::class)->runFor($this->a, fn () => $this->first->fresh()->changeStatus(TicketStatus::SOLVED, $this->agent));
});

it('zählt Tickets, Antworten und Zeiten je Mandant und Monat', function () {
    $rows = app(MonthlyReport::class)->rows('2026-10');

    expect($rows)->toHaveCount(3) // A, B, Gesamt
        ->and($rows[0])->toMatchArray([
            'tenant' => 'Firma A', 'created' => 2, 'via_email' => 2, 'closed' => 1, 'open_now' => 1, 'replies' => 1,
            'avg_first_response_h' => 2.0, 'avg_resolution_h' => 4.0,
        ])
        ->and($rows[1])->toMatchArray(['tenant' => 'Verein B', 'created' => 0, 'avg_first_response_h' => null])
        ->and($rows[2])->toMatchArray(['tenant' => 'Gesamt', 'created' => 2, 'avg_resolution_h' => 4.0]);

    expect(app(MonthlyReport::class)->rows('2026-09')[0]['created'])->toBe(0)
        ->and(app(MonthlyReport::class)->rows('2026-10', $this->b->id))->toHaveCount(1);
});

it('zeigt den Bericht und exportiert CSV', function () {
    $this->actingAs($this->agent)->get('/berichte')->assertForbidden();

    $this->actingAs(User::factory()->create(['is_admin' => true]));
    $this->get('/berichte?monat=2026-10')->assertOk()->assertSee('Oktober 2026')->assertSee('Firma A');

    Livewire::withQueryParams(['monat' => '2026-10'])->test(Reports::class)
        ->callAction('previous')->assertSet('month', '2026-09')
        ->callAction('csv')->assertFileDownloaded('anliego-bericht-2026-09.csv');

    $csv = app(MonthlyReport::class)->csv(app(MonthlyReport::class)->rows('2026-10'));
    expect($csv)->toContain('Mandant;"Neue Tickets"')->toContain('"Firma A";2;2;0;1;1;1;2,0;4,0;0;');
});
