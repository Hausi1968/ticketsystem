<?php

use App\Filament\Resources\Teams\Pages\EditTeam;
use App\Filament\Resources\Teams\RelationManagers\MembersRelationManager;
use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->other = Tenant::factory()->create();
    $this->team = teamFor($this->tenant, 'Auftragsbearbeitung');
    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

function membersOf($team)
{
    return Livewire::test(MembersRelationManager::class, ['ownerRecord' => $team, 'pageClass' => EditTeam::class]);
}

it('nimmt Mitarbeiter des Mandanten ins Team auf', function () {
    $anna = memberOf($this->tenant);

    membersOf($this->team)
        ->callAction(TestAction::make(AttachAction::class)->table(), ['recordId' => $anna->id, 'receives_assignments' => false])
        ->assertHasNoActionErrors();

    $pivot = $this->team->users()->whereKey($anna->id)->sole()->pivot;
    expect($pivot->tenant_id)->toBe($this->tenant->id)
        ->and((bool) $pivot->receives_assignments)->toBeFalse();
});

it('bietet nur Mitarbeiter des Mandanten an', function () {
    $fremd = memberOf($this->other);

    membersOf($this->team)
        ->callAction(TestAction::make(AttachAction::class)->table(), ['recordId' => $fremd->id])
        ->assertHasActionErrors(['recordId']);

    expect($this->team->users()->count())->toBe(0);
});

it('ändert „bekommt Zuweisungen“ und entfernt Mitglieder', function () {
    $anna = memberOf($this->tenant);
    $this->team->addMember($anna);

    membersOf($this->team)
        ->callAction(TestAction::make(EditAction::class)->table($anna), ['receives_assignments' => false])
        ->assertHasNoActionErrors();
    expect((bool) $this->team->users()->whereKey($anna->id)->sole()->pivot->receives_assignments)->toBeFalse();

    membersOf($this->team)->callAction(TestAction::make(DetachAction::class)->table($anna));
    expect($this->team->users()->count())->toBe(0);
});

it('zeigt die Mitglieder auf der Teamseite', function () {
    $this->team->addMember(memberOf($this->tenant));
    $this->get("/teams/{$this->team->id}/edit")->assertOk()->assertSeeLivewire(MembersRelationManager::class);
});
