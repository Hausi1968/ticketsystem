<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Automatisches Schließen und Erinnerung: seit wann wartet das Ticket auf den Kunden?
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('waiting_since')->nullable()->after('closed_at');
            $table->timestamp('reminded_at')->nullable()->after('waiting_since');
            $table->index(['status_id', 'waiting_since']);
        });

        // Textbausteine: für einen Mandanten oder (ohne Mandant) für alle
        Schema::create('canned_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->text('body');
            $table->unsignedInteger('sort')->default(100);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canned_responses');
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['status_id', 'waiting_since']);
            $table->dropColumn(['waiting_since', 'reminded_at']);
        });
    }
};
