<?php

use App\Filament\Resources\Mailboxes\Pages\CreateMailbox;
use App\Mail\Inbound\InboundMailProcessor;
use App\Mail\Outbound\MailboxTransportFactory;
use App\Mail\Outbound\ReplySender;
use App\Models\Mailbox;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\RecordingTransport;

beforeEach(function () {
    Storage::fake('local');
    $this->smtp = new RecordingTransport;
    app()->instance(MailboxTransportFactory::class, $this->smtp);
    $this->fetcher = fakeFetcher();

    $this->tenant = Tenant::factory()->create(['short_code' => 'FA']);
    $this->category = TicketCategory::create(['name' => 'Allgemeine Anfrage', 'tenant_id' => $this->tenant->id]);
    // Weiterleitungs-Postfach: nur abrufen
    $this->inbox = mailboxFor($this->tenant, ['email' => 'eingang@ionos-postfach.de', 'usage' => 'fetch',
        'default_category_id' => $this->category->id, 'smtp_host' => null, 'smtp_username' => null, 'smtp_password' => null]);
    // Absender: nur versenden, Kopie in „Gesendete Objekte“ und per BCC
    $this->sender = mailboxFor($this->tenant, ['email' => 'info@verein.de', 'usage' => 'send',
        'sent_folder' => 'Gesendete Objekte', 'sent_copy_bcc' => 'chef@outlook.de']);
    $this->agent = memberOf($this->tenant);
});

it('ruft über das Eingangs-Postfach ab und antwortet über das Absender-Postfach', function () {
    app(InboundMailProcessor::class)->process($this->inbox, rawMail(['subject' => 'Frage zum Beitrag', 'to' => 'info@verein.de']));
    $ticket = app(TenantContext::class)->runFor($this->tenant, fn () => Ticket::sole());

    expect($ticket->category_id)->toBe($this->category->id);

    app(TenantContext::class)->runFor($this->tenant, fn () => app(ReplySender::class)->reply($ticket, $this->agent, 'Gern geschehen.'));

    $email = $this->smtp->last();
    expect($this->smtp->sent[array_key_last($this->smtp->sent)]['mailbox'])->toBe($this->sender->id)
        ->and($email->getFrom()[0]->getAddress())->toBe('info@verein.de')
        ->and($email->getReplyTo()[0]->getAddress())->toBe('info@verein.de')
        ->and($email->getBcc()[0]->getAddress())->toBe('chef@outlook.de')
        ->and($this->fetcher->appended)->toHaveCount(1)
        ->and($this->fetcher->appended[0]['mailbox'])->toBe($this->sender->id)
        ->and($this->fetcher->appended[0]['folder'])->toBe('Gesendete Objekte')
        ->and($this->fetcher->appended[0]['raw'])->toContain('Gern geschehen.');
});

it('ruft Postfächer „nur versenden“ nicht ab', function () {
    $this->artisan('anliego:poll-mailboxes')->assertSuccessful();

    expect($this->fetcher->polled)->toBe([$this->inbox->id]);
});

it('meldet einen verständlichen Fehler, wenn es nur Abruf-Postfächer gibt', function () {
    $this->sender->update(['active' => false]);
    app(InboundMailProcessor::class)->process($this->inbox, rawMail());
    $ticket = app(TenantContext::class)->runFor($this->tenant, fn () => Ticket::sole());

    expect(fn () => app(TenantContext::class)->runFor($this->tenant, fn () => app(ReplySender::class)->reply($ticket, $this->agent, 'Hallo')))
        ->toThrow(RuntimeException::class, 'kein aktives Postfach zum Versenden');
});

it('legt ein Postfach „nur versenden“ ohne IMAP-Zugang an', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    Livewire::test(CreateMailbox::class)
        ->fillForm([
            'tenant_id' => $this->tenant->id, 'name' => 'Absender', 'email' => 'antwort@verein.de', 'usage' => 'send',
            'smtp_host' => 'smtp.ionos.de', 'smtp_port' => 465, 'smtp_encryption' => 'ssl',
            'smtp_username' => 'antwort@verein.de', 'smtp_password' => 'geheim',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $mailbox = app(TenantContext::class)->runWithoutTenancy(fn () => Mailbox::where('email', 'antwort@verein.de')->sole());
    expect($mailbox->usage)->toBe('send')->and($mailbox->imap_host)->toBeNull();

    // Mit Gesendet-Ordner wird der IMAP-Zugang Pflicht
    Livewire::test(CreateMailbox::class)
        ->fillForm([
            'tenant_id' => $this->tenant->id, 'name' => 'Absender 2', 'email' => 'antwort2@verein.de', 'usage' => 'send',
            'smtp_host' => 'smtp.ionos.de', 'smtp_port' => 465, 'smtp_encryption' => 'ssl',
            'smtp_username' => 'antwort2@verein.de', 'smtp_password' => 'geheim', 'sent_folder' => 'Gesendet',
        ])
        ->call('create')
        ->assertHasFormErrors(['imap_host' => 'required']);
});
