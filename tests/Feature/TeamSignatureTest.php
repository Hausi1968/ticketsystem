<?php

use App\Enums\MessageType;
use App\Filament\Resources\Teams\Pages\EditTeam;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Mail\Inbound\InboundMailProcessor;
use App\Mail\Outbound\MailboxTransportFactory;
use App\Mail\Outbound\ReplySender;
use App\Mail\Outbound\TeamSignature;
use App\Models\AgentToken;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketStatus;
use App\Models\User;
use App\Tenancy\TenantContext;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\RecordingTransport;

/** 1×1-PNG als Logo */
function signatureLogo(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
}

beforeEach(function () {
    Storage::fake('local');
    $this->smtp = new RecordingTransport;
    app()->instance(MailboxTransportFactory::class, $this->smtp);

    $this->tenant = Tenant::factory()->create(['name' => 'Muster GmbH', 'short_code' => 'MG']);
    $this->other = Tenant::factory()->create(['name' => 'Verein B', 'short_code' => 'VB']);
    $this->mailbox = mailboxFor($this->tenant, ['email' => 'auftrag@muster.de']);
    $this->agent = memberOf($this->tenant);
    $this->agent->forceFill(['name' => 'Anna Beispiel'])->save();
    DB::table('tenant_user')->where('user_id', $this->agent->id)->update(['signature' => 'Anna persönlich']);

    app(InboundMailProcessor::class)->process($this->mailbox, rawMail(['id' => 'k1@kunde.de', 'subject' => 'Bestellung']));
    $this->actingAs($this->agent);
    $this->ticket = Ticket::sole();

    Storage::disk('local')->put("tenants/{$this->tenant->id}/signaturen/logo.png", signatureLogo());
    $this->team = Team::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Auftragsbearbeitung',
        'signature_html' => '<p style="color:#1e3a8a">Viele Grüße<br><strong>{{name}}</strong><br>{{team}} · {{mandant}}</p>'
            .'{{logo}}<script>alert(1)</script><a href="javascript:alert(1)" onclick="x()">Link</a>',
        'signature_logo_path' => "tenants/{$this->tenant->id}/signaturen/logo.png",
        'signature_logo_width' => 150,
    ]);
});

it('speichert das Signatur-HTML nur bereinigt', function () {
    $html = $this->team->fresh()->signature_html;

    expect($html)->toContain('<p style="color:#1e3a8a">')
        ->toContain('{{logo}}')
        ->not->toContain('<script')
        ->not->toContain('javascript:')
        ->not->toContain('onclick');
});

it('setzt Platzhalter ein und bettet das Logo per CID ein', function () {
    app(ReplySender::class)->reply($this->ticket, $this->agent, "Hallo Frau Muster,\nalles erledigt.", signature: $this->team);

    $email = $this->smtp->last();
    $raw = $email->toString();
    $html = $email->getHtmlBody();
    $inline = collect($email->getAttachments())->first(fn ($p) => $p->getDisposition() === 'inline');

    expect($html)->toContain("Hallo Frau Muster,<br>\nalles erledigt.")
        ->toContain('<strong>Anna Beispiel</strong>')
        ->toContain('Auftragsbearbeitung · Muster GmbH')
        ->toContain('width="150"')
        ->not->toContain('cid.anliego.invalid')
        ->and($inline)->not->toBeNull()
        // Im versendeten Quelltext zeigt das Bild auf das eingebettete Logo (multipart/related)
        ->and($raw)->toContain('multipart/related')
        ->and(quoted_printable_decode($raw))->toContain('src="cid:'.$inline->getContentId().'"')
        ->and($inline->getBody())->toBe(signatureLogo())
        // Textteil: Signatur als Text, die persönliche Signatur entfällt
        ->and($email->getTextBody())->toContain("alles erledigt.\n\n-- \nViele Grüße\nAnna Beispiel")
        ->and($email->getTextBody())->not->toContain('Anna persönlich');

    $message = TicketMessage::where('type', MessageType::Reply)->sole();
    expect($message->signature_team_id)->toBe($this->team->id)
        ->and($message->attachments()->where('inline', true)->sole()->content_id)->toBe(TeamSignature::LOGO_NAME);
});

it('lässt die Antwort ohne Signatur wie bisher', function () {
    app(ReplySender::class)->reply($this->ticket, $this->agent, 'Kurze Antwort');

    $email = $this->smtp->last();
    expect($email->getTextBody())->toContain("Kurze Antwort\n\n-- \nAnna persönlich")
        ->and($email->getHtmlBody())->not->toContain('Viele Grüße')
        ->and($email->getAttachments())->toBeEmpty();
});

it('setzt das Logo unter den Text, wenn der Platzhalter fehlt', function () {
    $this->team->update(['signature_html' => '<p>Ihr Team</p>']);

    expect(TeamSignature::render($this->team->fresh(), $this->agent))
        ->toMatch('#<p>Ihr Team</p><br><img src="https://cid\.anliego\.invalid/signatur-logo"#');
});

it('nimmt keine Signatur eines anderen Mandanten', function () {
    $foreign = app(TenantContext::class)->runFor($this->other, fn () => Team::create([
        'tenant_id' => $this->other->id, 'name' => 'Vorstand', 'signature_html' => '<p>Verein</p>',
    ]));

    expect(fn () => app(ReplySender::class)->reply($this->ticket, $this->agent, 'Hallo', signature: $foreign))
        ->toThrow(RuntimeException::class);
    expect(TeamSignature::availableFor($this->ticket)->pluck('id')->all())->toBe([$this->team->id]);
});

it('fügt die Signatur im Antwortfenster per Knopf hinzu', function () {
    Livewire::test(ViewTicket::class, ['record' => $this->ticket->id])
        ->callAction([TestAction::make('reply'), TestAction::make('addSignature')->schemaComponent('body', 'mountedActionSchema0')])
        ->assertSet('mountedActions.0.data.signature_team_id', $this->team->id)
        ->fillForm(['body' => 'Erledigt.', 'status_id' => TicketStatus::system(TicketStatus::WAITING)->id])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect(TicketMessage::where('type', MessageType::Reply)->sole()->signature_team_id)->toBe($this->team->id)
        ->and($this->smtp->last()->getHtmlBody())->toContain('<strong>Anna Beispiel</strong>');
});

it('pflegt die Signatur in der Teamverwaltung', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(EditTeam::class, ['record' => $this->team->id])
        ->fillForm(['signature_html' => '<table><tr><td style="color:red">Neu</td></tr></table><iframe src="https://x"></iframe>'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->team->fresh()->signature_html)->toContain('<td style="color:red">Neu</td>')->not->toContain('iframe');
});

it('nimmt die Signatur über die App-Schnittstelle an', function () {
    [, $token] = AgentToken::issue($this->agent, 'Test');
    $api = fn () => $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']);

    $api()->getJson('/api/agent/v1/options')->assertOk()
        ->assertJsonFragment(['id' => $this->team->id, 'name' => 'Auftragsbearbeitung', 'tenant_id' => $this->tenant->id, 'signature' => true]);

    $api()->postJson("/api/agent/v1/tickets/{$this->ticket->id}/reply", ['body' => 'Aus der App', 'signature_team_id' => 999999])
        ->assertStatus(422)->assertJson(['error' => 'signature']);

    $api()->postJson("/api/agent/v1/tickets/{$this->ticket->id}/reply", ['body' => 'Aus der App', 'signature_team_id' => $this->team->id, 'force' => true])
        ->assertOk();

    expect($this->smtp->last()->getHtmlBody())->toContain('Aus der App')->toContain('Anna Beispiel');
});
