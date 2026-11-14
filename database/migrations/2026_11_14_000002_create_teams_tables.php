<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Teams / Queues je Mandant, z. B. "Auftragsbearbeitung"
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('assignment_mode')->default('manual'); // manual | round_robin | load
            $table->timestamps();

            // Ziel für zusammengesetzte Fremdschlüssel (tenant_id, id)
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'name']);
        });

        // Teammitglieder. Die zusammengesetzten Fremdschlüssel sorgen dafür, dass
        // Team und Mitgliedschaft immer zum selben Mandanten gehören.
        Schema::create('team_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('user_id');
            $table->boolean('receives_assignments')->default(true);
            $table->timestamps();

            $table->unique(['team_id', 'user_id']);
            $table->foreign(['tenant_id', 'team_id'])
                ->references(['tenant_id', 'id'])->on('teams')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'user_id'])
                ->references(['tenant_id', 'user_id'])->on('tenant_user')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
    }
};
