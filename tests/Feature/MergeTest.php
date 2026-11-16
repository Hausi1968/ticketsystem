<?php

use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Mail\Inbound\InboundMailProcessor;
use App\Mail\Outbound\MailboxTransportFactory;
use App\Models\ApiKey;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Tenancy\TenantContext;
use App\Tickets\TicketMerger;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\RecordingTransport;

function mergeScope(callable $callback): mixed
{
    return app(TenantContext::class)->runWithoutTenancy($callback);
}

beforeEach(function () {
    Storage::fake('local');
    $this->smtp = new RecordingTransport;
    app()->instance(MailboxTransportFactory::class, $this->smtp);

    $this->tenant = Tenant::factory()->create(['short_code' => 'FA']);
    $this->mailbox = mailboxFor($this->tenant, ['email' => 'auftrag@firma-a.de']);
    $this->agent = memberOf($this->tenant);

    $processor = app(InboundMailProcessor::class);
    $processor->process($this->mailbox, rawMail(['subject' => 'Lieferung fehlt', 'id' => 'erste@kunde.de']));
    $processor->process($this->mailbox, rawMail(['subject' => 'Nochmal wegen Lieferung', 'id' => 'zweite@kunde.de', 'attachment' => 'beleg.pdf',
        'from' => 'Max Muster <max@kunde.de>']));

    [$this->target, $this->source] = mergeScope(fn () => Ticket::query()->orderBy('id')->get()->all());
});

it('führt ein Ticket samt Nachrichten und Anhängen in ein anderes zusammen', function () {
    mergeScope(fn () => app(TicketMerger::class)->merge($this->source, $this->target, $this->agent));

    mergeScope(function () {
        $source = $this->source->fresh(['status']);
        $target = $this->target->fresh();

        expect($target->messages()->count())->toBe(2)
            ->and($target->messages()->get()->last()->attachments)->toHaveCount(1)
            ->and($source->messages()->count())->toBe(0)
            ->and($source->merged_into_id)->toBe($target->id)
            ->and($source->status->closes())->toBeTrue()
            ->and($target->acceptsRepliesFrom('max@kunde.de'))->toBeTrue()
            ->and($target->events()->where('type', 'merged_from')->exists())->toBeTrue();
    });
});

it('legt spätere E-Mail-Antworten auf das zusammengeführte Ticket ins Ziel', function () {
    mergeScope(fn () => app(TicketMerger::class)->merge($this->source, $this->target, $this->agent));

    // Antwort per Betreff-Kennung des alten Tickets
    $ref = mergeScope(fn () => $this->source->fresh()->subjectToken());
    app(InboundMailProcessor::class)->process($this->mailbox, rawMail(['subject' => "Re: Nochmal {$ref}", 'from' => 'max@kunde.de']));
    // Antwort per References-Kopfzeile auf die verschobene Nachricht
    app(InboundMailProcessor::class)->process($this->mailbox, rawMail(['subject' => 'Re: Nochmal', 'from' => 'max@kunde.de',
        'in_reply_to' => '<zweite@kunde.de>']));

    mergeScope(fn () => expect(Ticket::count())->toBe(2)
        ->and($this->target->fresh()->messages()->count())->toBe(4)
        ->and($this->source->fresh()->messages()->count())->toBe(0));
});

it('leitet API-Abfragen und -Antworten auf das Ziel-Ticket weiter', function () {
    [, $plain] = app(TenantContext::class)->runFor($this->tenant, fn () => ApiKey::issue($this->tenant->id, 'App', mailboxId: $this->mailbox->id));
    mergeScope(fn () => app(TicketMerger::class)->merge($this->source, $this->target, $this->agent));
    $sourceRef = mergeScope(fn () => $this->source->fresh()->reference());
    $targetRef = mergeScope(fn () => $this->target->fresh()->reference());

    $headers = ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'];
    $this->withHeaders($headers)->getJson("/api/v1/tickets/{$sourceRef}")->assertOk()->assertJsonPath('reference', $targetRef);

    $this->withHeaders($headers)->postJson("/api/v1/tickets/{$sourceRef}/messages", [
        'body' => 'Ist inzwischen angekommen.', 'reporter' => ['email' => 'max@kunde.de'],
    ])->assertCreated()->assertJsonPath('reference', $targetRef)->assertJsonPath('follow_up', false);

    mergeScope(fn () => expect(TicketMessage::query()->latest('id')->first())
        ->ticket_id->toBe($this->target->id)
        ->from_email->toBe('max@kunde.de'));
});

it('führt Tickets über die Ticketansicht zusammen', function () {
    $this->actingAs($this->agent);

    Livewire::test(ViewTicket::class, ['record' => $this->source->id])
        ->callAction('merge', data: ['target_id' => $this->target->id])
        ->assertHasNoActionErrors()
        ->assertRedirect();

    mergeScope(fn () => expect($this->source->fresh()->merged_into_id)->toBe($this->target->id));

    Livewire::test(ViewTicket::class, ['record' => $this->source->id])
        ->assertSee('zusammengeführt in')->assertActionHidden('reply')->assertActionHidden('merge');
});

it('verweigert das Zusammenführen über Mandantengrenzen', function () {
    $other = Tenant::factory()->create(['short_code' => 'XY']);
    app(InboundMailProcessor::class)->process(mailboxFor($other, ['email' => 'info@xy.de']), rawMail(['subject' => 'Fremd']));
    $foreign = mergeScope(fn () => Ticket::where('tenant_id', $other->id)->sole());

    expect(fn () => mergeScope(fn () => app(TicketMerger::class)->merge($this->source, $foreign, $this->agent)))
        ->toThrow(InvalidArgumentException::class);
});
