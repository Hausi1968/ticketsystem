<?php

use App\Enums\MembershipRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TicketNumber;
use Illuminate\Support\Facades\Artisan;

it('leitet nicht angemeldete Besucher zur Anmeldung', function () {
    $this->get('/')->assertRedirect('/login');
});

it('zeigt die Anmeldeseite mit Namen Anliego', function () {
    $this->get('/login')->assertOk()->assertSee('Anliego');
});

it('lässt aktive Mitarbeiter auf die Übersicht', function () {
    $this->actingAs(User::factory()->create())->get('/')->assertOk();
});

it('verlinkt das Benutzerhandbuch als PDF', function () {
    expect(public_path('handbuch.pdf'))->toBeFile();
    $this->actingAs(User::factory()->create())->get('/')->assertOk()
        ->assertSee('Handbuch')->assertSee('handbuch.pdf?v=', false);
});

it('sperrt inaktive Mitarbeiter aus', function () {
    $this->actingAs(User::factory()->create(['active' => false]))->get('/')->assertForbidden();
});

it('erlaubt die Mandanten- und Mitarbeiterverwaltung nur Administratoren', function () {
    $agent = User::factory()->create();

    $this->actingAs($agent)->get('/mandanten')->assertForbidden();
    $this->actingAs($agent)->get('/mitarbeiter')->assertForbidden();

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin)->get('/mandanten')->assertOk();
    $this->actingAs($admin)->get('/mitarbeiter')->assertOk();
});

it('erlaubt die Teamverwaltung Supervisoren', function () {
    $tenant = Tenant::factory()->create();
    $supervisor = User::factory()->create();
    $supervisor->memberships()->create(['tenant_id' => $tenant->id, 'role' => MembershipRole::Supervisor]);

    $this->actingAs($supervisor)->get('/teams')->assertOk();
    $this->actingAs(User::factory()->create())->get('/teams')->assertForbidden();
});

it('vergibt Ticketnummern fortlaufend und über alle Mandanten eindeutig', function () {
    expect(TicketNumber::next())->toBe(1000)
        ->and(TicketNumber::next())->toBe(1001)
        ->and(TicketNumber::next())->toBe(1002);
});

it('liest Ticketnummern aus Eingaben', function () {
    expect(TicketNumber::parse('MUS-1000'))->toBe(1000)
        ->and(TicketNumber::parse('#1000'))->toBe(1000)
        ->and(TicketNumber::parse('[#MUS-1234]'))->toBe(1234)
        ->and(TicketNumber::parse('1000'))->toBe(1000)
        ->and(TicketNumber::parse('Bestellung'))->toBeNull();
});

it('führt Migrationen über die Deploy-Adresse nur mit gültigem Token aus', function () {
    $this->post('/_deploy/migrate')->assertNotFound();

    config(['anliego.deploy_token' => 'geheim']);
    Artisan::shouldReceive('call')->times(4);
    Artisan::shouldReceive('output')->andReturn('ok');

    $this->post('/_deploy/migrate', [], ['X-Deploy-Token' => 'falsch'])->assertNotFound();
    $this->post('/_deploy/migrate', [], ['X-Deploy-Token' => 'geheim'])->assertOk()->assertSee('migrate --force');
});

it('legt bei der Ersteinrichtung ohne SSH den ersten Administrator an', function () {
    config([
        'anliego.deploy_token' => 'geheim',
        'anliego.initial_admin.email' => 'chef@example.test',
        'anliego.initial_admin.password' => 'EinLangesPasswort1',
    ]);
    Artisan::shouldReceive('call')->times(8);
    Artisan::shouldReceive('output')->andReturn('ok');

    $this->post('/_deploy/migrate', [], ['X-Deploy-Token' => 'geheim'])->assertOk()->assertSee('chef@example.test');
    expect(User::where('email', 'chef@example.test')->first()?->is_admin)->toBeTrue();

    // zweiter Aufruf legt keinen weiteren an
    $this->post('/_deploy/migrate', [], ['X-Deploy-Token' => 'geheim'])->assertOk()->assertDontSee('angelegt');
    expect(User::count())->toBe(1);
});

it('zeigt oben einen gut sichtbaren Abmelde-Knopf', function () {
    $this->actingAs(User::factory()->create())->get('/')->assertOk()
        ->assertSee('anliego-logout', false)
        ->assertSee('action="'.url('/logout').'"', false);

    $this->post('/logout')->assertRedirect();
    $this->assertGuest();
});

it('macht die Zwei-Faktor-Anmeldung freiwillig, außer sie ist als Pflicht eingestellt', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/')->assertOk();

    expect(filament()->getPanel('app')->isMultiFactorAuthenticationRequired())->toBeFalse();
    config(['anliego.require_mfa' => true]);
    expect(filament()->getPanel('app')->isMultiFactorAuthenticationRequired())->toBeTrue();
});
