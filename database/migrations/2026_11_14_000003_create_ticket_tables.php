<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Postfächer je Mandant (IMAP-Abruf, SMTP-Versand). Zugangsdaten werden verschlüsselt gespeichert.
        Schema::create('mailboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('from_name')->nullable();

            $table->string('imap_host');
            $table->unsignedSmallInteger('imap_port')->default(993);
            $table->string('imap_encryption')->default('ssl'); // ssl | tls | none
            $table->string('imap_username');
            $table->text('imap_password');
            $table->string('imap_folder')->default('INBOX');
            $table->string('processed_folder')->nullable(); // leer = nur als gelesen markieren

            $table->string('smtp_host');
            $table->unsignedSmallInteger('smtp_port')->default(465);
            $table->string('smtp_encryption')->default('ssl'); // ssl | tls | none
            $table->string('smtp_username');
            $table->text('smtp_password');

            $table->unsignedSmallInteger('poll_interval_minutes')->default(5);
            $table->boolean('active')->default(true);
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'team_id'])->references(['tenant_id', 'id'])->on('teams');
        });

        // Anfragende Personen (Kunden, Vereinsmitglieder …) je Mandant
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'email']);
        });

        // Frei definierbare Status. Das Verhalten legt fest, was der Status bewirkt:
        // open = in Bearbeitung, pending = wartet, closed = schließt das Ticket.
        // system_key markiert die Status, die Anliego selbst setzt (umbenennbar, nicht löschbar).
        Schema::create('ticket_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('color')->default('gray');
            $table->string('behaviour')->default('open'); // open | pending | closed
            $table->boolean('reply_creates_new_ticket')->default(false); // nur bei closed
            $table->string('system_key')->nullable()->unique(); // new | open | waiting | solved
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('ticket_statuses')->insert([
            ['name' => 'Neu', 'color' => 'info', 'behaviour' => 'open', 'reply_creates_new_ticket' => false, 'system_key' => 'new', 'sort' => 10, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Offen', 'color' => 'warning', 'behaviour' => 'open', 'reply_creates_new_ticket' => false, 'system_key' => 'open', 'sort' => 20, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Wartet auf Kunde', 'color' => 'gray', 'behaviour' => 'pending', 'reply_creates_new_ticket' => false, 'system_key' => 'waiting', 'sort' => 30, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Wartet auf Dritte', 'color' => 'gray', 'behaviour' => 'pending', 'reply_creates_new_ticket' => false, 'system_key' => null, 'sort' => 40, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Gelöst', 'color' => 'success', 'behaviour' => 'closed', 'reply_creates_new_ticket' => false, 'system_key' => 'solved', 'sort' => 50, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Geschlossen', 'color' => 'gray', 'behaviour' => 'closed', 'reply_creates_new_ticket' => true, 'system_key' => null, 'sort' => 60, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Kategorien: je Mandant oder (tenant_id leer) für alle Mandanten
        Schema::create('ticket_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('color')->default('gray');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('subject');
            $table->foreignId('status_id')->constrained('ticket_statuses');
            $table->foreignId('category_id')->nullable()->constrained('ticket_categories')->nullOnDelete();
            $table->string('priority')->default('normal');
            $table->string('channel')->default('email'); // email | api | web
            $table->unsignedBigInteger('mailbox_id')->nullable();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->unsignedBigInteger('contact_id');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('follow_up_of_id')->nullable();
            $table->timestamp('last_customer_message_at')->nullable();
            $table->timestamp('last_agent_message_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'number']);
            $table->index(['status_id', 'assignee_id']);
            $table->foreign(['tenant_id', 'mailbox_id'])->references(['tenant_id', 'id'])->on('mailboxes');
            $table->foreign(['tenant_id', 'team_id'])->references(['tenant_id', 'id'])->on('teams');
            $table->foreign(['tenant_id', 'contact_id'])->references(['tenant_id', 'id'])->on('contacts');
            $table->foreign(['tenant_id', 'follow_up_of_id'])->references(['tenant_id', 'id'])->on('tickets');
        });

        // Weitere Beteiligte (z. B. CC-Empfänger), die auf das Ticket antworten dürfen
        Schema::create('ticket_participants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedBigInteger('contact_id');
            $table->timestamps();

            $table->unique(['ticket_id', 'contact_id']);
            $table->foreign(['tenant_id', 'ticket_id'])->references(['tenant_id', 'id'])->on('tickets')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'contact_id'])->references(['tenant_id', 'id'])->on('contacts')->cascadeOnDelete();
        });

        // Nachrichten im Ticketverlauf: Kundennachricht, Antwort, interne Notiz
        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('ticket_id');
            $table->string('type'); // customer | reply | note
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();
            $table->json('to')->nullable();
            $table->json('cc')->nullable();
            $table->string('subject')->nullable();
            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable(); // bereits bereinigt
            $table->string('message_id')->nullable(); // Header Message-ID ohne <>
            $table->string('in_reply_to')->nullable();
            $table->text('references')->nullable();
            $table->string('raw_path')->nullable();
            $table->string('delivery_status')->nullable(); // pending | sent | failed
            $table->text('delivery_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'message_id']);
            $table->foreign(['tenant_id', 'ticket_id'])->references(['tenant_id', 'id'])->on('tickets')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'contact_id'])->references(['tenant_id', 'id'])->on('contacts');
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('ticket_message_id');
            $table->string('filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('content_id')->nullable();
            $table->boolean('inline')->default(false);
            $table->char('sha256', 64)->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'ticket_message_id'])->references(['tenant_id', 'id'])->on('ticket_messages')->cascadeOnDelete();
        });

        // Protokoll: Statuswechsel, Zuweisungen, Prioritäten …
        Schema::create('ticket_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('ticket_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->json('data')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign(['tenant_id', 'ticket_id'])->references(['tenant_id', 'id'])->on('tickets')->cascadeOnDelete();
        });

        // Jede abgerufene E-Mail wird hier vermerkt – auch ignorierte (Schleifen, Dubletten, Bounces)
        Schema::create('mail_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('mailbox_id');
            $table->char('message_hash', 64);
            $table->string('message_id')->nullable();
            $table->string('from_email')->nullable();
            $table->string('subject')->nullable();
            $table->string('outcome'); // created | appended | reopened | follow_up | ignored_loop | ignored_bounce | ignored_own | duplicate | failed
            $table->unsignedBigInteger('ticket_id')->nullable();
            $table->text('note')->nullable();
            $table->string('raw_path')->nullable();
            $table->timestamps();

            $table->unique(['mailbox_id', 'message_hash']);
            $table->foreign(['tenant_id', 'mailbox_id'])->references(['tenant_id', 'id'])->on('mailboxes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_receipts');
        Schema::dropIfExists('ticket_events');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('ticket_participants');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('ticket_categories');
        Schema::dropIfExists('ticket_statuses');
        Schema::dropIfExists('mailboxes');
    }
};
