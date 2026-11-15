<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SLA-Richtlinien je Mandant: Ziele je Priorität, Geschäftszeiten und Feiertage
        Schema::create('sla_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort')->default(100);
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 20)->nullable();
            $table->json('targets');               // {"urgent": {"first_response": 60, "next_response": 60, "resolution": 240}, …} in Minuten
            $table->boolean('all_day')->default(false);
            $table->json('business_hours')->nullable(); // {"1": {"open": true, "from": "08:00", "to": "17:00"}, …}
            $table->string('holiday_region', 5)->nullable();
            $table->json('extra_holidays')->nullable();
            $table->unsignedTinyInteger('warn_percent')->default(75);
            $table->timestamps();

            $table->index(['tenant_id', 'active', 'sort']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('sla_policy_id')->nullable()->after('api_key_id')->constrained()->nullOnDelete();
            $table->json('sla')->nullable()->after('sla_policy_id');
            $table->timestamp('sla_due_at')->nullable()->after('sla');          // nächste laufende Frist (Anzeige, Sortierung)
            $table->timestamp('sla_next_check_at')->nullable()->after('sla_due_at'); // nächste fällige Warnung oder Überschreitung
            $table->boolean('sla_breached')->default(false)->after('sla_next_check_at');

            $table->index('sla_next_check_at');
            $table->index(['tenant_id', 'sla_due_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['sla_next_check_at']);
            $table->dropIndex(['tenant_id', 'sla_due_at']);
            $table->dropConstrainedForeignId('sla_policy_id');
            $table->dropColumn(['sla', 'sla_due_at', 'sla_next_check_at', 'sla_breached']);
        });

        Schema::dropIfExists('sla_policies');
    }
};
