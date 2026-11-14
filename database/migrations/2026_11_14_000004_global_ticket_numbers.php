<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ticketnummern sind über alle Mandanten hinweg eindeutig (MUS-1000, TSV-1001, …).
 * Ein Ticket lässt sich so allein über seine Nummer finden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_sequence', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('next_number');
        });

        DB::table('ticket_sequence')->insert([
            'id' => 1,
            'next_number' => max(1000, (int) DB::table('tickets')->max('number') + 1),
        ]);

        Schema::table('tickets', function (Blueprint $table) {
            $table->unique('number');
        });

        Schema::dropIfExists('tenant_counters');
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropUnique(['number']);
        });

        Schema::dropIfExists('ticket_sequence');

        Schema::create('tenant_counters', function (Blueprint $table) {
            $table->foreignId('tenant_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('next_ticket_number')->default(1000);
            $table->timestamps();
        });
    }
};
