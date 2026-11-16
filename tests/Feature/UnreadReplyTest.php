<?php

use App\Mail\Outbound\MailboxTransportFactory;
use App\Mail\Outbound\ReplySender;
use App\Models\ApiKey;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RecordingTransport;

beforeEach(function () {
    Storage::fake('local');
    app()->instance(MailboxTransportFactory::class, new RecordingTransport);

    $this->tenant = Tenant::factory()->create(['short_code' => 'TSV', 'settings' => []]);
    $this->mailbox = mailboxFor($this->tenant, ['email' => 'support@tsv.de', 'send_acknowledgement' => true]);
    $this->agent = memberOf($this->tenant);
    [, $plain] = app(TenantContext::class)->runFor($this->tenant, fn () => ApiKey::issue($this->tenant->id, 'App', mailboxId: $this->mailbox->id));
    $this->headers = ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'];

    $this->ref = $this->withHeaders($this->headers)->postJson('/api/v1/tickets', [
        'subject' => 'Beitrag falsch', 'description' => 'Bitte prüfen',
        'reporter' => ['external_user_id' => 'u_1', 'email' => 'jonas@mitglied.de'],
    ])->assertCreated()->json('reference');
});

function listUnread(): ?bool
{
    return test()->withHeaders(test()->headers)->getJson('/api/v1/tickets?external_user_id=u_1')->assertOk()->json('data.0.unread');
}

it('meldet eine neue Antwort des Teams, bis der Nutzer das Ticket ansieht', function () {
    // Eingangsbestätigung zählt nicht als Antwort
    expect(listUnread())->toBeFalse();

    $this->travel(1)->minutes();
    $ticket = app(TenantContext::class)->runWithoutTenancy(fn () => Ticket::sole());
    app(TenantContext::class)->runFor($this->tenant, fn () => app(ReplySender::class)->reply($ticket, $this->agent, 'Ist korrigiert.'));
    app(TenantContext::class)->runFor($this->tenant, fn () => app(ReplySender::class)->note($ticket->fresh(), $this->agent, 'intern'));

    expect(listUnread())->toBeTrue();

    // Ansehen ohne mark_read ändert nichts (z. B. Prüfung im Hintergrund)
    $this->withHeaders($this->headers)->getJson("/api/v1/tickets/{$this->ref}")->assertJsonPath('unread', true);
    expect(listUnread())->toBeTrue();

    $updatedBefore = app(TenantContext::class)->runWithoutTenancy(fn () => Ticket::sole()->updated_at);
    $this->travel(1)->minutes();
    $this->withHeaders($this->headers)->getJson("/api/v1/tickets/{$this->ref}?mark_read=1")->assertJsonPath('unread', true);
    expect(listUnread())->toBeFalse()
        ->and(app(TenantContext::class)->runWithoutTenancy(fn () => Ticket::sole()->updated_at->equalTo($updatedBefore)))->toBeTrue();

    // Nächste Antwort ist wieder neu
    $this->travel(1)->minutes();
    app(TenantContext::class)->runFor($this->tenant, fn () => app(ReplySender::class)->reply(Ticket::sole(), $this->agent, 'Noch etwas.'));
    expect(listUnread())->toBeTrue();
});

it('gilt als gelesen, wenn der Nutzer selbst antwortet', function () {
    $this->travel(1)->minutes();
    app(TenantContext::class)->runFor($this->tenant, fn () => app(ReplySender::class)->reply(Ticket::sole(), $this->agent, 'Frage zurück'));
    expect(listUnread())->toBeTrue();

    $this->travel(1)->minutes();
    $this->withHeaders($this->headers)->postJson("/api/v1/tickets/{$this->ref}/messages", [
        'body' => 'Hier die Antwort', 'reporter' => ['external_user_id' => 'u_1'],
    ])->assertCreated();

    expect(listUnread())->toBeFalse();
});
