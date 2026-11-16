<?php

use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->a = Tenant::factory()->create(['name' => 'Mandant A']);
    $this->b = Tenant::factory()->create(['name' => 'Mandant B']);
    $this->teamA = teamFor($this->a, 'Team A');
    $this->teamB = teamFor($this->b, 'Team B');
});

it('zeigt ohne Anmeldung keine Mandantendaten', function () {
    expect(Team::count())->toBe(0);
});

it('zeigt einem Mitarbeiter nur die Daten seiner Mandanten', function () {
    $this->actingAs(memberOf($this->a));

    expect(Team::pluck('name')->all())->toBe(['Team A'])
        ->and(Team::find($this->teamB->id))->toBeNull();
});

it('zeigt einem Mitarbeiter mit mehreren Mandanten alle seine Mandanten', function () {
    $this->actingAs(memberOf($this->a, $this->b));

    expect(Team::count())->toBe(2);
});

it('zeigt einem Administrator alle Mandanten', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    expect(Team::count())->toBe(2);
});

it('zeigt inaktiven Mitarbeitern nichts', function () {
    $user = memberOf($this->a);
    $user->update(['active' => false]);
    $this->actingAs($user);

    expect(Team::count())->toBe(0);
});

it('verhindert das Anlegen von Daten in einem fremden Mandanten', function () {
    $this->actingAs(memberOf($this->a));

    Team::create(['tenant_id' => $this->b->id, 'name' => 'Eingeschleust']);
})->throws(AuthorizationException::class);

it('setzt den Mandanten automatisch, wenn er eindeutig ist', function () {
    $this->actingAs(memberOf($this->a));

    $team = Team::create(['name' => 'Neu']);

    expect($team->tenant_id)->toBe($this->a->id);
});

it('verlangt einen Mandanten, wenn er nicht eindeutig ist', function () {
    $this->actingAs(memberOf($this->a, $this->b));

    Team::create(['name' => 'Ohne Mandant']);
})->throws(LogicException::class);

it('verhindert, dass ein Datensatz den Mandanten wechselt', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    $this->teamA->update(['tenant_id' => $this->b->id]);
})->throws(LogicException::class);

it('beschränkt runFor auf genau einen Mandanten, auch für Admins', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    $names = app(TenantContext::class)->runFor($this->b, fn () => Team::pluck('name')->all());

    expect($names)->toBe(['Team B'])->and(Team::count())->toBe(2);
});

it('erlaubt in der Datenbank nur Teammitglieder desselben Mandanten', function () {
    $userOnlyInB = memberOf($this->b);

    $this->teamA->addMember($userOnlyInB);
})->throws(QueryException::class);

it('nimmt Teammitglieder desselben Mandanten auf', function () {
    $user = memberOf($this->a);
    $this->teamA->addMember($user);

    expect($this->teamA->users()->count())->toBe(1);
});
