<?php

use App\Enums\MembershipRole;
use App\Mail\Inbound\InboundMailProcessor;
use App\Mail\Outbound\MailboxTransportFactory;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketRule;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RecordingTransport;

beforeEach(function () {
    Storage::fake('local');
    app()->instance(MailboxTransportFactory::class, new RecordingTransport);

    $this->tenant = Tenant::factory()->create(['short_code' => 'FA']);
    $this->team = teamFor($this->tenant, 'Auftragsbearbeitung');
    $this->mailbox = mailboxFor($this->tenant, ['email' => 'bestellung@firma-a.de', 'team_id' => $this->team->id]);
    $this->anna = memberOf($this->tenant);
    $this->ben = memberOf($this->tenant);
    teamTenant(fn () => [$this->team->addMember($this->anna), $this->team->addMember($this->ben)]);
});

function teamTenant(Closure $fn)
{
    return app(TenantContext::class)->runFor(test()->tenant, $fn);
}

function setTeamMode(string $mode): void
{
    teamTenant(fn () => test()->team->update(['assignment_mode' => $mode]));
}

/** Neue E-Mail an das Team-Postfach; liefert den Bearbeiter des entstandenen Tickets */
function newTicketAssignee(): ?int
{
    app(InboundMailProcessor::class)->process(test()->mailbox, rawMail(['subject' => 'Bestellung', 'message_id' => uniqid().'@kunde.de']));

    return app(TenantContext::class)->runWithoutTenancy(fn () => Ticket::query()->latest('id')->first())->assignee_id;
}

it('weist bei „Manuell“ niemanden zu', function () {
    expect(newTicketAssignee())->toBeNull();
});

it('verteilt reihum der Reihe nach und fängt wieder von vorn an', function () {
    setTeamMode(Team::ROUND_ROBIN);

    expect([newTicketAssignee(), newTicketAssignee(), newTicketAssignee()])
        ->toBe([$this->anna->id, $this->ben->id, $this->anna->id]);
});

it('gibt nach Auslastung dem Mitglied mit den wenigsten offenen Tickets', function () {
    setTeamMode(Team::LOAD);

    expect([newTicketAssignee(), newTicketAssignee(), newTicketAssignee()])
        ->toBe([$this->anna->id, $this->ben->id, $this->anna->id]);

    // Anna hat jetzt zwei offene, Ben eines → Ben ist dran
    expect(newTicketAssignee())->toBe($this->ben->id);
});

it('übergeht Mitglieder ohne „bekommt Zuweisungen“, inaktive und Light-Agents', function () {
    setTeamMode(Team::ROUND_ROBIN);
    $light = memberOf();
    $light->memberships()->create(['tenant_id' => $this->tenant->id, 'role' => MembershipRole::LightAgent]);
    teamTenant(function () use ($light) {
        $this->team->users()->updateExistingPivot($this->anna->id, ['receives_assignments' => false]);
        $this->team->addMember($light);
    });
    $this->ben->update(['active' => false]);

    expect(newTicketAssignee())->toBeNull();
});

it('lässt eine Regel mit eigener Zuweisung vorgehen', function () {
    setTeamMode(Team::ROUND_ROBIN);
    TicketRule::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Ben', 'match' => 'all',
        'conditions' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'Bestellung']],
        'assign' => 'user', 'assignee_id' => $this->ben->id,
    ]);

    expect(newTicketAssignee())->toBe($this->ben->id);
});
