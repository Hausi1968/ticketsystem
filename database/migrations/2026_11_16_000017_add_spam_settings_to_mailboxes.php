<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            // Eine Adresse oder Domain je Zeile, z. B. werbung@shop.de oder @spam-domain.de
            $table->text('blocked_senders')->nullable();
            $table->boolean('ignore_newsletters')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn(['blocked_senders', 'ignore_newsletters']);
        });
    }
};
