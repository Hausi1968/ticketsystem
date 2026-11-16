<?php

use App\Enums\MembershipRole;
use App\Enums\TicketPriority;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Mail\Inbound\InboundMailProcessor;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketStatus;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->a = Tenant::factory()->create(['short_code' => 'FA']);
    $this->b = Tenant::factory()->create(['short_code' => 'VB']);
    $boxA = mailboxFor($this->a, ['email' => 'a@firma-a.de']);
    $boxB = mailboxFor($this->b, ['email' => 'b@verein-b.de']);
    foreach (['Eins', 'Zwei'] as $s) {
        app(InboundMailProcessor::class)->process($boxA, rawMail(['subject' => "A $s", 'message_id' => "<a-$s@x>"]));
    }
    app(InboundMailProcessor::class)->process($boxB, rawMail(['subject' => 'B Eins', 'message_id' => '<b-1@x>']));

    $this->ticketsA = app(TenantContext::class)->runFor($this->a, fn () => Ticket::query()->orderBy('id')->get());
    $this->ticketB = app(TenantContext::class)->runFor($this->b, fn () => Ticket::sole());
    $this->agent = memberOf($this->a, $this->b);
});

it('übernimmt mehrere Tickets auf einmal', function () {
    $this->actingAs($this->agent);

    Livewire::test(ListTickets::class, ['activeTab' => 'all'])
        ->callTableBulkAction('bulkTake', [...$this->ticketsA, $this->ticketB]);

    expect(Ticket::query()->withoutGlobalScopes()->where('assignee_id', $this->agent->id)->count())->toBe(3);
});

it('weist nur Tickets der Mandanten zu, die die Person betreut', function () {
    $onlyA = memberOf($this->a);
    $this->actingAs($this->agent);

    Livewire::test(ListTickets::class, ['activeTab' => 'all'])
        ->callTableBulkAction('bulkAssign', [...$this->ticketsA, $this->ticketB], data: ['assignee_id' => $onlyA->id])
        ->assertNotified('Zugewiesen: 2 Ticket(s) geändert');

    expect($this->ticketsA->map(fn ($t) => $t->fresh()->assignee_id)->unique()->all())->toBe([$onlyA->id])
        ->and($this->ticketB->fresh()->assignee_id)->toBeNull();
});

it('setzt Status, Priorität und Kategorie für mehrere Tickets', function () {
    $this->actingAs($this->agent);
    $closed = TicketStatus::system(TicketStatus::SOLVED);
    $catA = TicketCategory::create(['tenant_id' => $this->a->id, 'name' => 'Bestellung']);
    $all = [...$this->ticketsA, $this->ticketB];

    Livewire::test(ListTickets::class, ['activeTab' => 'all'])
        ->callTableBulkAction('bulkStatus', $all, data: ['status_id' => $closed->id])
        ->callTableBulkAction('bulkPriority', $all, data: ['priority' => TicketPriority::High->value])
        ->callTableBulkAction('bulkCategory', $all, data: ['category_id' => $catA->id]);

    foreach ($all as $ticket) {
        $fresh = Ticket::query()->withoutGlobalScopes()->find($ticket->id);
        expect($fresh->status_id)->toBe($closed->id)
            ->and($fresh->priority)->toBe(TicketPriority::High);
    }
    expect($this->ticketsA->every(fn ($t) => $t->fresh()->category_id === $catA->id))->toBeTrue()
        ->and(Ticket::query()->withoutGlobalScopes()->find($this->ticketB->id)->category_id)->toBeNull();
});

it('überspringt Tickets, die ein Light-Agent nicht bearbeiten darf', function () {
    $light = memberOf();
    $light->memberships()->create(['tenant_id' => $this->a->id, 'role' => MembershipRole::LightAgent]);
    $light->memberships()->create(['tenant_id' => $this->b->id, 'role' => MembershipRole::Agent]);
    $this->actingAs($light);

    Livewire::test(ListTickets::class, ['activeTab' => 'all'])
        ->callTableBulkAction('bulkTake', [...$this->ticketsA, $this->ticketB])
        ->assertNotified('Übernommen: 1 Ticket(s) geändert');

    expect(Ticket::query()->withoutGlobalScopes()->where('assignee_id', $light->id)->pluck('id')->all())->toBe([$this->ticketB->id]);
});
