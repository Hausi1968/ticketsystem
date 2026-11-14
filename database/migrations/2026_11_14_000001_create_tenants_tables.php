<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            // Kürzel, z. B. "ACME" – Präfix der Ticketnummern (ACME-1042) und Kennzeichnung in Listen
            $table->string('short_code', 10)->unique();
            $table->string('color', 7)->default('#0d9488');
            $table->string('status')->default('active'); // active | suspended
            $table->string('timezone')->default('Europe/Berlin');
            $table->string('locale', 5)->default('de');
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        // Mitgliedschaft: welcher Mitarbeiter betreut welchen Mandanten in welcher Rolle
        Schema::create('tenant_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('agent'); // supervisor | agent | light_agent
            $table->text('signature')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
        });

        // Fortlaufende Ticketnummern je Mandant
        Schema::create('tenant_counters', function (Blueprint $table) {
            $table->foreignId('tenant_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('next_ticket_number')->default(1000);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_counters');
        Schema::dropIfExists('tenant_user');
        Schema::dropIfExists('tenants');
    }
};
