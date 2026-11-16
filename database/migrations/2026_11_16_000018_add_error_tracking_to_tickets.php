<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Automatische Fehlermeldungen: gleiche Fehler (gleicher Fingerabdruck) landen im selben offenen Ticket
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('error_fingerprint', 64)->nullable();
            $table->unsignedInteger('error_count')->default(0);
            $table->timestamp('error_last_at')->nullable();
            $table->index(['tenant_id', 'error_fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'error_fingerprint']);
            $table->dropColumn(['error_fingerprint', 'error_count', 'error_last_at']);
        });
    }
};
