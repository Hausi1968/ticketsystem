<?php

use App\Enums\StatusBehaviour;
use App\Filament\Resources\Mailboxes\Pages\CreateMailbox;
use App\Models\Mailbox;
use App\Models\MailReceipt;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

/** Im Mandanten ausführen (Tickets, Status und Postfächer sind mandantengebunden). */
function inMandant(Tenant $tenant, callable $fn): mixed
{
    return app(TenantContext::class)->runFor($tenant, $fn);
}

beforeEach(function () {
    Storage::fake('local');
    $this->tenant = Tenant::factory()->create(['name' => 'Firma Muster', 'short_code' => 'FM']);
    $this->mailbox = mailboxFor($this->tenant, ['email' => 'bestellung@firma.de', 'mark_seen' => 'resolved']);
    $this->fetcher = fakeFetcher();
});

it('lässt E-Mails beim Abruf ungelesen, wenn das Postfach erst bei Lösung markiert', function () {
    $normal = mailboxFor($this->tenant, ['email' => 'info@firma.de']);
    $this->fetcher->push($this->mailbox, rawMail(['id' => 'a@kunde.de']));
    $this->fetcher->push($normal, rawMail(['id' => 'b@kunde.de']));

    $this->artisan('anliego:poll-mailboxes')->assertSuccessful();

    expect(inMandant($this->tenant, fn () => Ticket::count()))->toBe(2)
        ->and($this->fetcher->markedOnFetch)->toHaveKey($normal->id)
        ->and($this->fetcher->markedOnFetch)->not->toHaveKey($this->mailbox->id);
});

it('markiert die E-Mails erst beim Lösen als gelesen, nicht beim Zuweisen', function () {
    $this->fetcher->push($this->mailbox, rawMail(['id' => 'a@kunde.de']));
    $this->artisan('anliego:poll-mailboxes')->assertSuccessful();
    $agent = memberOf($this->tenant);

    inMandant($this->tenant, function () use ($agent) {
        $ticket = Ticket::sole();
        $ticket->assignTo($agent);
        $ticket->changeStatus(TicketStatus::OPEN);
        $ticket->changeStatus(TicketStatus::WAITING);
    });

    expect($this->fetcher->seenChanges)->toBe([]);

    inMandant($this->tenant, fn () => Ticket::sole()->changeStatus(TicketStatus::SOLVED));

    expect($this->fetcher->seenChanges)->toBe([
        ['mailbox' => $this->mailbox->id, 'ids' => ['a@kunde.de'], 'seen' => true],
    ]);
});

it('gilt auch für frei angelegte schließende Status wie „Geschlossen“', function () {
    $this->fetcher->push($this->mailbox, rawMail(['id' => 'a@kunde.de']));
    $this->artisan('anliego:poll-mailboxes')->assertSuccessful();

    inMandant($this->tenant, function () {
        $closed = TicketStatus::create(['name' => 'Geschlossen', 'behaviour' => StatusBehaviour::Closed]);
        Ticket::sole()->changeStatus($closed);
    });

    expect($this->fetcher->seenChanges)->toHaveCount(1)
        ->and($this->fetcher->seenChanges[0]['seen'])->toBeTrue();
});

it('markiert die E-Mails wieder als ungelesen, wenn das Ticket wieder geöffnet wird', function () {
    $this->fetcher->push($this->mailbox, rawMail(['id' => 'a@kunde.de']));
    $this->artisan('anliego:poll-mailboxes')->assertSuccessful();

    inMandant($this->tenant, function () {
        Ticket::sole()->changeStatus(TicketStatus::SOLVED);
        Ticket::sole()->changeStatus(TicketStatus::OPEN);
    });

    expect($this->fetcher->seenChanges)->toBe([
        ['mailbox' => $this->mailbox->id, 'ids' => ['a@kunde.de'], 'seen' => true],
        ['mailbox' => $this->mailbox->id, 'ids' => ['a@kunde.de'], 'seen' => false],
    ]);
});

it('öffnet bei einer Kundenantwort wieder und markiert alle E-Mails des Tickets als ungelesen', function () {
    $this->fetcher->push($this->mailbox, rawMail(['id' => 'a@kunde.de']));
    $this->artisan('anliego:poll-mailboxes')->assertSuccessful();
    inMandant($this->tenant, fn () => Ticket::sole()->changeStatus(TicketStatus::SOLVED));

    $this->travel(10)->minutes();
    $this->fetcher->push($this->mailbox, rawMail(['id' => 'b@kunde.de', 'in_reply_to' => '<a@kunde.de>', 'references' => '<a@kunde.de>']));
    $this->artisan('anliego:poll-mailboxes')->assertSuccessful();

    expect(inMandant($this->tenant, fn () => Ticket::sole()->status->closes()))->toBeFalse()
        ->and(end($this->fetcher->seenChanges))->toBe(['mailbox' => $this->mailbox->id, 'ids' => ['a@kunde.de', 'b@kunde.de'], 'seen' => false]);
});

it('markiert auch die Kopie in einem zweiten Postfach', function () {
    $second = mailboxFor($this->tenant, ['email' => 'info@firma.de', 'mark_seen' => 'resolved']);
    $this->fetcher->push($this->mailbox, rawMail(['id' => 'a@kunde.de']));
    $this->fetcher->push($second, rawMail(['id' => 'a@kunde.de']));
    $this->artisan('anliego:poll-mailboxes')->assertSuccessful();

    inMandant($this->tenant, fn () => Ticket::sole()->changeStatus(TicketStatus::SOLVED));

    expect(collect($this->fetcher->seenChanges)->pluck('mailbox')->sort()->values()->all())
        ->toBe([$this->mailbox->id, $second->id]);
});

it('fasst Postfächer mit der Standard-Einstellung beim Lösen nicht an', function () {
    $this->mailbox->forceFill(['mark_seen' => 'fetch'])->saveQuietly();
    $this->fetcher->push($this->mailbox, rawMail(['id' => 'a@kunde.de']));
    $this->artisan('anliego:poll-mailboxes')->assertSuccessful();

    inMandant($this->tenant, fn () => Ticket::sole()->changeStatus(TicketStatus::SOLVED));

    expect($this->fetcher->seenChanges)->toBe([])
        ->and($this->fetcher->markedOnFetch)->toHaveKey($this->mailbox->id);
});

it('setzt den UID-Merker zurück, wenn Einstellung oder Ordner wechseln', function () {
    inMandant($this->tenant, function () {
        $mailbox = Mailbox::sole();
        $mailbox->forceFill(['imap_uidvalidity' => 7, 'imap_last_uid' => 120])->save();
        $mailbox->update(['name' => 'Neu']);
        expect($mailbox->fresh()->imap_last_uid)->toBe(120);

        $mailbox->update(['imap_folder' => 'Bestellungen']);
        expect($mailbox->fresh()->imap_last_uid)->toBeNull()
            ->and($mailbox->fresh()->imap_uidvalidity)->toBeNull();
    });
});

it('legt aus E-Mails vor „Nur E-Mails ab“ keine Tickets an', function () {
    $this->mailbox->forceFill(['import_since' => '2026-11-14'])->saveQuietly();
    $this->fetcher->push($this->mailbox,
        rawMail(['id' => 'alt@kunde.de', 'headers' => ['Date' => 'Fri, 13 Nov 2026 23:00:00 +0100']]),
        rawMail(['id' => 'neu@kunde.de', 'headers' => ['Date' => 'Sat, 14 Nov 2026 08:00:00 +0100']]),
    );

    $this->artisan('anliego:poll-mailboxes')->assertSuccessful();

    $receipts = inMandant($this->tenant, fn () => MailReceipt::query()->pluck('outcome', 'message_id'));
    expect(inMandant($this->tenant, fn () => Ticket::count()))->toBe(1)
        ->and($receipts['alt@kunde.de'])->toBe('ignored_old')
        ->and($receipts['neu@kunde.de'])->toBe('created');
});

it('schlägt beim Anlegen eines Postfachs heute als „Nur E-Mails ab“ vor', function () {
    expect(Mailbox::factory()->make()->import_since)->toBeNull(); // bestehende/Factory: alle
    $this->freezeTime();
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);
    Livewire\Livewire::test(CreateMailbox::class)
        ->assertSchemaStateSet(['import_since' => today()->toDateString()]);
});
