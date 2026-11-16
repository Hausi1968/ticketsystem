<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Wann der Nutzer das Ticket zuletzt in der Anwendung angesehen hat (für „Neue Antwort“)
            $table->timestamp('customer_seen_at')->nullable()->after('last_agent_message_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', fn (Blueprint $table) => $table->dropColumn('customer_seen_at'));
    }
};
