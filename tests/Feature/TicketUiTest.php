<?php

use App\Enums\MessageType;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Tickets\TicketResource;
use App\Mail\Inbound\InboundMailProcessor;
use App\Mail\Outbound\MailboxTransportFactory;
use App\Models\Attachment;
use App\Models\CannedResponse;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketStatus;
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
        ->and($this->ticketA->fresh()->status->system_key)->toBe(TicketStatus::OPEN);
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
        ->callAction('reply', data: ['body' => 'Danke, ist notiert.', 'cc' => [], 'status_id' => TicketStatus::system(TicketStatus::WAITING)->id])
        ->assertHasNoActionErrors();

    expect($this->smtp->sent)->toHaveCount(1)
        ->and($this->smtp->last()->getTo()[0]->getAddress())->toBe('erika@kunde.de')
        ->and($this->ticketA->fresh()->status->system_key)->toBe(TicketStatus::WAITING);
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

    $results = TicketResource::getGlobalSearchResults('#1000');
    expect($results)->toHaveCount(1)
        ->and($results->first()->url)->toEndWith("/tickets/{$this->ticketA->id}");
});

it('findet über die Nummer keine Tickets fremder Mandanten', function () {
    $this->actingAs($this->agentA);

    expect(TicketResource::getGlobalSearchResults('VB-1001'))->toHaveCount(0);
});

it('zeigt eine verständliche Meldung statt eines Serverfehlers, wenn die Antwort nicht gespeichert werden kann', function () {
    $this->actingAs($this->agentA);
    $this->mailboxA->forceFill(['active' => false])->saveQuietly();
    app(TenantContext::class)->runFor($this->a, fn () => $this->ticketA->forceFill(['mailbox_id' => null])->saveQuietly());

    Livewire::test(ViewTicket::class, ['record' => $this->ticketA->id])
        ->callAction('reply', data: ['body' => 'Hallo', 'cc' => [], 'status_id' => TicketStatus::system(TicketStatus::WAITING)->id])
        ->assertNotified('Antwort nicht gesendet');

    expect($this->smtp->sent)->toBe([])
        ->and($this->ticketA->messages()->where('type', 'reply')->count())->toBe(0);
});

it('fügt Textbausteine des Mandanten mit ausgefüllten Platzhaltern ein', function () {
    $own = CannedResponse::create(['tenant_id' => $this->a->id, 'title' => 'Eingang', 'body' => 'Hallo {name}, Ihre Bestellung {nummer} ist da. Gruß {mitarbeiter}']);
    $foreign = CannedResponse::create(['tenant_id' => $this->b->id, 'title' => 'Fremd', 'body' => 'geheim']);
    $this->actingAs($this->agentA);

    $page = Livewire::test(ViewTicket::class, ['record' => $this->ticketA->id])
        ->mountAction('reply')
        ->set('mountedActions.0.data.body', 'Vorab:')
        ->set('mountedActions.0.data.canned', (string) $own->id);

    expect($page->get('mountedActions.0.data.body'))
        ->toBe("Vorab:\n\nHallo Erika Muster, Ihre Bestellung ".$this->ticketA->reference().' ist da. Gruß '.$this->agentA->name);

    $page->set('mountedActions.0.data.canned', (string) $foreign->id);
    expect($page->get('mountedActions.0.data.body'))->not->toContain('geheim');
});

it('sortiert die Ticketliste nach Kategorie, Status, Bearbeiter und Betreff', function () {
    $this->actingAs(memberOf($this->a, $this->b));
    $zebra = TicketCategory::create(['name' => 'Zebra']);
    $apfel = TicketCategory::create(['name' => 'Apfel']);
    app(TenantContext::class)->runWithoutTenancy(function () use ($zebra, $apfel) {
        $this->ticketA->update(['category_id' => $zebra->id]);
        $this->ticketB->update(['category_id' => $apfel->id]);
    });

    Livewire::test(ListTickets::class)
        ->sortTable('category.name')->assertCanSeeTableRecords([$this->ticketB, $this->ticketA], inOrder: true)
        ->sortTable('category.name', 'desc')->assertCanSeeTableRecords([$this->ticketA, $this->ticketB], inOrder: true)
        ->sortTable('subject')->assertCanSeeTableRecords([$this->ticketA, $this->ticketB], inOrder: true)
        ->sortTable('status.name')->assertSuccessful()
        ->sortTable('assignee.name')->assertSuccessful()
        ->sortTable('number')->assertSuccessful();
});

it('findet Tickets über Texte in Nachrichten, Notizen und den Absender', function () {
    $this->actingAs(memberOf($this->a, $this->b));
    app(TenantContext::class)->runFor($this->a, fn () => $this->ticketA->messages()->create([
        'tenant_id' => $this->a->id, 'type' => MessageType::Note, 'user_id' => $this->agentA->id,
        'body_text' => 'Lieferung an den Musterweg 12',
    ]));

    Livewire::test(ListTickets::class, ['activeTab' => 'all'])
        ->searchTable('musterweg')
        ->assertCanSeeTableRecords([$this->ticketA])
        ->assertCanNotSeeTableRecords([$this->ticketB])
        ->searchTable('Kisten')
        ->assertCanSeeTableRecords([$this->ticketA, $this->ticketB])
        ->searchTable('Kisten Musterweg')
        ->assertCanSeeTableRecords([$this->ticketA])
        ->assertCanNotSeeTableRecords([$this->ticketB])
        ->searchTable($this->ticketA->contact->email)
        ->assertCanSeeTableRecords([$this->ticketA])
        ->searchTable('Gibtsnicht')
        ->assertCanNotSeeTableRecords([$this->ticketA, $this->ticketB]);

    expect(TicketResource::getGlobalSearchResults('Musterweg'))->toHaveCount(1)
        ->and(TicketResource::getGlobalSearchResults('Kisten'))->toHaveCount(2);
});

it('findet über Nachrichtentexte keine Tickets fremder Mandanten', function () {
    $this->actingAs($this->agentA);

    Livewire::test(ListTickets::class, ['activeTab' => 'all'])
        ->searchTable('Kisten')
        ->assertCanSeeTableRecords([$this->ticketA])
        ->assertCanNotSeeTableRecords([$this->ticketB]);
    expect(TicketResource::getGlobalSearchResults('Kisten'))->toHaveCount(1);
});
