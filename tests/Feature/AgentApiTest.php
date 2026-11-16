<?php

use App\Enums\MembershipRole;
use App\Mail\Inbound\InboundMailProcessor;
use App\Mail\Outbound\MailboxTransportFactory;
use App\Models\AgentToken;
use App\Models\Attachment;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use App\Notifications\Channels\ExpoPushChannel;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PragmaRX\Google2FAQRCode\Google2FA;
use Tests\Support\RecordingTransport;

beforeEach(function () {
    Storage::fake('local');
    $this->smtp = new RecordingTransport;
    app()->instance(MailboxTransportFactory::class, $this->smtp);
    config(['anliego.require_mfa' => false]);

    $this->a = Tenant::factory()->create(['short_code' => 'FA']);
    $this->b = Tenant::factory()->create(['short_code' => 'VB']);
    $this->mailboxA = mailboxFor($this->a, ['email' => 'a@firma-a.de']);
    app(InboundMailProcessor::class)->process($this->mailboxA, rawMail(['subject' => 'Bestellung A', 'attachment' => 'auftrag.pdf']));
    app(InboundMailProcessor::class)->process(mailboxFor($this->b, ['email' => 'b@verein-b.de']), rawMail(['subject' => 'Frage B', 'attachment' => 'b.pdf']));
    $this->ticketA = app(TenantContext::class)->runFor($this->a, fn () => Ticket::sole());
    $this->ticketB = app(TenantContext::class)->runFor($this->b, fn () => Ticket::sole());

    $this->agent = memberOf($this->a);
    $this->agent->forceFill(['email' => 'anna@team.de', 'password' => 'geheim-12345', 'name' => 'Anna'])->save();
});

function agentLogin(?User $user = null): string
{
    $user ??= test()->agent;
    [, $plain] = AgentToken::issue($user, 'Test');

    return $plain;
}

function agentApi(string $token)
{
    return test()->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
}

it('meldet mit E-Mail und Passwort an und liefert ein Token', function () {
    $this->postJson('/api/agent/v1/login', ['email' => 'anna@team.de', 'password' => 'falsch'])->assertStatus(422);

    $token = $this->postJson('/api/agent/v1/login', ['email' => 'Anna@Team.de', 'password' => 'geheim-12345', 'device' => 'Pixel 8'])
        ->assertOk()->assertJsonPath('name', 'Anna')->json('token');

    expect($token)->toStartWith('agt_');
    agentApi($token)->getJson('/api/agent/v1/me')->assertOk()->assertJsonPath('tenants.0.short_code', 'FA');
});

it('verlangt den Code aus der Authenticator-App, wenn eingerichtet', function () {
    $google = app(Google2FA::class);
    $secret = $google->generateSecretKey();
    $this->agent->saveAppAuthenticationSecret($secret);

    $mfa = $this->postJson('/api/agent/v1/login', ['email' => 'anna@team.de', 'password' => 'geheim-12345'])
        ->assertOk()->assertJson(['mfa_required' => true])->json('mfa_token');

    $this->postJson('/api/agent/v1/login/mfa', ['mfa_token' => $mfa, 'code' => '000000'])->assertStatus(422);
    $this->postJson('/api/agent/v1/login/mfa', ['mfa_token' => $mfa, 'code' => $google->getCurrentOtp($secret)])
        ->assertOk()->assertJsonStructure(['token']);
});

it('lässt ohne eingerichtete Zwei-Faktor-Anmeldung nicht herein, wenn sie Pflicht ist', function () {
    config(['anliego.require_mfa' => true]);

    $this->postJson('/api/agent/v1/login', ['email' => 'anna@team.de', 'password' => 'geheim-12345'])
        ->assertForbidden()->assertJson(['error' => 'mfa_setup']);
});

it('lehnt ungültige Tokens ab und meldet ab', function () {
    agentApi('agt_falsch')->getJson('/api/agent/v1/tickets')->assertUnauthorized();

    $token = agentLogin();
    agentApi($token)->postJson('/api/agent/v1/logout')->assertOk();
    agentApi($token)->getJson('/api/agent/v1/me')->assertUnauthorized();
});

it('zeigt nur Tickets der betreuten Mandanten, nach Reiter und Suche', function () {
    $token = agentLogin();

    agentApi($token)->getJson('/api/agent/v1/tickets?tab=unassigned')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', $this->ticketA->reference());
    agentApi($token)->getJson('/api/agent/v1/tickets?tab=mine')->assertOk()->assertJsonCount(0, 'data');
    agentApi($token)->getJson('/api/agent/v1/tickets?search=Kisten')->assertOk()->assertJsonCount(1, 'data');
    agentApi($token)->getJson('/api/agent/v1/tickets/'.$this->ticketB->id)->assertNotFound();
});

it('liefert ein Ticket mit Verlauf, Anhängen und Rechten', function () {
    $token = agentLogin();

    $json = agentApi($token)->getJson('/api/agent/v1/tickets/'.$this->ticketA->id)->assertOk()
        ->assertJsonPath('can.reply', true)
        ->assertJsonPath('messages.0.type', 'customer')
        ->assertJsonPath('messages.0.attachments.0.filename', 'auftrag.pdf')
        ->json();

    $attachmentId = $json['messages'][0]['attachments'][0]['id'];
    agentApi($token)->get('/api/agent/v1/attachments/'.$attachmentId)->assertOk();

    $foreign = app(TenantContext::class)->runFor($this->b, fn () => Attachment::sole());
    agentApi($token)->get('/api/agent/v1/attachments/'.$foreign->id)->assertNotFound();
});

it('beantwortet ein Ticket mit Anhang und warnt bei neuen Nachrichten', function () {
    $token = agentLogin();
    $show = agentApi($token)->getJson('/api/agent/v1/tickets/'.$this->ticketA->id)->json();

    $upload = agentApi($token)->post('/api/agent/v1/uploads', ['file' => UploadedFile::fake()->create('angebot.pdf', 20, 'application/pdf')])
        ->assertCreated()->json('id');

    // Kollege schreibt inzwischen eine Notiz
    app(TenantContext::class)->runFor($this->a, fn () => $this->ticketA->messages()->create([
        'tenant_id' => $this->a->id, 'type' => 'note', 'user_id' => memberOf($this->a)->id, 'body_text' => 'Rufe an',
    ]));

    $payload = ['body' => 'Anbei das Angebot.', 'upload_ids' => [$upload], 'last_message_id' => $show['last_message_id'], 'minutes' => 10];
    agentApi($token)->postJson("/api/agent/v1/tickets/{$this->ticketA->id}/reply", $payload)->assertStatus(409)->assertJson(['error' => 'new_messages']);
    expect($this->smtp->sent)->toBe([]);

    agentApi($token)->postJson("/api/agent/v1/tickets/{$this->ticketA->id}/reply", $payload + ['force' => true])->assertOk()
        ->assertJsonPath('ticket.time_minutes', 10);

    expect($this->smtp->sent)->toHaveCount(1)
        ->and($this->smtp->last()->getAttachments()[0]->getFilename())->toBe('angebot.pdf')
        ->and($this->ticketA->fresh()->status->system_key)->toBe(TicketStatus::WAITING);
});

it('übernimmt, weist zu, setzt Status und schreibt Notizen', function () {
    $token = agentLogin();
    $colleague = memberOf($this->a);
    $outsider = memberOf($this->b);
    $id = $this->ticketA->id;

    agentApi($token)->postJson("/api/agent/v1/tickets/{$id}/take")->assertOk()->assertJsonPath('ticket.assignee.name', 'Anna');
    agentApi($token)->postJson("/api/agent/v1/tickets/{$id}/assign", ['assignee_id' => $outsider->id])->assertStatus(422);
    agentApi($token)->postJson("/api/agent/v1/tickets/{$id}/assign", ['assignee_id' => $colleague->id])->assertOk()
        ->assertJsonPath('ticket.assignee.id', $colleague->id);
    agentApi($token)->postJson("/api/agent/v1/tickets/{$id}/status", ['status_id' => TicketStatus::system(TicketStatus::SOLVED)->id])->assertOk();
    agentApi($token)->postJson("/api/agent/v1/tickets/{$id}/priority", ['priority' => 'urgent'])->assertOk()->assertJsonPath('ticket.priority.value', 'urgent');
    agentApi($token)->postJson("/api/agent/v1/tickets/{$id}/note", ['body' => 'Erledigt per Telefon'])->assertOk();

    expect($this->ticketA->fresh()->status->system_key)->toBe(TicketStatus::SOLVED)
        ->and($this->smtp->sent)->toBe([]);
});

it('lässt Light-Agents nur lesen und Notizen schreiben', function () {
    $light = User::factory()->create();
    $light->memberships()->create(['tenant_id' => $this->a->id, 'role' => MembershipRole::LightAgent]);
    $token = agentLogin($light);
    $id = $this->ticketA->id;

    agentApi($token)->getJson("/api/agent/v1/tickets/{$id}")->assertOk()->assertJsonPath('can.reply', false);
    agentApi($token)->postJson("/api/agent/v1/tickets/{$id}/reply", ['body' => 'x'])->assertForbidden();
    agentApi($token)->postJson("/api/agent/v1/tickets/{$id}/take")->assertForbidden();
    agentApi($token)->postJson("/api/agent/v1/tickets/{$id}/note", ['body' => 'Hinweis'])->assertOk();
});

it('liefert Auswahllisten nur für betreute Mandanten', function () {
    agentApi(agentLogin())->getJson('/api/agent/v1/options')->assertOk()
        ->assertJsonCount(1, 'tenants')->assertJsonPath('tenants.0.short_code', 'FA')
        ->assertJsonStructure(['tabs', 'statuses', 'priorities', 'categories', 'users']);
});

it('schickt Push-Nachrichten an angemeldete Geräte', function () {
    Http::fake([ExpoPushChannel::ENDPOINT => Http::response(['data' => [['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]]])]);
    $token = agentLogin();
    agentApi($token)->postJson('/api/agent/v1/push-token', ['push_token' => 'ExponentPushToken[abc123]'])->assertOk();
    agentApi($token)->postJson('/api/agent/v1/push-token', ['push_token' => 'kein-token'])->assertStatus(422);

    auth()->forgetGuards(); // der Mailabruf läuft ohne angemeldeten Mitarbeiter
    app(InboundMailProcessor::class)->process($this->mailboxA, rawMail(['subject' => 'Neue Bestellung', 'message_id' => '<neu@x>']));

    Http::assertSent(fn ($request) => $request->url() === ExpoPushChannel::ENDPOINT
        && str_contains($request[0]['title'], 'Neue Bestellung')
        && $request[0]['to'] === 'ExponentPushToken[abc123]'
        && is_int($request[0]['data']['ticket_id']));
    expect(AgentToken::query()->whereNotNull('push_token')->count())->toBe(0);
});
