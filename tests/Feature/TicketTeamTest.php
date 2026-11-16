<?php

use App\Enums\MessageType;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Mail\Inbound\InboundMailProcessor;
use App\Mail\Outbound\MailboxTransportFactory;
use App\Models\AgentToken;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\RecordingTransport;

beforeEach(function () {
    Storage::fake('local');
    app()->instance(MailboxTransportFactory::class, new RecordingTransport);
    config(['anliego.require_mfa' => false]);

    $this->a = Tenant::factory()->create(['short_code' => 'FA']);
    $this->b = Tenant::factory()->create(['short_code' => 'VB']);
    $this->einkauf = teamFor($this->a, 'Einkauf');
    $this->reparatur = teamFor($this->a, 'Reparatur');
    $this->vorstand = teamFor($this->b, 'Vorstand');

    app(InboundMailProcessor::class)->process(mailboxFor($this->a, ['email' => 'info@firma-a.de']), rawMail(['subject' => 'Ersatzteil']));
    $this->ticket = app(TenantContext::class)->runFor($this->a, fn () => Ticket::sole());

    $this->anna = memberOf($this->a, $this->b);
    $this->ben = memberOf($this->a);
});

function inTenantA(Closure $fn)
{
    return app(TenantContext::class)->runFor(test()->a, $fn);
}

it('lässt einen Mitarbeiter mehreren Teams angehören, auch über Mandanten hinweg', function () {
    UserForm::syncTeams($this->anna, [$this->einkauf->id, $this->reparatur->id, $this->vorstand->id]);

    expect($this->anna->teams()->withoutGlobalScopes()->pluck('name')->sort()->values()->all())
        ->toBe(['Einkauf', 'Reparatur', 'Vorstand']);

    // Teams eines Mandanten, den der Mitarbeiter nicht betreut, werden übergangen
    UserForm::syncTeams($this->ben, [$this->einkauf->id, $this->vorstand->id]);
    expect($this->ben->teams()->withoutGlobalScopes()->pluck('name')->all())->toBe(['Einkauf']);
});

it('behält beim Speichern der Teams die Einstellung „bekommt Zuweisungen“', function () {
    inTenantA(fn () => $this->einkauf->addMember($this->anna, false));

    UserForm::syncTeams($this->anna, [$this->einkauf->id, $this->reparatur->id]);

    $pivot = $this->anna->teams()->withoutGlobalScopes()->get()->keyBy('name');
    expect((bool) $pivot['Einkauf']->pivot->receives_assignments)->toBeFalse()
        ->and((bool) $pivot['Reparatur']->pivot->receives_assignments)->toBeTrue();
});

it('ordnet ein Ticket einem Team zu und protokolliert das', function () {
    inTenantA(fn () => $this->ticket->changeTeam($this->einkauf, $this->anna));

    $ticket = inTenantA(fn () => $this->ticket->fresh());
    expect($ticket->team_id)->toBe($this->einkauf->id)
        ->and($ticket->assignee_id)->toBeNull()
        ->and(inTenantA(fn () => $ticket->events()->where('type', 'team')->sole()->data))->toBe(['from' => null, 'to' => 'Einkauf']);
});

it('verteilt nach der Team-Zuordnung automatisch, wenn das Team das so eingestellt hat', function () {
    inTenantA(function () {
        $this->reparatur->update(['assignment_mode' => Team::ROUND_ROBIN]);
        $this->reparatur->addMember($this->ben);
        $this->ticket->changeTeam($this->reparatur, $this->anna);
    });

    expect(inTenantA(fn () => $this->ticket->fresh()->assignee_id))->toBe($this->ben->id);
});

it('lehnt ein Team eines anderen Mandanten ab', function () {
    inTenantA(fn () => $this->ticket->changeTeam($this->vorstand, $this->anna));
})->throws(AuthorizationException::class);

it('setzt Team und Bearbeiter in der Weboberfläche', function () {
    $this->actingAs($this->anna);

    Livewire::test(ViewTicket::class, ['record' => $this->ticket->id])
        ->callAction('assign', data: ['team_id' => $this->einkauf->id, 'assignee_id' => $this->ben->id])
        ->assertHasNoActionErrors();

    $ticket = inTenantA(fn () => $this->ticket->fresh());
    expect($ticket->team_id)->toBe($this->einkauf->id)->and($ticket->assignee_id)->toBe($this->ben->id);
});

it('zeigt unter „Meine Teams“ die offenen Tickets der eigenen Teams', function () {
    inTenantA(function () {
        $this->einkauf->addMember($this->anna);
        $this->ticket->changeTeam($this->einkauf);
    });
    $this->actingAs($this->anna);

    Livewire::test(ListTickets::class, ['activeTab' => 'teams'])->assertCanSeeTableRecords([$this->ticket]);

    $this->actingAs($this->ben);
    Livewire::test(ListTickets::class, ['activeTab' => 'teams'])->assertCanNotSeeTableRecords([$this->ticket]);
});

it('zeigt den Verlauf standardmäßig mit der neuesten Nachricht oben und merkt sich die Umkehr', function () {
    $this->travel(5)->minutes();
    inTenantA(fn () => $this->ticket->messages()->create([
        'tenant_id' => $this->a->id, 'type' => MessageType::Note, 'user_id' => $this->anna->id, 'body_text' => 'Rueckfrage zur Lieferzeit',
    ]));
    $this->actingAs($this->anna);

    $page = Livewire::test(ViewTicket::class, ['record' => $this->ticket->id])
        ->assertSet('newestFirst', true)
        ->assertSeeInOrder(['Rueckfrage zur Lieferzeit', 'ich bestelle 3 Kisten']);

    $page->call('toggleOrder')->assertSet('newestFirst', false)->assertSeeInOrder(['ich bestelle 3 Kisten', 'Rueckfrage zur Lieferzeit']);
    expect(session('ticket_order'))->toBe('oldest');

    Livewire::test(ViewTicket::class, ['record' => $this->ticket->id])->assertSet('newestFirst', false);
});

it('setzt das Team über die App und liefert die Teams in den Auswahllisten', function () {
    [, $token] = AgentToken::issue($this->anna, 'Test');
    $api = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);

    $api->getJson('/api/agent/v1/options')->assertOk()
        ->assertJsonFragment(['name' => 'Einkauf', 'tenant_id' => $this->a->id])
        ->assertJsonFragment(['key' => 'teams', 'label' => 'Meine Teams']);

    $api->postJson("/api/agent/v1/tickets/{$this->ticket->id}/team", ['team_id' => $this->vorstand->id])->assertStatus(422);
    $api->postJson("/api/agent/v1/tickets/{$this->ticket->id}/team", ['team_id' => $this->einkauf->id])
        ->assertOk()->assertJsonPath('ticket.team.name', 'Einkauf');
});
