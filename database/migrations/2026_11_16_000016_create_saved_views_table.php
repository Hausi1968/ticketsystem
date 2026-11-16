<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gespeicherte Ansichten der Ticketliste je Mitarbeiter (Reiter + Filter + Suche + Sortierung)
        Schema::create('saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('base_tab', 32)->nullable();
            $table->json('filters')->nullable();
            $table->string('search', 255)->nullable();
            $table->string('sort', 100)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
