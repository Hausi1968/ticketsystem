<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sicherheitsprotokoll: Anmeldungen, Geräte, Änderungen an Mitarbeitern
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40)->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();   // wen es betrifft
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete(); // wer es ausgelöst hat
            $table->string('email', 255)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('notify_on_assignment')->default(false);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('snoozed_until')->nullable()->index();
            $table->foreignId('snoozed_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('snoozed_by');
            $table->dropColumn('snoozed_until');
        });
        Schema::table('teams', fn (Blueprint $table) => $table->dropColumn('notify_on_assignment'));
        Schema::dropIfExists('security_events');
    }
};
