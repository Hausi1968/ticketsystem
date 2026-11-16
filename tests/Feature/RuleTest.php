<?php

use App\Enums\TicketPriority;
use App\Filament\Resources\TicketRules\Pages\ManageTicketRules;
use App\Mail\Inbound\InboundMailProcessor;
use App\Mail\Outbound\MailboxTransportFactory;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketRule;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\RecordingTransport;

beforeEach(function () {
    Storage::fake('local');
    app()->instance(MailboxTransportFactory::class, new RecordingTransport);

    $this->tenant = Tenant::factory()->create(['short_code' => 'FA']);
    $this->mailbox = mailboxFor($this->tenant, ['email' => 'info@firma-a.de']);
    $this->team = teamFor($this->tenant, 'Auftragsbearbeitung');
    $this->anna = memberOf($this->tenant);
    $this->ben = memberOf($this->tenant);
    app(TenantContext::class)->runFor($this->tenant, function () {
        $this->team->addMember($this->anna);
        $this->team->addMember($this->ben);
    });
});

function ruleIncoming(array $mail): Ticket
{
    app(InboundMailProcessor::class)->process(test()->mailbox, rawMail($mail));

    return app(TenantContext::class)->runWithoutTenancy(fn () => Ticket::query()->with('team', 'category')->latest('id')->first());
}

it('setzt Team, Kategorie und Priorität und verteilt reihum', function () {
    $category = TicketCategory::create(['name' => 'Bestellung', 'tenant_id' => $this->tenant->id]);
    TicketRule::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Bestellungen', 'match' => 'any',
        'conditions' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'Bestellung'],
            ['field' => 'body', 'operator' => 'contains', 'value' => 'Auftrag']],
        'team_id' => $this->team->id, 'assign' => 'round_robin', 'category_id' => $category->id, 'priority' => 'high',
    ]);

    $first = ruleIncoming(['subject' => 'Bestellung 4711']);
    $second = ruleIncoming(['subject' => 'Neuer Vorgang', 'body' => 'Anbei mein Auftrag', 'from' => 'max@kunde.de']);
    $other = ruleIncoming(['subject' => 'Rechnungsfrage', 'from' => 'eva@kunde.de']);

    expect($first->team_id)->toBe($this->team->id)
        ->and($first->category_id)->toBe($category->id)
        ->and($first->priority)->toBe(TicketPriority::High)
        ->and([$first->assignee_id, $second->assignee_id])->toEqualCanonicalizing([$this->anna->id, $this->ben->id])
        ->and($other->assignee_id)->toBeNull()
        ->and($other->category_id)->toBeNull();
});

it('prüft Regeln der Reihe nach und hört bei der ersten passenden auf', function () {
    TicketRule::create(['tenant_id' => $this->tenant->id, 'name' => 'Shop', 'sort' => 1,
        'conditions' => [['field' => 'from', 'operator' => 'ends_with', 'value' => '@shop.de']], 'assign' => 'user', 'assignee_id' => $this->ben->id]);
    TicketRule::create(['tenant_id' => null, 'name' => 'Alle dringend', 'sort' => 2,
        'conditions' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'dringend']], 'priority' => 'urgent']);

    $shop = ruleIncoming(['subject' => 'Dringend: Bestellung', 'from' => 'noreply@shop.de']);
    $urgent = ruleIncoming(['subject' => 'Dringend: Frage', 'from' => 'eva@kunde.de']);

    expect($shop->assignee_id)->toBe($this->ben->id)
        ->and($shop->priority)->toBe(TicketPriority::Normal)
        ->and($urgent->priority)->toBe(TicketPriority::Urgent)
        ->and($urgent->assignee_id)->toBeNull();
});

it('lässt Regeln anderer Mandanten und inaktive Regeln außen vor', function () {
    $other = Tenant::factory()->create(['short_code' => 'XY']);
    TicketRule::create(['tenant_id' => $other->id, 'name' => 'Fremd',
        'conditions' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'Bestellung']], 'priority' => 'urgent']);
    TicketRule::create(['tenant_id' => $this->tenant->id, 'name' => 'Aus', 'active' => false,
        'conditions' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'Bestellung']], 'priority' => 'low']);

    expect(ruleIncoming(['subject' => 'Bestellung'])->priority)->toBe(TicketPriority::Normal);
});

it('legt Regeln in der Verwaltung an', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    $this->get('/regeln')->assertOk();

    Livewire::test(ManageTicketRules::class)
        ->callAction('create', data: [
            'name' => 'Bestellungen', 'tenant_id' => $this->tenant->id, 'match' => 'all',
            'conditions' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'Bestellung']],
            'team_id' => $this->team->id, 'assign' => 'user', 'assignee_id' => $this->anna->id,
        ])
        ->assertHasNoActionErrors();

    expect(ruleIncoming(['subject' => 'Bestellung 1'])->assignee_id)->toBe($this->anna->id);
});
