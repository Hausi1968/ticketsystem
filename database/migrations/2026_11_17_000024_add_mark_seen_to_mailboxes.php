<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            // fetch = beim Abruf als gelesen markieren (bisher), resolved = erst wenn das Ticket gelöst/geschlossen ist
            $table->string('mark_seen', 10)->default('fetch')->after('processed_folder');
            // Merker für den Abruf ohne Gelesen-Markierung: bis zu welcher IMAP-UID ist alles abgeholt?
            $table->unsignedBigInteger('imap_uidvalidity')->nullable()->after('mark_seen');
            $table->unsignedBigInteger('imap_last_uid')->nullable()->after('imap_uidvalidity');
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn(['mark_seen', 'imap_uidvalidity', 'imap_last_uid']);
        });
    }
};
