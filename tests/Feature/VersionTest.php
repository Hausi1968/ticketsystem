<?php

use App\Models\AgentToken;
use App\Models\Tenant;
use App\Models\User;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['short_code' => 'FA']);
});

it('zeigt die Web-Version unten auf jeder Seite und auf der Seite „Versionen“', function () {
    $this->actingAs(memberOf($this->tenant));

    $this->get('/')->assertOk()->assertSee('Anliego '.config('anliego.version'));
    $this->get('/versionen')->assertOk()->assertSee('Weboberfläche')->assertSee('Mitarbeiter-App');
});

it('merkt sich die App-Version des Geräts und liefert der App die Server-Version', function () {
    $user = memberOf($this->tenant);
    [, $plain] = AgentToken::issue($user, 'Android-App');

    $this->withHeaders([
        'Authorization' => "Bearer {$plain}", 'Accept' => 'application/json',
        'X-App-Version' => '1.0.0', 'X-App-Platform' => 'android', 'X-App-Update' => '18.11.2026',
    ])->getJson('/api/agent/v1/me')
        ->assertOk()
        ->assertJsonPath('server.version', config('anliego.version'));

    $token = AgentToken::sole();
    expect($token->app_version)->toBe('1.0.0')
        ->and($token->app_platform)->toBe('android')
        ->and($token->app_update)->toBe('18.11.2026');

    $this->actingAs($user)->get('/versionen')->assertSee('Android-App')->assertSee('1.0.0');
});

it('zeigt normalen Mitarbeitern nur die eigenen Geräte, Administratoren alle', function () {
    $anna = memberOf($this->tenant);
    $ben = memberOf($this->tenant);
    AgentToken::issue($anna, 'Annas Handy');
    AgentToken::issue($ben, 'Bens Handy');

    $this->actingAs($anna)->get('/versionen')->assertSee('Annas Handy')->assertDontSee('Bens Handy');
    $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/versionen')->assertSee('Annas Handy')->assertSee('Bens Handy');
});
