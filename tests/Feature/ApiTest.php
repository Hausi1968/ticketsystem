<?php

use App\Mail\Outbound\MailboxTransportFactory;
use App\Mail\Outbound\ReplySender;
use App\Models\ApiKey;
use App\Models\ApiUpload;
use App\Models\Attachment;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketStatus;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RecordingTransport;

function unscoped(callable $callback): mixed
{
    return app(TenantContext::class)->runWithoutTenancy($callback);
}

beforeEach(function () {
    Storage::fake('local');
    app()->instance(MailboxTransportFactory::class, new RecordingTransport);

    $this->tenant = Tenant::factory()->create(['name' => 'TSV Beispiel', 'short_code' => 'TSV']);
    $this->other = Tenant::factory()->create(['short_code' => 'XY']);
    $this->mailbox = mailboxFor($this->tenant, ['email' => 'support@tsv.de']);
    $this->team = teamFor($this->tenant, 'App-Support');

    [$this->key, $this->plain] = app(TenantContext::class)->runFor($this->tenant,
        fn () => ApiKey::issue($this->tenant->id, 'Mitgliederverwaltung', teamId: $this->team->id));
    [, $this->otherPlain] = app(TenantContext::class)->runFor($this->other, fn () => ApiKey::issue($this->other->id, 'Andere App'));

    TicketCategory::create(['name' => 'App-Fehler', 'tenant_id' => $this->tenant->id]);

    $this->payload = [
        'subject' => 'Beitrag wird falsch angezeigt',
        'description' => "Bei mir steht 120 € statt 60 €.\nScreenshot anbei.",
        'category' => 'app-fehler',
        'priority' => 'high',
        'reporter' => ['external_user_id' => 'u_4711', 'email' => 'Jonas@Mitglied.de', 'name' => 'Jonas K.'],
        'context' => ['url' => '/beitraege', 'app_version' => '2.3.1', 'browser' => 'Firefox 132'],
    ];
});

function api(string $plain)
{
    return test()->withHeaders(['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json']);
}

it('lehnt Anfragen ohne oder mit falschem Schlüssel ab', function () {
    $this->postJson('/api/v1/tickets', $this->payload)->assertUnauthorized();
    api('ank_falsch')->postJson('/api/v1/tickets', $this->payload)->assertUnauthorized();
});

it('legt ein Ticket mit Kontakt, Kategorie, Kontext und Team an', function () {
    $response = api($this->plain)->postJson('/api/v1/tickets', $this->payload)
        ->assertCreated()
        ->assertJsonPath('reference', 'TSV-1000')
        ->assertJsonPath('status.state', 'open')
        ->assertJsonPath('category', 'App-Fehler');

    $ticket = unscoped(fn () => Ticket::with('contact', 'messages')->sole());

    expect($ticket->tenant_id)->toBe($this->tenant->id)
        ->and($ticket->channel)->toBe('api')
        ->and($ticket->priority->value)->toBe('high')
        ->and($ticket->team_id)->toBe($this->team->id)
        ->and($ticket->mailbox_id)->toBe($this->mailbox->id)
        ->and($ticket->context)->toBe(['url' => '/beitraege', 'app_version' => '2.3.1', 'browser' => 'Firefox 132'])
        ->and($ticket->contact->email)->toBe('jonas@mitglied.de')
        ->and($ticket->contact->external_user_id)->toBe('u_4711')
        ->and($ticket->messages->sole()->body_text)->toContain('120 €');
});

it('legt bei gleichem Idempotency-Key kein zweites Ticket an', function () {
    api($this->plain)->withHeader('Idempotency-Key', 'abc-1')->postJson('/api/v1/tickets', $this->payload)->assertCreated();
    api($this->plain)->withHeader('Idempotency-Key', 'abc-1')->postJson('/api/v1/tickets', $this->payload)
        ->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('reference', 'TSV-1000');

    expect(unscoped(fn () => Ticket::count()))->toBe(1);
});

it('prüft die Pflichtangaben', function () {
    api($this->plain)->postJson('/api/v1/tickets', ['subject' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['subject', 'description', 'reporter.email']);
});

it('nimmt Anhänge vorab an und hängt sie an das Ticket', function () {
    $upload = api($this->plain)->post('/api/v1/attachments', [
        'file' => UploadedFile::fake()->createWithContent('screenshot.png', 'PNGDATA'),
    ])->assertCreated()->json();

    expect($upload['id'])->toStartWith('att_')->and($upload['filename'])->toBe('screenshot.png');

    api($this->plain)->postJson('/api/v1/tickets', $this->payload + ['attachment_ids' => [$upload['id']]])->assertCreated();

    $attachment = unscoped(fn () => Attachment::sole());
    expect($attachment->filename)->toBe('screenshot.png')
        ->and($attachment->path)->toStartWith("tenants/{$this->tenant->id}/attachments/")
        ->and(Storage::disk('local')->get($attachment->path))->toBe('PNGDATA');

    // Ein Anhang kann nur einmal verwendet werden
    api($this->plain)->postJson('/api/v1/tickets', $this->payload + ['attachment_ids' => [$upload['id']]])
        ->assertUnprocessable()->assertJsonValidationErrors('attachment_ids');
});

it('lässt keine Anhänge eines anderen Mandanten verwenden', function () {
    $upload = api($this->otherPlain)->post('/api/v1/attachments', ['file' => UploadedFile::fake()->create('x.pdf', 10)])->json();

    api($this->plain)->postJson('/api/v1/tickets', $this->payload + ['attachment_ids' => [$upload['id']]])
        ->assertUnprocessable();
});

it('liefert die Tickets eines Nutzers und die Antworten, aber keine internen Notizen', function () {
    api($this->plain)->postJson('/api/v1/tickets', $this->payload)->assertCreated();
    $ticket = unscoped(fn () => Ticket::sole());

    $agent = memberOf($this->tenant);
    app(TenantContext::class)->runFor($this->tenant, function () use ($ticket, $agent) {
        app(ReplySender::class)->note($ticket, $agent, 'Geheim: Beitragsdatei prüfen');
        app(ReplySender::class)->reply($ticket, $agent, 'Danke, ist korrigiert.');
    });

    api($this->plain)->getJson('/api/v1/tickets?external_user_id=u_4711')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reference', 'TSV-1000')
        ->assertJsonPath('data.0.status.state', 'pending');

    $detail = api($this->plain)->getJson('/api/v1/tickets/TSV-1000')->assertOk();
    expect($detail->json('messages'))->toHaveCount(2)
        ->and($detail->json('messages.1.body'))->toContain('ist korrigiert')
        ->and(json_encode($detail->json()))->not->toContain('Geheim')
        ->and($detail->json())->not->toHaveKey('context');
});

it('zeigt über die API keine Tickets anderer Mandanten', function () {
    api($this->plain)->postJson('/api/v1/tickets', $this->payload)->assertCreated();

    api($this->otherPlain)->getJson('/api/v1/tickets/TSV-1000')->assertNotFound();
    api($this->otherPlain)->getJson('/api/v1/tickets?external_user_id=u_4711')->assertOk()->assertJsonCount(0, 'data');
});

it('nimmt widerrufene Schlüssel nicht mehr an', function () {
    unscoped(fn () => $this->key->fresh()->revoke());

    api($this->plain)->postJson('/api/v1/tickets', $this->payload)->assertUnauthorized();
});

it('ordnet App-Meldungen und E-Mails derselben Person demselben Kontakt zu', function () {
    app(TenantContext::class)->runFor($this->tenant, fn () => Contact::findOrCreateFor($this->tenant->id, 'jonas@mitglied.de', null));

    api($this->plain)->postJson('/api/v1/tickets', $this->payload)->assertCreated();

    $contacts = unscoped(fn () => Contact::where('tenant_id', $this->tenant->id)->get());
    expect($contacts)->toHaveCount(1)
        ->and($contacts->first()->external_user_id)->toBe('u_4711')
        ->and($contacts->first()->name)->toBe('Jonas K.');
});

it('zeigt die technischen Details in der Ticketansicht', function () {
    api($this->plain)->postJson('/api/v1/tickets', $this->payload)->assertCreated();
    $ticket = unscoped(fn () => Ticket::sole());

    $this->actingAs(memberOf($this->tenant))->get("/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Technische Details')
        ->assertSee('2.3.1')
        ->assertSee('Anwendung (API)');
});

it('räumt nicht verwendete Uploads nach einem Tag auf', function () {
    api($this->plain)->post('/api/v1/attachments', ['file' => UploadedFile::fake()->create('alt.pdf', 5)])->assertCreated();
    $this->travel(25)->hours();

    $this->artisan('anliego:cleanup')->assertSuccessful();

    expect(unscoped(fn () => ApiUpload::count()))->toBe(0);
});

it('nimmt Antworten des Nutzers auf sein Ticket an und öffnet es wieder', function () {
    api($this->plain)->postJson('/api/v1/tickets', $this->payload)->assertCreated();
    $ticket = unscoped(fn () => Ticket::sole());
    $agent = memberOf($this->tenant);
    app(TenantContext::class)->runFor($this->tenant, fn () => app(ReplySender::class)->reply($ticket, $agent, 'Welche Beitragsart?'));
    expect(unscoped(fn () => $ticket->fresh()->status->system_key))->toBe(TicketStatus::WAITING);

    api($this->plain)->postJson('/api/v1/tickets/TSV-1000/messages', [
        'body' => 'Familienbeitrag.',
        'reporter' => ['external_user_id' => 'u_4711'],
    ])->assertCreated()->assertJsonPath('reference', 'TSV-1000')->assertJsonPath('follow_up', false);

    $fresh = unscoped(fn () => $ticket->fresh());
    expect($fresh->status->system_key)->toBe(TicketStatus::OPEN)
        ->and(unscoped(fn () => $fresh->messages()->where('type', 'customer')->reorder()->latest('id')->value('body_text')))->toBe('Familienbeitrag.');

    $detail = api($this->plain)->getJson('/api/v1/tickets/TSV-1000')->assertOk();
    expect($detail->json('messages'))->toHaveCount(3);
});

it('lässt nur den Ersteller oder Beteiligte antworten', function () {
    api($this->plain)->postJson('/api/v1/tickets', $this->payload)->assertCreated();

    api($this->plain)->postJson('/api/v1/tickets/TSV-1000/messages', ['body' => 'Hallo', 'reporter' => ['external_user_id' => 'u_9999']])
        ->assertForbidden();
    api($this->plain)->postJson('/api/v1/tickets/TSV-1000/messages', ['body' => 'Hallo', 'reporter' => ['email' => 'fremd@x.de']])
        ->assertForbidden();
    api($this->otherPlain)->postJson('/api/v1/tickets/TSV-1000/messages', ['body' => 'Hallo', 'reporter' => ['external_user_id' => 'u_4711']])
        ->assertNotFound();
    api($this->plain)->postJson('/api/v1/tickets/TSV-1000/messages', ['body' => 'per Mail', 'reporter' => ['email' => 'jonas@mitglied.de']])
        ->assertCreated();
});

it('legt bei Antwort auf ein geschlossenes Ticket ein Folgeticket an, wenn der Status es verlangt', function () {
    api($this->plain)->postJson('/api/v1/tickets', $this->payload)->assertCreated();
    $ticket = unscoped(fn () => Ticket::sole());
    $closed = TicketStatus::system(TicketStatus::SOLVED);
    $closed->update(['reply_creates_new_ticket' => true]);
    app(TenantContext::class)->runFor($this->tenant, fn () => $ticket->changeStatus($closed));

    api($this->plain)->postJson('/api/v1/tickets/TSV-1000/messages', ['body' => 'Nochmal kaputt', 'reporter' => ['external_user_id' => 'u_4711']])
        ->assertCreated()->assertJsonPath('reference', 'TSV-1001')->assertJsonPath('follow_up', true);

    $followUp = unscoped(fn () => Ticket::query()->where('number', 1001)->sole());
    expect($followUp->follow_up_of_id)->toBe($ticket->id)
        ->and($followUp->subject)->toBe($ticket->subject);
});
