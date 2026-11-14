<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ticket-Schnittstelle für Anwendungen (Konzept Abschnitt 6a). */
return new class extends Migration
{
    public function up(): void
    {
        // API-Schlüssel je Mandant. Gespeichert wird nur ein Hash; der Schlüssel ist nur beim Anlegen sichtbar.
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('prefix', 20);
            $table->char('key_hash', 64)->unique();
            $table->unsignedBigInteger('mailbox_id')->nullable(); // Antworten gehen über dieses Postfach
            $table->unsignedBigInteger('team_id')->nullable();    // Neue Tickets landen in diesem Team
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'mailbox_id'])->references(['tenant_id', 'id'])->on('mailboxes');
            $table->foreign(['tenant_id', 'team_id'])->references(['tenant_id', 'id'])->on('teams');
        });

        // Vorab hochgeladene Anhänge; werden beim Ticket mit ihrer öffentlichen ID angegeben
        Schema::create('api_uploads', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 40)->unique(); // att_…
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('api_key_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size');
            $table->string('path');
            $table->char('sha256', 64);
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        // Doppelte Anfragen (Doppelklick, Netzwerk-Wiederholung) erzeugen kein zweites Ticket
        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_key_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->unsignedBigInteger('ticket_id');
            $table->timestamp('created_at')->nullable();

            $table->unique(['api_key_id', 'key']);
            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('external_user_id', 191)->nullable()->after('name');
            $table->unique(['tenant_id', 'external_user_id']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->json('context')->nullable()->after('channel'); // technische Angaben der Anwendung, nur intern
            $table->unsignedBigInteger('api_key_id')->nullable()->after('context');
            $table->foreign('api_key_id')->references('id')->on('api_keys')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['api_key_id']);
            $table->dropColumn(['context', 'api_key_id']);
        });
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'external_user_id']);
            $table->dropColumn('external_user_id');
        });
        Schema::dropIfExists('api_idempotency_keys');
        Schema::dropIfExists('api_uploads');
        Schema::dropIfExists('api_keys');
    }
};
