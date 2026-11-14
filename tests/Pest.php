<?php

use App\Enums\MembershipRole;
use App\Mail\Inbound\MailFetcher;
use App\Models\Mailbox;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeMailFetcher;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

function memberOf(Tenant ...$tenants): User
{
    $user = User::factory()->create();
    foreach ($tenants as $tenant) {
        $user->memberships()->create(['tenant_id' => $tenant->id, 'role' => MembershipRole::Agent]);
    }

    return $user;
}

function teamFor(Tenant $tenant, string $name): Team
{
    return app(TenantContext::class)->runFor($tenant, fn () => Team::factory()->create(['name' => $name]));
}

function mailboxFor(Tenant $tenant, array $attributes = []): Mailbox
{
    return app(TenantContext::class)->runFor($tenant, fn () => Mailbox::factory()->create(['tenant_id' => $tenant->id, ...$attributes]));
}

function fakeFetcher(): FakeMailFetcher
{
    $fake = new FakeMailFetcher;
    app()->instance(MailFetcher::class, $fake);

    return $fake;
}

/** Baut eine einfache Roh-E-Mail für Tests. */
function rawMail(array $o = []): string
{
    $headers = [
        'From' => $o['from'] ?? 'Erika Muster <erika@kunde.de>',
        'To' => $o['to'] ?? 'support@firma.de',
        'Subject' => $o['subject'] ?? 'Bestellung 4711',
        'Message-ID' => '<'.($o['id'] ?? uniqid('m', true).'@kunde.de').'>',
        'Date' => 'Fri, 13 Nov 2026 10:00:00 +0100',
        'MIME-Version' => '1.0',
    ];
    foreach (['cc' => 'Cc', 'in_reply_to' => 'In-Reply-To', 'references' => 'References'] as $key => $name) {
        if (isset($o[$key])) {
            $headers[$name] = $o[$key];
        }
    }
    $headers = array_merge($headers, $o['headers'] ?? []);

    $body = $o['body'] ?? "Hallo,\nich bestelle 3 Kisten.\n\nGruß Erika";

    if (isset($o['attachment'])) {
        $boundary = 'b'.md5($body);
        $headers['Content-Type'] = "multipart/mixed; boundary=\"{$boundary}\"";
        $content = "--{$boundary}\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n{$body}\r\n"
            ."--{$boundary}\r\nContent-Type: application/pdf; name=\"{$o['attachment']}\"\r\n"
            ."Content-Disposition: attachment; filename=\"{$o['attachment']}\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            .chunk_split(base64_encode('%PDF-1.4 test'))."\r\n--{$boundary}--\r\n";
    } else {
        $headers['Content-Type'] ??= 'text/plain; charset=utf-8';
        $content = $body;
    }

    $lines = [];
    foreach ($headers as $name => $value) {
        $lines[] = "{$name}: {$value}";
    }

    return implode("\r\n", $lines)."\r\n\r\n".$content;
}
