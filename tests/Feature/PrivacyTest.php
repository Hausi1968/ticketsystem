<?php

use App\Filament\Resources\Contacts\Pages\ManageContacts;
use App\Mail\Inbound\InboundMailProcessor;
use App\Mail\Outbound\MailboxTransportFactory;
use App\Mail\Outbound\ReplySender;
use App\Models\Attachment;
use App\Models\Contact;
use App\Models\MailReceipt;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use App\Privacy\ContactPrivacy;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\RecordingTransport;

beforeEach(function () {
    Storage::fake('local');
    app()->instance(MailboxTransportFactory::class, new RecordingTransport);

    $this->tenant = Tenant::factory()->create(['short_code' => 'FA']);
    $this->mailbox = mailboxFor($this->tenant, ['email' => 'auftrag@firma-a.de']);
    $this->agent = memberOf($this->tenant);

    app(InboundMailProcessor::class)->process($this->mailbox, rawMail([
        'subject' => 'Erika Muster: Lieferung fehlt', 'attachment' => 'rechnung.pdf',
        'body' => "Hallo,\nmeine Bestellung fehlt.\n\nErika Muster, Hauptstr. 1",
    ]));
    $this->ticket = app(TenantContext::class)->runFor($this->tenant, fn () => Ticket::sole());
    app(TenantContext::class)->runFor($this->tenant, fn () => app(ReplySender::class)
        ->reply($this->ticket, $this->agent, "Hallo Erika Muster,\nwir prüfen das."));

    $this->contact = app(TenantContext::class)->runWithoutTenancy(fn () => Contact::where('email', 'erika@kunde.de')->sole());
});

it('gibt alle Daten eines Kontakts als Auskunft heraus', function () {
    $data = app(TenantContext::class)->runWithoutTenancy(fn () => app(ContactPrivacy::class)->export($this->contact));

    expect($data['kontakt']['email'])->toBe('erika@kunde.de')
        ->and($data['tickets'])->toHaveCount(1)
        ->and($data['tickets'][0]['nachrichten'])->toHaveCount(2)
        ->and($data['tickets'][0]['nachrichten'][0]['anhaenge'])->toBe(['rechnung.pdf'])
        ->and($data['tickets'][0]['nachrichten'][1]['art'])->toBe('Antwort des Teams');
});

it('löscht einen Kontakt durch Anonymisieren und lässt das Ticket bestehen', function () {
    $attachment = app(TenantContext::class)->runWithoutTenancy(fn () => Attachment::sole());
    Storage::disk($attachment->disk)->assertExists($attachment->path);

    $count = app(TenantContext::class)->runWithoutTenancy(fn () => app(ContactPrivacy::class)->anonymize($this->contact));
    expect($count)->toBe(1);

    app(TenantContext::class)->runWithoutTenancy(function () use ($attachment) {
        $ticket = $this->ticket->fresh();
        [$own, $reply] = $ticket->messages()->get()->all();

        expect($ticket)->not->toBeNull()
            ->and($ticket->subject)->not->toContain('Erika Muster')
            ->and($own->body_text)->toBe(ContactPrivacy::REMOVED)
            ->and($own->from_email)->toBeNull()
            ->and($reply->body_text)->not->toContain('Erika Muster')
            ->and(json_encode($reply->to))->not->toContain('erika@kunde.de')
            ->and(Attachment::count())->toBe(0)
            ->and(MailReceipt::where('from_email', 'erika@kunde.de')->exists())->toBeFalse()
            ->and($this->contact->fresh()->email)->toEndWith('@anonym.invalid')
            ->and($this->contact->fresh()->name)->toBeNull()
            ->and($ticket->events()->where('type', 'anonymized')->exists())->toBeTrue();

        Storage::disk($attachment->disk)->assertMissing($attachment->path);
    });
});

it('lässt nur Administratoren Kontakte verwalten und verlangt zum Löschen die E-Mail-Adresse', function () {
    $this->actingAs($this->agent)->get('/kontakte')->assertForbidden();

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin)->get('/kontakte')->assertOk()->assertSee('erika@kunde.de');

    Livewire::test(ManageContacts::class)
        ->callTableAction('anonymize', $this->contact, ['confirm' => 'falsch@kunde.de'])
        ->assertHasTableActionErrors(['confirm']);
    expect(app(TenantContext::class)->runWithoutTenancy(fn () => $this->contact->fresh()->email))->toBe('erika@kunde.de');

    Livewire::test(ManageContacts::class)
        ->callTableAction('anonymize', $this->contact, ['confirm' => 'Erika@Kunde.de'])
        ->assertHasNoTableActionErrors();
    expect(app(TenantContext::class)->runWithoutTenancy(fn () => $this->contact->fresh()->email))->toEndWith('@anonym.invalid');

    Livewire::test(ManageContacts::class)->callTableAction('export', $this->contact->fresh())->assertFileDownloaded();
});
