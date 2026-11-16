<?php

use App\Enums\TicketPriority;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Mail\Inbound\InboundMailProcessor;
use App\Models\SavedView;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->a = Tenant::factory()->create(['short_code' => 'FA']);
    $this->b = Tenant::factory()->create(['short_code' => 'VB']);
    app(InboundMailProcessor::class)->process(mailboxFor($this->a, ['email' => 'a@firma-a.de']), rawMail(['subject' => 'Bestellung A']));
    app(InboundMailProcessor::class)->process(mailboxFor($this->b, ['email' => 'b@verein-b.de']), rawMail(['subject' => 'Frage B']));
    $this->ticketA = app(TenantContext::class)->runFor($this->a, fn () => Ticket::sole());
    $this->ticketB = app(TenantContext::class)->runFor($this->b, fn () => Ticket::sole());
    $this->agent = memberOf($this->a, $this->b);
    $this->actingAs($this->agent);
});

it('speichert die aktuelle Ansicht als eigenen Reiter und wendet die Filter wieder an', function () {
    Livewire::test(ListTickets::class, ['activeTab' => 'all'])
        ->filterTable('tenant_id', $this->a->id)
        ->callAction('saveView', data: ['name' => 'Firma A'])
        ->assertNotified('Ansicht „Firma A“ gespeichert');

    $view = SavedView::sole();
    expect($view->user_id)->toBe($this->agent->id)
        ->and($view->base_tab)->toBe('all')
        ->and($view->filters['tenant_id']['value'] ?? null)->toEqual($this->a->id);

    // Neu öffnen: zuerst alle, mit dem Reiter der Ansicht nur Firma A
    Livewire::test(ListTickets::class, ['activeTab' => 'all'])
        ->assertCanSeeTableRecords([$this->ticketA, $this->ticketB])
        ->set('activeTab', $view->tabKey())
        ->assertCanSeeTableRecords([$this->ticketA])
        ->assertCanNotSeeTableRecords([$this->ticketB])
        ->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$this->ticketA, $this->ticketB]);

    // Direkt mit der Ansicht geöffnet (z. B. per Link)
    Livewire::test(ListTickets::class, ['activeTab' => $view->tabKey()])
        ->assertCanSeeTableRecords([$this->ticketA])
        ->assertCanNotSeeTableRecords([$this->ticketB]);
});

it('zeigt gespeicherte Ansichten nur ihrem Besitzer', function () {
    SavedView::create(['user_id' => $this->agent->id, 'name' => 'Meine Spezialansicht', 'base_tab' => 'all']);

    Livewire::test(ListTickets::class)->assertSee('Meine Spezialansicht');

    $this->actingAs(memberOf($this->a));
    Livewire::test(ListTickets::class)->assertDontSee('Meine Spezialansicht');
});

it('aktualisiert und löscht eine Ansicht', function () {
    $view = SavedView::create(['user_id' => $this->agent->id, 'name' => 'Dringend', 'base_tab' => 'all']);
    $this->ticketA->changePriority(TicketPriority::High);

    Livewire::test(ListTickets::class, ['activeTab' => $view->tabKey()])
        ->filterTable('priority', TicketPriority::High->value)
        ->callAction('updateView')
        ->assertCanSeeTableRecords([$this->ticketA])
        ->assertCanNotSeeTableRecords([$this->ticketB]);

    expect($view->fresh()->filters['priority']['value'] ?? null)->toBe('high');

    Livewire::test(ListTickets::class, ['activeTab' => $view->tabKey()])
        ->callAction('deleteView');
    expect(SavedView::count())->toBe(0);
});
