<?php

use App\Enums\StatusBehaviour;
use App\Filament\Resources\TicketCategories\Pages\ManageTicketCategories;
use App\Filament\Resources\TicketStatuses\Pages\ManageTicketStatuses;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Mail\Inbound\InboundMailProcessor;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketStatus;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->tenant = Tenant::factory()->create(['short_code' => 'FM']);
    $this->other = Tenant::factory()->create(['short_code' => 'XY']);
    $this->mailbox = mailboxFor($this->tenant, ['email' => 'auftrag@firma.de']);
    app(InboundMailProcessor::class)->process($this->mailbox, rawMail(['id' => 'k1@kunde.de']));

    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin);
    $this->ticket = Ticket::sole();
});

it('lässt Administratoren eigene Status anlegen und festlegen, ob sie das Ticket schließen', function () {
    Livewire::test(ManageTicketStatuses::class)
        ->callAction('create', data: ['name' => 'Ware versendet', 'color' => 'success', 'behaviour' => 'closed', 'sort' => 70, 'active' => true])
        ->assertHasNoActionErrors();

    $status = TicketStatus::where('name', 'Ware versendet')->sole();
    expect($status->closes())->toBeTrue();

    $this->ticket->changeStatus($status, $this->admin);

    expect($this->ticket->fresh()->isClosed())->toBeTrue()
        ->and($this->ticket->fresh()->closed_at)->not->toBeNull();

    Livewire::test(ListTickets::class, ['activeTab' => 'open'])->assertCanNotSeeTableRecords([$this->ticket]);
    Livewire::test(ListTickets::class, ['activeTab' => 'closed'])->assertCanSeeTableRecords([$this->ticket]);
});

it('hält Tickets mit einem nicht schließenden eigenen Status offen', function () {
    $status = TicketStatus::create(['name' => 'In Klärung', 'behaviour' => StatusBehaviour::Open]);

    $this->ticket->changeStatus($status, $this->admin);

    expect($this->ticket->fresh()->isClosed())->toBeFalse()
        ->and($this->ticket->fresh()->closed_at)->toBeNull();
    Livewire::test(ListTickets::class, ['activeTab' => 'open'])->assertCanSeeTableRecords([$this->ticket]);
});

it('öffnet ein Ticket mit schließendem Status bei Kundenantwort wieder, oder legt je nach Einstellung ein neues an', function () {
    $reopen = TicketStatus::create(['name' => 'Erledigt', 'behaviour' => StatusBehaviour::Closed]);
    $this->ticket->changeStatus($reopen, $this->admin);

    $r1 = app(InboundMailProcessor::class)->process($this->mailbox, rawMail(['in_reply_to' => '<k1@kunde.de>']));
    expect($r1->outcome)->toBe('reopened')
        ->and($this->ticket->fresh()->status->system_key)->toBe(TicketStatus::OPEN);

    $final = TicketStatus::create(['name' => 'Archiviert', 'behaviour' => StatusBehaviour::Closed, 'reply_creates_new_ticket' => true]);
    $this->ticket->changeStatus($final, $this->admin);

    $r2 = app(InboundMailProcessor::class)->process($this->mailbox, rawMail(['in_reply_to' => '<k1@kunde.de>']));
    expect($r2->outcome)->toBe('follow_up')
        ->and(Ticket::count())->toBe(2);
});

it('schützt die von Anliego genutzten Status vor Löschen und Verhaltensänderung', function () {
    $new = TicketStatus::system(TicketStatus::NEW);
    $new->update(['name' => 'Eingang', 'behaviour' => StatusBehaviour::Closed]);

    expect($new->fresh()->name)->toBe('Eingang')
        ->and($new->fresh()->behaviour)->toBe(StatusBehaviour::Open)
        ->and(fn () => $new->delete())->toThrow(LogicException::class);
});

it('lässt Kategorien für einen Mandanten oder alle Mandanten anlegen', function () {
    Livewire::test(ManageTicketCategories::class)
        ->callAction('create', data: ['name' => 'Bestellung', 'tenant_id' => $this->tenant->id, 'color' => 'info', 'sort' => 1, 'active' => true])
        ->assertHasNoActionErrors()
        ->callAction('create', data: ['name' => 'Allgemein', 'tenant_id' => null, 'color' => 'gray', 'sort' => 2, 'active' => true])
        ->assertHasNoActionErrors()
        ->callAction('create', data: ['name' => 'Beitrag', 'tenant_id' => $this->other->id, 'color' => 'gray', 'sort' => 3, 'active' => true])
        ->assertHasNoActionErrors();

    expect(TicketCategory::query()->forTenant($this->tenant->id)->ordered()->pluck('name')->all())
        ->toBe(['Bestellung', 'Allgemein']);
});

it('setzt die Kategorie im Ticket und verhindert Kategorien fremder Mandanten', function () {
    $own = TicketCategory::create(['name' => 'Reklamation', 'tenant_id' => $this->tenant->id]);
    $foreign = TicketCategory::create(['name' => 'Beitrag', 'tenant_id' => $this->other->id]);

    Livewire::test(ViewTicket::class, ['record' => $this->ticket->id])
        ->callAction('category', data: ['category_id' => $own->id])
        ->assertHasNoActionErrors();

    expect($this->ticket->fresh()->category_id)->toBe($own->id)
        ->and(fn () => $this->ticket->changeCategory($foreign))->toThrow(AuthorizationException::class);

    Livewire::test(ListTickets::class, ['activeTab' => 'all'])
        ->filterTable('category_id', [$own->id])
        ->assertCanSeeTableRecords([$this->ticket]);
});

it('erlaubt die Status- und Kategorienverwaltung nur Administratoren', function () {
    $agent = memberOf($this->tenant);

    $this->actingAs($agent)->get('/status')->assertForbidden();
    $this->actingAs($agent)->get('/kategorien')->assertForbidden();
    $this->actingAs($this->admin)->get('/status')->assertOk()->assertSee('Wartet auf Kunde');
    $this->actingAs($this->admin)->get('/kategorien')->assertOk();
});
