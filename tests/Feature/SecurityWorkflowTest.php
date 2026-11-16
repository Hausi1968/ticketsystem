<?php

use App\Filament\Pages\SecurityLogPage;
use App\Filament\Pages\Versions;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Mail\Inbound\InboundMailProcessor;
use App\Mail\Outbound\MailboxTransportFactory;
use App\Mail\Outbound\ReplySender;
use App\Models\AgentToken;
use App\Models\SecurityEvent;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketActivity;
use App\Tenancy\TenantContext;
use App\Tickets\Mentions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\RecordingTransport;

beforeEach(function () {
    Storage::fake('local');
    app()->instance(MailboxTransportFactory::class, new RecordingTransport);
    config(['anliego.require_mfa' => false]);

    $this->a = Tenant::factory()->create(['short_code' => 'FA']);
    $this->b = Tenant::factory()->create(['short_code' => 'VB']);
    $this->mailbox = mailboxFor($this->a, ['email' => 'info@firma-a.de']);
    app(InboundMailProcessor::class)->process($this->mailbox, rawMail(['subject' => 'Ersatzteil']));
    $this->ticket = app(TenantContext::class)->runFor($this->a, fn () => Ticket::sole());

    $this->anna = memberOf($this->a);
    $this->anna->forceFill(['name' => 'Anna Beispiel', 'email' => 'anna@team.de', 'password' => 'geheim-12345'])->save();
    $this->ben = memberOf($this->a);
    $this->ben->forceFill(['name' => 'Ben Muster'])->save();
    $this->fremd = memberOf($this->b);
    $this->fremd->forceFill(['name' => 'Carla Fremd'])->save();
    $this->admin = User::factory()->create(['is_admin' => true, 'name' => 'Admin Chef']);
});

function tenantA(Closure $fn)
{
    return app(TenantContext::class)->runFor(test()->a, $fn);
}

function appApi(string $token)
{
    return test()->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
}

// ---------------------------------------------------------------- 1 App-Geräte

it('meldet ein App-Gerät über die Seite Versionen ab, aber nur eigene (außer Admin)', function () {
    [$mine, $plain] = AgentToken::issue($this->anna, 'Pixel');
    [$other] = AgentToken::issue($this->ben, 'Galaxy');

    $this->actingAs($this->anna);
    Livewire::test(Versions::class)->call('revokeDevice', $other->id)->call('revokeDevice', $mine->id);

    expect(AgentToken::query()->pluck('name')->all())->toBe(['Galaxy'])
        ->and(SecurityEvent::query()->where('type', 'device_revoked')->count())->toBe(1);
    appApi($plain)->getJson('/api/agent/v1/me')->assertUnauthorized();

    $this->actingAs($this->admin);
    Livewire::test(Versions::class)->call('revokeDevice', $other->id);
    expect(AgentToken::query()->count())->toBe(0);
});

it('lässt lange unbenutzte App-Anmeldungen verfallen', function () {
    [$token, $plain] = AgentToken::issue($this->anna, 'Altes Handy');
    appApi($plain)->getJson('/api/agent/v1/me')->assertOk();

    $token->forceFill(['last_used_at' => now()->subDays(91)])->save();
    appApi($plain)->getJson('/api/agent/v1/me')->assertUnauthorized();

    expect(AgentToken::query()->count())->toBe(0)
        ->and(SecurityEvent::query()->where('type', 'device_expired')->exists())->toBeTrue();
});

it('meldet bei einem neuen Passwort alle App-Geräte ab', function () {
    [, $plain] = AgentToken::issue($this->anna, 'Pixel');

    $this->anna->update(['password' => 'ganz-neu-98765']);

    appApi($plain)->getJson('/api/agent/v1/me')->assertUnauthorized();
    expect(SecurityEvent::query()->where('type', 'password_changed')->where('user_id', $this->anna->id)->exists())->toBeTrue();
});

// ---------------------------------------------------------------- 2 Kopfzeilen

it('setzt Sicherheits-Kopfzeilen', function () {
    $this->get('/app/login')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeaderMissing('Strict-Transport-Security');

    $this->get('https://localhost/app/login')->assertHeader('Strict-Transport-Security');
});

// ---------------------------------------------------------------- 3 Protokoll

it('protokolliert Anmeldungen der App, auch fehlgeschlagene', function () {
    $this->postJson('/api/agent/v1/login', ['email' => 'anna@team.de', 'password' => 'falsch'])->assertStatus(422);
    $this->postJson('/api/agent/v1/login', ['email' => 'anna@team.de', 'password' => 'geheim-12345', 'device' => 'Pixel 8'])->assertOk();

    expect(SecurityEvent::query()->whereIn('type', ['app_login_failed', 'app_login'])->orderBy('id')->pluck('type')->all())
        ->toBe(['app_login_failed', 'app_login'])
        ->and(SecurityEvent::query()->where('type', 'app_login')->sole()->data)->toBe(['device' => 'Pixel 8']);
});

it('protokolliert Änderungen an Mitarbeitern und Rollen', function () {
    $this->actingAs($this->admin);
    $this->ben->update(['active' => false]);
    $this->ben->memberships()->first()->delete();

    expect(SecurityEvent::query()->where('type', 'user_changed')->where('user_id', $this->ben->id)->latest('id')->first()->data)->toBe(['active' => false])
        ->and(SecurityEvent::query()->where('type', 'membership_changed')->latest('id')->first())
        ->actor_id->toBe($this->admin->id)
        ->data->toMatchArray(['aenderung' => 'entfernt', 'mandant' => 'FA']);
});

it('zeigt das Sicherheitsprotokoll nur Administratoren', function () {
    $this->actingAs($this->anna);
    expect(SecurityLogPage::canAccess())->toBeFalse();
    $this->get(SecurityLogPage::getUrl(panel: 'app'))->assertForbidden();

    $this->actingAs($this->admin);
    Livewire::test(SecurityLogPage::class)->assertOk()->assertSee('Mitarbeiter angelegt');
});

// ---------------------------------------------------------------- 5 Team-Hinweis

it('benachrichtigt das Team bei einer Zuordnung nur, wenn es eingeschaltet ist', function () {
    Notification::fake();
    $team = teamFor($this->a, 'Einkauf');
    $other = teamFor($this->a, 'Reparatur');
    tenantA(function () use ($team, $other) {
        $team->addMember($this->ben);
        $other->addMember($this->ben);
        $other->update(['notify_on_assignment' => true]);

        $this->ticket->changeTeam($team, $this->anna);
    });
    Notification::assertNothingSent();

    tenantA(fn () => $this->ticket->changeTeam($other, $this->anna));
    Notification::assertSentTo($this->ben, TicketActivity::class,
        fn (TicketActivity $n) => $n->event === 'team_assigned' && str_contains($n->title(), 'dem Team Reparatur zugeordnet'));
});

// ---------------------------------------------------------------- 6 Wiedervorlage

it('stellt ein Ticket zurück, blendet es aus den Arbeitslisten aus und holt es zurück', function () {
    Notification::fake();
    tenantA(fn () => $this->ticket->assignTo($this->anna, $this->anna));
    $this->actingAs($this->anna);

    Livewire::test(ViewTicket::class, ['record' => $this->ticket->id])
        ->callAction('snooze', data: ['preset' => 'days3'])->assertHasNoActionErrors();

    expect(tenantA(fn () => $this->ticket->fresh()->isSnoozed()))->toBeTrue();
    Livewire::test(ListTickets::class, ['activeTab' => 'mine'])->assertCanNotSeeTableRecords([$this->ticket]);
    Livewire::test(ListTickets::class, ['activeTab' => 'snoozed'])->assertCanSeeTableRecords([$this->ticket]);

    $this->artisan('anliego:wake-snoozed')->assertSuccessful();
    expect(tenantA(fn () => $this->ticket->fresh()->isSnoozed()))->toBeTrue();

    $this->travel(4)->days();
    $this->artisan('anliego:wake-snoozed')->assertSuccessful();

    expect(tenantA(fn () => $this->ticket->fresh()->snoozed_until))->toBeNull();
    Livewire::test(ListTickets::class, ['activeTab' => 'mine'])->assertCanSeeTableRecords([$this->ticket]);
    Notification::assertSentTo($this->anna, TicketActivity::class, fn (TicketActivity $n) => $n->event === 'snooze_due');
});

it('beendet die Wiedervorlage, wenn der Kunde antwortet', function () {
    tenantA(fn () => $this->ticket->snooze(now()->addWeek(), $this->anna));

    app(InboundMailProcessor::class)->process($this->mailbox, rawMail([
        'subject' => 'Re: Ersatzteil ['.tenantA(fn () => $this->ticket->subjectToken()).']', 'message_id' => 'antwort@kunde.de',
    ]));

    $ticket = tenantA(fn () => $this->ticket->fresh());
    expect($ticket->snoozed_until)->toBeNull()
        ->and(tenantA(fn () => $ticket->events()->where('type', 'unsnoozed')->sole()->data))->toBe(['reason' => 'reply']);
});

it('stellt über die App zurück und hebt wieder auf', function () {
    [, $plain] = AgentToken::issue($this->anna, 'Pixel');

    appApi($plain)->postJson("/api/agent/v1/tickets/{$this->ticket->id}/snooze", ['preset' => 'tomorrow'])
        ->assertOk()->assertJsonPath('ticket.snoozed_until', fn ($value) => $value !== null);
    appApi($plain)->getJson('/api/agent/v1/tickets?tab=snoozed')->assertOk()->assertJsonCount(1, 'data');
    appApi($plain)->getJson('/api/agent/v1/tickets?tab=unassigned')->assertOk()->assertJsonCount(0, 'data');

    appApi($plain)->postJson("/api/agent/v1/tickets/{$this->ticket->id}/snooze", ['preset' => 'off'])
        ->assertOk()->assertJsonPath('ticket.snoozed_until', null);
});

// ---------------------------------------------------------------- 7 Erwähnungen

it('erkennt @Erwähnungen mit ganzem Namen oder eindeutigem Vornamen, aber nur für Mitarbeiter des Mandanten', function () {
    $names = fn (string $body, array $ids = []) => tenantA(fn () => Mentions::resolve($this->ticket, $body, $ids)->pluck('name')->all());

    expect($names('Bitte prüfen, @Ben Muster'))->toBe(['Ben Muster'])
        ->and($names('@anna schau mal, @Carla Fremd auch'))->toBe(['Anna Beispiel'])
        ->and($names('Mail an ben@firma.de und @Benjamin'))->toBe([])
        ->and($names('ohne Erwähnung', [$this->ben->id, $this->fremd->id]))->toBe(['Ben Muster']);
});

it('benachrichtigt erwähnte Kollegen bei einer internen Notiz, nicht den Verfasser', function () {
    Notification::fake();

    tenantA(fn () => app(ReplySender::class)->note($this->ticket, $this->anna, 'Kannst du das übernehmen, @Ben? Gruß @Anna'));

    Notification::assertSentTo($this->ben, TicketActivity::class,
        fn (TicketActivity $n) => $n->event === 'mention' && str_contains($n->title(), 'Anna Beispiel hat dich in'));
    Notification::assertNotSentTo($this->anna, TicketActivity::class);
});
