<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Benachrichtigungen in der Oberfläche (Glocke oben rechts)
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::table('mailboxes', function (Blueprint $table) {
            $table->boolean('send_acknowledgement')->default(false)->after('poll_interval_minutes');
            $table->text('acknowledgement_text')->nullable()->after('send_acknowledgement');
        });

        // Welche Ereignisse ein Mitarbeiter zusätzlich per E-Mail bekommt
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('notification_preferences'));
        Schema::table('mailboxes', fn (Blueprint $table) => $table->dropColumn(['send_acknowledgement', 'acknowledgement_text']));
        Schema::dropIfExists('notifications');
    }
};
