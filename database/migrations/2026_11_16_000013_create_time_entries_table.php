<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ticket_message_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('minutes');
            $table->date('worked_on');
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'worked_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
