<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('match', 3)->default('all'); // all | any
            $table->json('conditions');
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('assign', 20)->nullable(); // null | user | round_robin
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('ticket_categories')->nullOnDelete();
            $table->string('priority', 20)->nullable();
            $table->boolean('stop_processing')->default(true);
            $table->unsignedInteger('sort')->default(100);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_rules');
    }
};
