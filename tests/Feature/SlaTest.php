<?php

use App\Enums\MembershipRole;
use App\Enums\TicketPriority;
use App\Filament\Resources\SlaPolicies\Pages\CreateSlaPolicy;
use App\Filament\Resources\SlaPolicies\Pages\EditSlaPolicy;
use App\Mail\Inbound\InboundMailProcessor;
use App\Mail\Outbound\MailboxTransportFactory;
use App\Mail\Outbound\ReplySender;
use App\Models\SlaPolicy;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use App\Notifications\TicketActivity;
use App\Sla\BusinessHours;
use App\Sla\GermanHolidays;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\RecordingTransport;

/** Montag, 16.11.2026, 09:00 Uhr in Berlin */
const SLA_MONDAY_9 = '2026-11-16 08:00:00';

beforeEach(function () {
    Storage::fake('local');
    app()->instance(MailboxTransportFactory::class, new RecordingTransport);
    $this->travelTo(CarbonImmutable::parse(SLA_MONDAY_9, 'UTC'));

    $this->tenant = Tenant::factory()->create(['name' => 'Firma Muster', 'short_code' => 'FM']);
    $this->mailbox = mailboxFor($this->tenant, ['email' => 'bestellung@firma.de']);
    $this->agent = memberOf($this->tenant);
    $this->supervisor = memberOf($this->tenant);
    $this->supervisor->memberships()->update(['role' => MembershipRole::Supervisor]);

    // Mo–Fr 8–17 Uhr, Feiertage NRW. Normal: 2 Std. Erstreaktion, 1 Std. Folgereaktion, 9 Std. Lösung
    $this->policy = slaIn(fn () => SlaPolicy::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Standard',
        'targets' => [
            'normal' => ['first_response' => 120, 'next_response' => 60, 'resolution' => 540],
            'urgent' => ['first_response' => 30, 'resolution' => 120],
        ],
        'business_hours' => SlaPolicy::DEFAULT_HOURS,
        'holiday_region' => 'NW',
    ]));
});

function slaIn(callable $callback): mixed
{
    return app(TenantContext::class)->runFor(test()->tenant, $callback);
}

function slaMail(array $o = []): Ticket
{
    $receipt = app(InboundMailProcessor::class)->process(test()->mailbox, rawMail($o));

    return slaIn(fn () => Ticket::findOrFail($receipt->ticket_id));
}

function berlin(?CarbonInterface $at): ?string
{
    return $at ? CarbonImmutable::instance($at)->setTimezone('Europe/Berlin')->format('D d.m. H:i') : null;
}

function dueOf(Ticket $ticket, string $metric): ?string
{
    $due = $ticket->fresh()->sla[$metric]['due_at'] ?? null;

    return $due ? berlin(CarbonImmutable::parse($due)) : null;
}

// ---------------------------------------------------------------- Geschäftszeiten

it('rechnet Fristen in Geschäftszeiten über Abend, Wochenende und Feiertage', function () {
    $hours = $this->policy->calendar();
    $at = fn (string $local) => CarbonImmutable::parse($local, 'Europe/Berlin');

    expect(berlin($hours->add($at('2026-11-16 09:00'), 120)))->toBe('Mon 16.11. 11:00')
        // Freitag 16 Uhr + 2 Std. → Montag 9 Uhr
        ->and(berlin($hours->add($at('2026-11-20 16:00'), 120)))->toBe('Mon 23.11. 09:00')
        // Eingang am Samstag zählt ab Montag 8 Uhr
        ->and(berlin($hours->add($at('2026-11-21 10:00'), 60)))->toBe('Mon 23.11. 09:00')
        // 24.12. ist ein normaler Werktag, 25./26.12. Feiertage (Fr/Sa), 27.12. Sonntag
        ->and(berlin($hours->add($at('2026-12-24 16:00'), 120)))->toBe('Mon 28.12. 09:00')
        ->and($hours->minutesBetween($at('2026-11-20 16:00'), $at('2026-11-23 09:00')))->toBe(120);
});

it('kennt die Feiertage der Bundesländer', function () {
    expect(GermanHolidays::easterSunday(2027)->format('Y-m-d'))->toBe('2027-03-28')
        ->and(GermanHolidays::for(2026, 'NW'))->toHaveKey('2026-06-04') // Fronleichnam
        ->and(GermanHolidays::for(2026, 'NI'))->not->toHaveKey('2026-06-04')
        ->and(GermanHolidays::for(2026, 'NI'))->toHaveKey('2026-10-31')
        ->and(GermanHolidays::for(2026, 'SN'))->toHaveKey('2026-11-18'); // Buß- und Bettag
});

it('zählt rund um die Uhr, wenn so eingestellt', function () {
    $hours = BusinessHours::allDay('Europe/Berlin');

    expect(berlin($hours->add(CarbonImmutable::parse('2026-11-21 23:00', 'Europe/Berlin'), 120)))->toBe('Sun 22.11. 01:00');
});

it('nimmt zusätzliche freie Tage mit und ohne Jahr', function () {
    $this->policy->update(['extra_holidays' => ['24.12.', '02.01.2027', 'Unsinn']]);

    expect($this->policy->holidaysIn(2026))->toHaveKeys(['2026-12-24', '2026-12-25'])
        ->and($this->policy->holidaysIn(2027))->toHaveKeys(['2027-01-02', '2027-12-24']);
});

// ---------------------------------------------------------------- Fristen am Ticket

it('setzt beim neuen Ticket Fristen für Erstreaktion und Lösung', function () {
    $ticket = slaMail();

    expect($ticket->sla_policy_id)->toBe($this->policy->id)
        ->and(dueOf($ticket, 'first_response'))->toBe('Mon 16.11. 11:00')
        ->and(dueOf($ticket, 'resolution'))->toBe('Tue 17.11. 09:00')
        ->and(berlin($ticket->sla_due_at))->toBe('Mon 16.11. 11:00')
        // Warnung bei 75 % der Erstreaktion (90 Minuten)
        ->and(berlin($ticket->sla_next_check_at))->toBe('Mon 16.11. 10:30');
});

it('beendet die Erstreaktion mit der ersten Antwort und pausiert die Lösungszeit, solange auf den Kunden gewartet wird', function () {
    $ticket = slaMail(['id' => 'k1@kunde.de']);

    $this->travel(30)->minutes();
    $this->actingAs($this->agent);
    app(ReplySender::class)->reply($ticket, $this->agent, 'Rückfrage: welche Farbe?');

    $sla = $ticket->fresh()->sla;
    expect($sla['first_response']['met_at'])->not->toBeNull()
        ->and($sla['first_response']['breached'])->toBeFalse()
        ->and($sla['resolution']['paused'])->toBeTrue()
        ->and($sla['resolution']['used'])->toBe(30)
        ->and($ticket->fresh()->sla_due_at)->toBeNull();

    // Kunde antwortet am nächsten Morgen: Lösungszeit läuft weiter, Folgereaktion beginnt
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Europe/Berlin'));
    auth()->logout();
    slaMail(['subject' => 'Re: Bestellung 4711 [#FM-1000]', 'in_reply_to' => '<'.$ticket->messages()->where('type', 'reply')->value('message_id').'>']);

    $ticket->refresh();
    expect($ticket->status->system_key)->toBe(TicketStatus::OPEN)
        // Rest 8,5 Std. ab Dienstag 10 Uhr: 7 Std. bis Feierabend, 1,5 Std. am Mittwoch
        ->and(dueOf($ticket, 'resolution'))->toBe('Wed 18.11. 09:30')
        ->and(dueOf($ticket, 'next_response'))->toBe('Tue 17.11. 11:00');
});

it('berechnet die Ziele nach einer Prioritätsänderung neu', function () {
    $ticket = slaMail();

    $this->travel(10)->minutes();
    slaIn(fn () => $ticket->changePriority(TicketPriority::Urgent));

    expect(dueOf($ticket, 'first_response'))->toBe('Mon 16.11. 09:30')
        ->and(dueOf($ticket, 'resolution'))->toBe('Mon 16.11. 11:00');
});

it('erledigt alle Fristen beim Schließen und startet die Lösungszeit beim Wiedereröffnen neu', function () {
    $ticket = slaMail();

    slaIn(fn () => $ticket->changeStatus(TicketStatus::SOLVED));
    $ticket->refresh();
    expect($ticket->sla_due_at)->toBeNull()->and($ticket->sla_next_check_at)->toBeNull()
        ->and($ticket->sla['resolution']['met_at'])->not->toBeNull();

    $this->travel(1)->hours();
    slaIn(fn () => $ticket->changeStatus(TicketStatus::OPEN));
    expect(dueOf($ticket, 'resolution'))->toBe('Tue 17.11. 10:00');
});

it('wählt die erste passende Richtlinie und misst ohne Richtlinie nichts', function () {
    $team = teamFor($this->tenant, 'Auftragsbearbeitung');
    $special = slaIn(fn () => SlaPolicy::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Aufträge', 'sort' => 1, 'team_id' => $team->id, 'all_day' => true,
        'targets' => ['normal' => ['first_response' => 15]],
    ]));
    $this->mailbox->update(['team_id' => $team->id]);

    $ticket = slaMail();
    expect($ticket->sla_policy_id)->toBe($special->id)
        ->and($ticket->sla)->toHaveKey('first_response')->not->toHaveKey('resolution');

    slaIn(fn () => SlaPolicy::query()->update(['active' => false]));
    expect(slaMail(['subject' => 'Andere Frage'])->sla_policy_id)->toBeNull();
});

it('gilt nicht für andere Mandanten', function () {
    $other = Tenant::factory()->create(['short_code' => 'XY']);
    $box = mailboxFor($other, ['email' => 'info@xy.de']);

    $receipt = app(InboundMailProcessor::class)->process($box, rawMail());
    $ticket = app(TenantContext::class)->runFor($other, fn () => Ticket::findOrFail($receipt->ticket_id));

    expect($ticket->sla_policy_id)->toBeNull();
});

// ---------------------------------------------------------------- Erinnerungen

it('warnt den Bearbeiter vor Ablauf und meldet die Überschreitung auch dem Supervisor, jeweils einmal', function () {
    Notification::fake();
    $ticket = slaMail();
    slaIn(fn () => $ticket->assignTo($this->agent, $this->supervisor));

    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:29', 'Europe/Berlin'));
    $this->artisan('anliego:sla-check')->assertSuccessful();
    Notification::assertNotSentTo($this->agent, TicketActivity::class, fn ($n) => str_starts_with($n->event, 'sla_'));

    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:31', 'Europe/Berlin'));
    $this->artisan('anliego:sla-check')->expectsOutputToContain('1 Warnung(en), 0 Überschreitung(en)');
    Notification::assertSentTo($this->agent, TicketActivity::class, fn ($n) => $n->event === 'sla_warning'
        && $n->title() === 'Frist läuft bald ab: Erstreaktion zu FM-1000 bis 16.11.2026 11:00');
    Notification::assertNotSentTo($this->supervisor, TicketActivity::class, fn ($n) => $n->event === 'sla_warning');

    $this->artisan('anliego:sla-check')->expectsOutputToContain('0 Warnung(en), 0 Überschreitung(en)');

    $this->travelTo(CarbonImmutable::parse('2026-11-16 11:01', 'Europe/Berlin'));
    $this->artisan('anliego:sla-check')->expectsOutputToContain('0 Warnung(en), 1 Überschreitung(en)');
    Notification::assertSentTo([$this->agent, $this->supervisor], TicketActivity::class, fn ($n) => $n->event === 'sla_breach');

    $ticket->refresh();
    expect($ticket->sla_breached)->toBeTrue()
        ->and(slaIn(fn () => $ticket->events()->where('type', 'sla_breach')->count()))->toBe(1);

    $this->artisan('anliego:sla-check')->expectsOutputToContain('0 Warnung(en), 0 Überschreitung(en)');
});

it('erinnert ohne Bearbeiter die Mitarbeiter des Mandanten', function () {
    Notification::fake();
    slaMail();

    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:31', 'Europe/Berlin'));
    $this->artisan('anliego:sla-check');

    Notification::assertSentTo([$this->agent, $this->supervisor], TicketActivity::class, fn ($n) => $n->event === 'sla_warning');
});

it('lässt das Ticket beim Prüfen unverändert ("Aktualisiert" bleibt)', function () {
    $ticket = slaMail();
    $updated = $ticket->updated_at;

    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:31', 'Europe/Berlin'));
    $this->artisan('anliego:sla-check');

    expect($ticket->fresh()->updated_at->equalTo($updated))->toBeTrue();
});

// ---------------------------------------------------------------- Oberfläche

it('zeigt die Fristen im Ticket und in der Liste', function () {
    $ticket = slaMail();
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->get("/tickets/{$ticket->id}")
        ->assertOk()->assertSee('Fristen (SLA)')->assertSee('Erstreaktion')->assertSee('fällig in 2 Std.');

    $this->actingAs($admin)->get('/tickets')->assertOk()->assertSee('in 2 Std.');
});

it('legt eine Richtlinie im Formular an (Stunden werden zu Minuten)', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(CreateSlaPolicy::class)
        ->fillForm([
            'tenant_id' => $this->tenant->id,
            'name' => 'Premium',
            'targets.urgent.first_response' => '0.5',
            'targets.normal.resolution' => '',
            'holiday_region' => 'BY',
            'extra_holidays' => ['24.12.'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $policy = SlaPolicy::query()->where('name', 'Premium')->sole();
    expect($policy->target('urgent', 'first_response'))->toBe(30)
        ->and($policy->target('normal', 'resolution'))->toBeNull()
        ->and($policy->target('normal', 'first_response'))->toBe(480)
        ->and($policy->business_hours[1])->toBe(['open' => true, 'from' => '08:00', 'to' => '17:00'])
        ->and($policy->business_hours[6]['open'])->toBeFalse();

    Livewire::test(EditSlaPolicy::class, ['record' => $policy->getRouteKey()])
        ->assertSchemaStateSet(['targets.urgent.first_response' => 0.5, 'targets.normal.first_response' => 8]);
});

it('ist nur für Administratoren', function () {
    $this->actingAs($this->agent)->get('/sla')->assertForbidden();
});

it('beendet laufende Fristen, wenn die Richtlinie gelöscht wird', function () {
    $ticket = slaMail();
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    $this->policy->delete();

    expect($ticket->fresh()->sla_next_check_at)->toBeNull()
        ->and($ticket->fresh()->sla)->toBeNull();
});

it('prüft, dass die Geschäftszeit eines Tages nach ihrem Beginn endet', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    Livewire::test(CreateSlaPolicy::class)
        ->fillForm(['tenant_id' => $this->tenant->id, 'name' => 'Falsch', 'business_hours.1.from' => '17:00', 'business_hours.1.to' => '09:00'])
        ->call('create')
        ->assertHasFormErrors(['business_hours.1.to']);
});
