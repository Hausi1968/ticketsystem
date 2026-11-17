<?php

use App\Enums\MembershipRole;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Widgets\MyAssignments;
use App\Mail\Inbound\InboundMailProcessor;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->a = Tenant::factory()->create(['name' => 'Firma Alpha', 'short_code' => 'FA']);
    $this->b = Tenant::factory()->create(['name' => 'Verein Beta', 'short_code' => 'VB']);
    $this->c = Tenant::factory()->create(['name' => 'Club Gamma', 'short_code' => 'CG']);

    $this->teamA = teamFor($this->a, 'Auftragsbearbeitung');
    $this->teamB = teamFor($this->b, 'Mitgliederservice');
    $this->teamB2 = teamFor($this->b, 'Kasse');

    app(InboundMailProcessor::class)->process(mailboxFor($this->a, ['email' => 'a@firma-a.de', 'team_id' => $this->teamA->id]), rawMail(['subject' => 'Bestellung A']));
    app(InboundMailProcessor::class)->process(mailboxFor($this->b, ['email' => 'b@verein-b.de']), rawMail(['subject' => 'Frage B']));
    $this->ticketA = app(TenantContext::class)->runFor($this->a, fn () => Ticket::sole());
    $this->ticketB = app(TenantContext::class)->runFor($this->b, fn () => Ticket::sole());
});

function blocksFor($user): array
{
    test()->actingAs($user);

    return collect((new MyAssignments)->getBlocks())->mapWithKeys(fn ($b) => [
        $b['heading'] => collect($b['items'])->pluck('label')->all(),
    ])->all();
}

it('zeigt meine Organisationen und Teams, Supervisor-Blöcke bleiben ohne Supervisor-Rolle leer', function () {
    $agent = memberOf($this->a, $this->b);
    $this->teamA->addMember($agent);

    $blocks = blocksFor($agent);

    expect($blocks['Meine Organisationen'])->toBe(['Firma Alpha', 'Verein Beta'])
        ->and($blocks['Meine Teams'])->toBe(['Auftragsbearbeitung'])
        ->and($blocks['Organisationen meiner Mitarbeiter'])->toBe([])
        ->and($blocks['Teams meiner Mitarbeiter'])->toBe([]);

    // Überschriften stehen trotzdem da
    Livewire::test(MyAssignments::class)
        ->assertSee('Organisationen meiner Mitarbeiter')
        ->assertSee('Teams meiner Mitarbeiter')
        ->assertSee('Firma Alpha')
        ->assertDontSee('Club Gamma');
});

it('zeigt Supervisoren die Mandanten, in denen sie Supervisor sind, und alle Teams dort', function () {
    $sv = memberOf($this->a);
    $sv->memberships()->create(['tenant_id' => $this->b->id, 'role' => MembershipRole::Supervisor]);
    $colleague = memberOf($this->b);
    $this->teamB->addMember($colleague);

    $blocks = blocksFor($sv);

    expect($blocks['Meine Organisationen'])->toBe(['Firma Alpha', 'Verein Beta'])
        ->and($blocks['Meine Teams'])->toBe([])
        ->and($blocks['Organisationen meiner Mitarbeiter'])->toBe(['Verein Beta'])
        ->and($blocks['Teams meiner Mitarbeiter'])->toBe(['Kasse', 'Mitgliederservice']);

    $supervised = collect((new MyAssignments)->getBlocks()[2]['items'])->first();
    expect($supervised['hint'])->toBe('1 Mitarbeiter')
        ->and($supervised['open'])->toBe(1);
});

it('öffnet per Klick die Ticketliste nur für diese Organisation bzw. dieses Team', function () {
    $agent = memberOf($this->a, $this->b);
    $this->actingAs($agent);

    $url = MyAssignments::ticketUrl('tenant_id', $this->a->id);
    expect(urldecode($url))->toContain('tab=open')->toContain('filters[tenant_id][value]='.$this->a->id);

    Livewire::withQueryParams(['tab' => 'open', 'filters' => ['tenant_id' => ['value' => $this->a->id]]])
        ->test(ListTickets::class)
        ->assertCanSeeTableRecords([$this->ticketA])
        ->assertCanNotSeeTableRecords([$this->ticketB]);

    Livewire::withQueryParams(['tab' => 'open', 'filters' => ['team_id' => ['value' => $this->teamA->id]]])
        ->test(ListTickets::class)
        ->assertCanSeeTableRecords([$this->ticketA])
        ->assertCanNotSeeTableRecords([$this->ticketB]);
});

it('zeigt die neue Zeile auf der Übersicht', function () {
    $this->actingAs(memberOf($this->a));

    $this->get('/')->assertOk();
    expect((new Dashboard)->getWidgets())->toContain(MyAssignments::class);
});
