<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Wer hat welches Ticket gerade offen bzw. schreibt eine Antwort (Schutz vor doppelter Bearbeitung)
        Schema::create('ticket_presences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('activity', 16)->default('viewing');
            $table->timestamp('last_seen_at');
            $table->unique(['ticket_id', 'user_id']);
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_presences');
    }
};
