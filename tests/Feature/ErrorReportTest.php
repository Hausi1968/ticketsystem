<?php

use App\Enums\MessageType;
use App\Enums\TicketPriority;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Mail\Outbound\MailboxTransportFactory;
use App\Models\ApiKey;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\RecordingTransport;

beforeEach(function () {
    Storage::fake('local');
    $this->smtp = new RecordingTransport;
    app()->instance(MailboxTransportFactory::class, $this->smtp);
    $this->tenant = Tenant::factory()->create(['short_code' => 'TSV']);
    mailboxFor($this->tenant, ['email' => 'support@tsv.de']);
    [, $this->plain] = app(TenantContext::class)->runFor($this->tenant, fn () => ApiKey::issue($this->tenant->id, 'Mitgliederverwaltung'));
    $this->error = [
        'message' => 'Call to undefined function foo() bei Mitglied 17',
        'type' => 'Error', 'level' => 'critical',
        'file' => 'pages/members.php', 'line' => 42,
        'url' => '/index.php?p=members', 'method' => 'GET',
        'app_version' => '3.69.0', 'php_version' => '8.2.1', 'source' => 'Mitgliederverwaltung',
        'trace' => "#0 pages/members.php:42 render()\n#1 index.php:10",
    ];
    $this->tickets = fn () => app(TenantContext::class)->runFor($this->tenant, fn () => Ticket::query()->orderBy('id')->get());
});

function errorApi(string $plain)
{
    return test()->withHeaders(['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json']);
}

it('legt aus einem Fehler ein Ticket mit allen Angaben an, ohne E-Mail zu verschicken', function () {
    errorApi($this->plain)->postJson('/api/v1/errors', $this->error)
        ->assertCreated()->assertJson(['new_ticket' => true, 'count' => 1]);

    $ticket = ($this->tickets)()->sole();
    expect($ticket->channel)->toBe('error')
        ->and($ticket->priority)->toBe(TicketPriority::Urgent)
        ->and($ticket->subject)->toStartWith('Fehler: Call to undefined function foo()')
        ->and($ticket->contact->email)->toEndWith('@anliego.invalid')
        ->and($ticket->messages->first()->body_text)->toContain('pages/members.php:42')->toContain('3.69.0')->toContain('Aufrufkette')
        ->and($this->smtp->sent)->toBe([]);
});

it('fasst gleiche Fehler zusammen, auch mit anderen Zahlen in der Meldung', function () {
    errorApi($this->plain)->postJson('/api/v1/errors', $this->error)->assertCreated();
    errorApi($this->plain)->postJson('/api/v1/errors', ['message' => 'Call to undefined function foo() bei Mitglied 18'] + $this->error)
        ->assertOk()->assertJson(['new_ticket' => false, 'count' => 2]);
    errorApi($this->plain)->postJson('/api/v1/errors', ['line' => 99] + $this->error)->assertCreated();

    expect(($this->tickets)())->toHaveCount(2)
        ->and(($this->tickets)()->first()->error_count)->toBe(2);
});

it('ergänzt bei Wiederholungen höchstens stündlich eine Notiz', function () {
    errorApi($this->plain)->postJson('/api/v1/errors', $this->error);
    $this->travel(2)->hours();
    errorApi($this->plain)->postJson('/api/v1/errors', $this->error);
    errorApi($this->plain)->postJson('/api/v1/errors', $this->error);

    $ticket = ($this->tickets)()->sole();
    expect($ticket->error_count)->toBe(3)
        ->and($ticket->messages->where('type', MessageType::Note))->toHaveCount(1);
});

it('legt nach dem Schließen ein neues Ticket mit Verweis an', function () {
    errorApi($this->plain)->postJson('/api/v1/errors', $this->error);
    $first = ($this->tickets)()->sole();
    app(TenantContext::class)->runFor($this->tenant, fn () => $first->changeStatus(TicketStatus::SOLVED));

    errorApi($this->plain)->postJson('/api/v1/errors', $this->error)->assertCreated();

    $second = ($this->tickets)()->last();
    expect($second->follow_up_of_id)->toBe($first->id)
        ->and($second->messages->first()->body_text)->toContain('Früher schon aufgetreten: '.$first->reference());
});

it('bietet bei Fehlermeldungen keine Antwort an den Absender an', function () {
    errorApi($this->plain)->postJson('/api/v1/errors', $this->error);
    $this->actingAs(memberOf($this->tenant));

    Livewire::test(ViewTicket::class, ['record' => ($this->tickets)()->sole()->id])
        ->assertActionHidden('reply')
        ->assertActionVisible('note');
});

it('verlangt eine Meldung und einen gültigen Schlüssel', function () {
    $this->postJson('/api/v1/errors', $this->error)->assertUnauthorized();
    errorApi($this->plain)->postJson('/api/v1/errors', ['line' => 1])->assertUnprocessable();
});
