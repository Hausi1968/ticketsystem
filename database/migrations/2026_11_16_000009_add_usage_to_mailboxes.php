<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            // both = abrufen und versenden, fetch = nur abrufen, send = nur versenden
            $table->string('usage', 10)->default('both')->after('email');
            $table->foreignId('default_category_id')->nullable()->after('team_id')->constrained('ticket_categories')->nullOnDelete();
            $table->string('sent_folder')->nullable()->after('processed_folder');
            $table->string('sent_copy_bcc')->nullable()->after('sent_folder');
        });

        Schema::table('mailboxes', function (Blueprint $table) {
            foreach (['imap_host', 'imap_username', 'smtp_host', 'smtp_username'] as $column) {
                $table->string($column)->nullable()->change();
            }
            $table->text('imap_password')->nullable()->change();
            $table->text('smtp_password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_category_id');
            $table->dropColumn(['usage', 'sent_folder', 'sent_copy_bcc']);
        });
    }
};
